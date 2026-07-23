<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificate;
use App\Models\Certificate;
use App\Models\User;
use App\Services\CertificateExcelExporter;
use App\Services\QrCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sertifikat yang udah terbit dari sesi kalibrasi yang disetujui.
 *
 * PDF-nya disimpen di disk `local` (privat) — nggak bisa diakses langsung lewat
 * URL storage. Satu-satunya jalan ngambilnya buat orang dalam ya lewat sini,
 * dengan auth + scope organisasi. (Yang publik cuma verifikasi QR, bukan PDF-nya.)
 */
class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateExcelExporter $excel,
        private readonly QrCodeGenerator $qr,
    ) {}

    /** Relasi yang dibutuhin CertificateResource. */
    private const RELASI = ['session.equipment'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $sertifikat = Certificate::query()
            ->with(self::RELASI)
            ->where('organization_id', $user->organization_id)
            // Teknisi SELALU cuma lihat sertifikat dari sesi miliknya sendiri —
            // sama polanya kayak di daftar sesi kalibrasi. `mine` cuma berpengaruh
            // buat admin/viewer.
            ->when(
                $user->role === User::ROLE_TEKNISI || $request->boolean('mine'),
                fn ($query) => $query->whereHas('session', fn ($q) => $q->where('teknisi_id', $user->id)),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return CertificateResource::collection($sertifikat);
    }

    /**
     * Isi sertifikat lengkap — dari snapshot yang dibekukan waktu terbit.
     * Dipakai layar pratinjau sertifikat sebelum diunduh/dikirim.
     */
    public function show(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanBolehLihat($request, $certificate);

        return response()->json([
            'data' => [
                ...(new CertificateResource($certificate->load(self::RELASI)))->toArray($request),
                'snapshot' => $certificate->snapshot,
                // Hasil pemeriksaan hitung ulang waktu terbit (spesifikasi poin
                // 11) — biar admin bisa lihat sertifikat mana yang dulu terbit
                // dengan peringatan.
                'validasi' => $certificate->validasi,
            ],
        ]);
    }

    /** Kirim file PDF-nya. Streamed — file bisa gede, jangan ditahan di memori. */
    public function download(Request $request, Certificate $certificate): StreamedResponse
    {
        $this->pastikanBolehLihat($request, $certificate);

        abort_unless(
            $certificate->status === Certificate::STATUS_TERBIT && $certificate->pdf_path,
            404,
            'Sertifikat ini belum punya PDF yang bisa diunduh.',
        );

        // Baris di DB bilang terbit tapi file-nya raib (kehapus manual, dsb).
        abort_unless(Storage::disk('local')->exists($certificate->pdf_path), 404);

        return Storage::disk('local')->download($certificate->pdf_path, $certificate->namaFile('pdf'));
    }

    /**
     * Export satu sertifikat ke Excel (spesifikasi poin 10).
     *
     * File-nya dibikin ON DEMAND, bukan disimpan waktu terbit: isinya turunan
     * dari snapshot yang udah beku, jadi hasilnya selalu sama — nyimpen file
     * kedua cuma nambah barang yang bisa basi & nggak sinkron.
     */
    public function exportExcel(Request $request, Certificate $certificate): BinaryFileResponse
    {
        $this->pastikanBolehLihat($request, $certificate);

        abort_unless(
            $certificate->status === Certificate::STATUS_TERBIT && filled($certificate->snapshot),
            404,
            'Sertifikat ini belum terbit, jadi belum ada isinya buat diexport.',
        );

        $tmp = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
        $this->excel->satu($certificate, $tmp);

        return response()->download($tmp, $certificate->namaFile('xlsx'))->deleteFileAfterSend();
    }

    /**
     * Rekap banyak sertifikat sekaligus — bulanan atau per PT (spesifikasi
     * poin 10). Admin doang: isinya lintas pelanggan.
     */
    public function exportRekap(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'bulan' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'dari' => ['sometimes', 'nullable', 'date'],
            'sampai' => ['sometimes', 'nullable', 'date', 'after_or_equal:dari'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $query = Certificate::query()
            ->with(['session.equipment.customer'])
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->when(
                isset($data['bulan']),
                fn ($q) => $q->whereYear('diterbitkan_pada', substr($data['bulan'], 0, 4))
                    ->whereMonth('diterbitkan_pada', substr($data['bulan'], 5, 2)),
            )
            ->when(isset($data['dari']), fn ($q) => $q->whereDate('diterbitkan_pada', '>=', $data['dari']))
            ->when(isset($data['sampai']), fn ($q) => $q->whereDate('diterbitkan_pada', '<=', $data['sampai']))
            ->when(
                isset($data['customer_id']),
                fn ($q) => $q->whereHas(
                    'session.equipment',
                    fn ($e) => $e->where('customer_id', $data['customer_id']),
                ),
            )
            ->orderBy('diterbitkan_pada')
            ->orderBy('nomor');

        $sertifikat = $query->get();

        abort_if($sertifikat->isEmpty(), 404, 'Nggak ada sertifikat yang cocok sama penyaring itu.');

        $tmp = tempnam(sys_get_temp_dir(), 'rekap-sertifikat-').'.xlsx';
        $this->excel->rekap($sertifikat, $tmp);

        $namaFile = 'Rekap-Sertifikat-'.($data['bulan'] ?? now()->format('Y-m-d')).'.xlsx';

        return response()->download($tmp, $namaFile)->deleteFileAfterSend();
    }

    /**
     * Gambar QR sertifikat sebagai PNG (spesifikasi poin 13). Dipakai mobile &
     * panel buat nampilin/ngeprint ulang QR-nya di luar PDF.
     */
    public function qr(Request $request, Certificate $certificate): Response
    {
        $this->pastikanBolehLihat($request, $certificate);

        $png = $this->qr->png((string) ($certificate->qr_payload ?? url("/verify/{$certificate->qr_token}")));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // Isinya cuma turunan dari qr_token yang nggak pernah berubah —
            // aman di-cache lama di sisi klien.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Coba terbitin ulang sertifikat yang generate-nya gagal. Admin doang
     * (dijaga `role:admin` di routes) — ini tindakan penerbitan, sejalan sama approve.
     *
     * Job-nya idempoten & pakai updateOrCreate di baris sesi yang sama, jadi aman
     * dipanggil ulang. Status dibalik ke `menunggu_generate` biar mobile langsung
     * nunjukin "lagi diproses", bukan nawarin retry lagi selagi job jalan.
     */
    public function retry(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        if ($certificate->status !== Certificate::STATUS_GAGAL) {
            return response()->json([
                'message' => 'Cuma sertifikat yang statusnya `gagal` yang bisa dicoba terbitin ulang.',
            ], 422);
        }

        $certificate->update(['status' => Certificate::STATUS_MENUNGGU_GENERATE]);
        GenerateCertificate::dispatch($certificate->calibration_session_id, $request->user()->id);

        return response()->json([
            'data' => new CertificateResource($certificate->fresh()->load(self::RELASI)),
        ]);
    }

    /**
     * Teknisi cuma boleh megang sertifikat dari sesi miliknya sendiri. Tanpa ini,
     * daftar udah difilter tapi download per-ID masih bocor — tinggal tebak ID.
     */
    private function pastikanBolehLihat(Request $request, Certificate $certificate): void
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $user = $request->user();

        abort_if(
            $user->role === User::ROLE_TEKNISI && $certificate->session?->teknisi_id !== $user->id,
            404,
        );
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh baca sertifikat kita. */
    private function pastikanSatuOrganisasi(Request $request, Certificate $certificate): void
    {
        abort_if($certificate->organization_id !== $request->user()->organization_id, 404);
    }
}
