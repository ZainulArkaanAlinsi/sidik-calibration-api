<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificate;
use App\Mail\SertifikatKalibrasi;
use App\Models\Certificate;
use App\Models\CertificateEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

        // `nomor` ada slash-nya (CAL/2026/07/0001) — nggak boleh jadi nama file.
        $namaFile = 'Sertifikat-'.str_replace('/', '-', $certificate->nomor).'.pdf';

        return Storage::disk('local')->download($certificate->pdf_path, $namaFile);
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
     * Kirim sertifikat ke pelanggan lewat email. Admin doang (dijaga
     * `role:admin` di routes) — ini tindakan penerbitan, sejalan sama approve.
     *
     * Ditaruh di backend, bukan di HP teknisi, karena dua hal: alamat pengirim
     * harus domain lab (bukan Gmail pribadi), dan tiap pengiriman WAJIB
     * tercatat — asesor nanya "sertifikat ini dikirim ke siapa, kapan, oleh
     * siapa", dan log aplikasi kepangkas berkala jadi nggak bisa diandelin.
     */
    public function kirimEmail(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $data = $request->validate([
            'ke' => ['required', 'array', 'min:1'],
            'ke.*' => ['required', 'email'],
            'cc' => ['sometimes', 'nullable', 'array'],
            'cc.*' => ['required', 'email'],
        ], [
            'ke.required' => 'Alamat email tujuan wajib diisi.',
            'ke.*.email' => 'Ada alamat email tujuan yang formatnya salah.',
        ]);

        // Sertifikat yang PDF-nya belum jadi nggak boleh dikirim — pelanggan
        // bakal nerima email tanpa lampiran, dan itu lebih membingungkan
        // daripada nggak nerima apa-apa.
        if ($certificate->status !== Certificate::STATUS_TERBIT || ! $certificate->pdf_path) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya PDF yang bisa dikirim.',
            ], 422);
        }

        $certificate->loadMissing(['session.equipment.customer', 'organization']);

        Mail::to($data['ke'])
            ->cc($data['cc'] ?? [])
            ->send(new SertifikatKalibrasi($certificate));

        $catatan = CertificateEmail::create([
            'certificate_id' => $certificate->id,
            'dikirim_oleh' => $request->user()->id,
            'penerima' => $data['ke'],
            'cc' => $data['cc'] ?? [],
            'dikirim_pada' => now(),
        ]);

        return response()->json(['data' => [
            'certificate_id' => $certificate->id,
            'nomor' => $certificate->nomor,
            'penerima' => $catatan->penerima,
            'cc' => $catatan->cc,
            'dikirim_pada' => $catatan->dikirim_pada->toIso8601ZuluString(),
        ]]);
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
