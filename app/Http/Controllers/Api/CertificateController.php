<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificate;
use App\Mail\SertifikatKePelanggan;
use App\Models\Certificate;
use App\Models\CertificateEmailLog;
use App\Models\User;
use App\Services\CertificateExcelExporter;
use App\Services\QrCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
     * Kirim sertifikat ke email pelanggan (fase-2 §3d). **Admin doang.**
     *
     * Kenapa di backend dan bukan di mobile — dua alasan dari permintaannya, dan
     * dua-duanya dipenuhi di sini:
     *
     * 1. **Alamat pengirim harus domain lab**, bukan Gmail teknisi. Dipenuhi lewat
     *    `MAIL_FROM_ADDRESS`; email lab ditaruh di `Reply-To` (lihat penjelasan di
     *    `SertifikatKePelanggan` — `From` yang domainnya beda dari domain pengirim
     *    bikin SPF/DKIM gagal dan email-nya masuk spam).
     * 2. **Pengirimannya tercatat buat audit** — siapa ngirim ke siapa, kapan.
     *    Semua percobaan dicatat di `certificate_email_logs`, termasuk yang GAGAL:
     *    "kami udah nyoba kirim tapi alamatnya nolak" itu informasi yang justru
     *    dicari waktu pelanggan ngaku nggak nerima.
     *
     * Dikirim SINKRON, bukan lewat queue. Admin yang mencet "Kirim" perlu tahu
     * hasilnya saat itu juga; kiriman yang di-queue lalu gagal diam-diam berarti
     * pelanggan nggak pernah nerima dan nggak ada yang sadar sampai ditanya.
     */
    public function kirimEmail(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $data = $request->validate([
            'ke' => ['required', 'array', 'min:1', 'max:10'],
            'ke.*' => ['required', 'email:rfc'],
            'cc' => ['sometimes', 'nullable', 'array', 'max:10'],
            'cc.*' => ['required', 'email:rfc'],
        ], [
            'ke.required' => 'Alamat tujuannya wajib diisi.',
            'ke.max' => 'Maksimal 10 alamat tujuan sekali kirim.',
            'ke.*.email' => 'Ada alamat email yang formatnya nggak valid.',
        ]);

        // Cuma sertifikat TERBIT yang boleh dikirim. Yang `gagal` atau
        // `menunggu_generate` belum punya PDF — dan email sertifikat tanpa
        // lampirannya lebih buruk daripada nggak ngirim: pelanggan nganggep udah
        // beres, padahal dokumennya nggak ada.
        // Dua sebab, dua pesan. Digabung jadi satu pesan bikin admin bingung:
        // "harus terbit & punya PDF, yang ini statusnya `terbit`" kedengeran kayak
        // backend-nya ngaco, padahal yang kurang PDF-nya.
        if ($certificate->status !== Certificate::STATUS_TERBIT) {
            return response()->json([
                'message' => 'Cuma sertifikat yang udah terbit yang bisa dikirim. '
                    .'Yang ini statusnya `'.$certificate->status.'`.',
            ], 422);
        }

        if (! filled($certificate->pdf_path)) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya berkas PDF, jadi belum ada yang bisa '
                    .'dilampirkan. Coba terbitkan ulang dulu.',
            ], 422);
        }

        abort_unless(
            Storage::disk('local')->exists($certificate->pdf_path),
            422,
            'Berkas PDF sertifikatnya nggak ketemu di penyimpanan. Coba terbitkan ulang dulu.',
        );

        $ke = array_values(array_unique($data['ke']));
        $cc = array_values(array_unique($data['cc'] ?? []));
        $certificate->loadMissing(['organization', 'session.equipment.customer']);

        try {
            Mail::to($ke)->send(new SertifikatKePelanggan($certificate, $cc));
            $status = CertificateEmailLog::STATUS_TERKIRIM;
            $error = null;
        } catch (\Throwable $e) {
            $status = CertificateEmailLog::STATUS_GAGAL;
            $error = $e->getMessage();

            Log::warning('Kirim email sertifikat gagal.', [
                'certificate_id' => $certificate->id,
                'error' => $error,
            ]);
        }

        // Dicatat DULUAN sebelum responsnya dibalik, dan buat dua-dua hasilnya.
        // Percobaan yang gagal tetap kejadian yang perlu bisa dibuktiin.
        $log = CertificateEmailLog::create([
            'organization_id' => $certificate->organization_id,
            'certificate_id' => $certificate->id,
            'ke' => $ke,
            'cc' => $cc === [] ? null : $cc,
            'status' => $status,
            'error' => $error,
            'dikirim_oleh' => $request->user()->id,
        ]);

        if ($status === CertificateEmailLog::STATUS_GAGAL) {
            return response()->json([
                'message' => 'Email gagal dikirim. Percobaannya tetap tercatat di riwayat pengiriman.',
                'data' => $this->barisRiwayat($log),
            ], 502);
        }

        return response()->json([
            'message' => 'Sertifikat terkirim ke '.implode(', ', $ke).'.',
            'data' => $this->barisRiwayat($log),
        ]);
    }

    /**
     * Riwayat pengiriman satu sertifikat — terbaru dulu. **Admin doang.**
     *
     * Ini sisi bacanya dari catatan audit yang diminta fase-2 §3d. Tanpa endpoint
     * ini, catatannya ada di database tapi nggak bisa dilihat siapa pun — dan
     * catatan yang nggak bisa dilihat sama saja dengan nggak nyatet.
     */
    public function riwayatEmail(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $riwayat = CertificateEmailLog::where('certificate_id', $certificate->id)
            ->with('pengirim')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $riwayat->map(fn (CertificateEmailLog $l): array => $this->barisRiwayat($l))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function barisRiwayat(CertificateEmailLog $log): array
    {
        $log->loadMissing('pengirim');

        return [
            'id' => $log->id,
            'ke' => $log->ke,
            'cc' => $log->cc ?? [],
            'status' => $log->status,
            'error' => $log->error,
            'dikirim_oleh' => $log->pengirim ? [
                'id' => $log->pengirim->id,
                'nama' => $log->pengirim->name,
            ] : null,
            'dikirim_pada' => $log->created_at?->toIso8601ZuluString(),
        ];
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
