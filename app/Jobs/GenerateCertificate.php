<?php

namespace App\Jobs;

use App\Events\PerubahanDataOrganisasi;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SertifikatTerbit;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\FolderOrganizer;
use App\Services\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Terbitin sertifikat dari sesi kalibrasi yang udah disetujui.
 *
 * Jalan di QUEUE (async) — pembuatan PDF bisa lama, jadi jangan nahan request
 * approve. Sesuai kontrak: sehabis approve, `certificate_id` boleh masih null
 * sesaat sampai job ini kelar.
 *
 * FAIL tetap diterbitin — hasil "tidak laik pakai" itu sertifikat yang sah.
 *
 * Urutannya: hitung ulang & periksa (spesifikasi poin 11) → bekukan snapshot
 * (poin 9) → cetak PDF → taruh di Folder Manager (poin 3) → kabarin yang
 * bersangkutan (poin 6). Snapshot dibekukan DULUAN, sebelum PDF, biar kalau
 * cetaknya gagal isinya tetap kesimpen & tinggal di-retry.
 */
class GenerateCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string|null  $berlakuSampai  Masa berlaku pilihan admin (`Y-m-d`).
     *                                      Null → pakai default masa berlaku organisasi.
     */
    public function __construct(
        public int $calibrationSessionId,
        public ?int $issuedBy = null,
        public ?string $berlakuSampai = null,
    ) {}

    public function handle(): void
    {
        // Diambil dari container di dalam sini, bukan lewat parameter handle().
        // Job ini juga dipanggil langsung (`(new GenerateCertificate($id))->handle()`)
        // dari test & aksi panel admin — kalau dependensinya jadi parameter,
        // semua pemanggilan langsung itu ikut pecah.
        $snapshotBuilder = app(CertificateSnapshotBuilder::class);
        $validator = app(CalibrationValidator::class);
        $qr = app(QrCodeGenerator::class);
        $folder = app(FolderOrganizer::class);

        $sesi = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard', 'thermohygro',
            'standarDicek', 'calibrationMethod', 'room', 'rawMeasurements', 'uncertaintyCalculations.standard',
        ])->find($this->calibrationSessionId);

        if (! $sesi || $sesi->status !== CalibrationSession::STATUS_DISETUJUI) {
            return;
        }

        // Idempoten: kalau udah pernah terbit, jangan bikin dobel (job bisa
        // ke-retry, atau admin approve dua kali).
        if ($sesi->certificate()->where('status', Certificate::STATUS_TERBIT)->exists()) {
            return;
        }

        $sertifikat = DB::transaction(function () use ($sesi): Certificate {
            $nomor = $this->nomorBerikutnya($sesi->organization_id);
            $token = $this->tokenUnik();

            return $sesi->certificate()->updateOrCreate(
                ['calibration_session_id' => $sesi->id],
                [
                    'organization_id' => $sesi->organization_id,
                    'issued_by' => $this->issuedBy,
                    'nomor' => $nomor,
                    'qr_token' => $token,
                    'qr_payload' => url("/verify/{$token}"),
                    'diterbitkan_pada' => now(),
                    'berlaku_sampai' => $this->berlakuSampai($sesi),
                    'status' => Certificate::STATUS_MENUNGGU_GENERATE,
                ],
            );
        });

        try {
            // Hasil pemeriksaan disimpan APA ADANYA, termasuk kalau ada temuan.
            // Approve udah nahan yang fatal; yang nyampe sini paling banter
            // peringatan yang sengaja dilewatin admin — dan justru itu yang
            // paling penting ketinggalan jejaknya.
            $sertifikat->update([
                'validasi' => $validator->periksa($sesi),
                'snapshot' => $snapshotBuilder->bangun($sesi, $sertifikat),
            ]);

            $pdf = Pdf::loadView('sertifikat.pdf', [
                'sertifikat' => $sertifikat,
                'snapshot' => $sertifikat->snapshot,
                // Embed sebagai data URI — paling aman buat dompdf (nggak
                // gantung ke path/symlink). null kalau logonya nggak ketemu.
                'logo' => $this->logoDataUri($sesi->organization),
                'qr' => $this->qrDataUri($qr, $sesi->organization, $sertifikat),
                'keputusan' => $this->tampilkanKeputusan($sesi->organization) ? $sesi->keputusan : null,
            ]);

            $path = "certificates/{$sertifikat->qr_token}.pdf";
            Storage::disk('local')->put($path, $pdf->output());

            $sertifikat->update([
                'pdf_path' => $path,
                'status' => Certificate::STATUS_TERBIT,
            ]);
        } catch (\Throwable $e) {
            // Status `gagal` bikin tombol retry muncul di mobile, bukan diem-diem
            // ngilang. Sesi tetap `disetujui`.
            $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

            throw $e;
        }

        // Dua langkah di bawah ini pelengkap, bukan syarat sahnya sertifikat.
        // Kalau salah satunya meledak, sertifikatnya udah terbit & PDF-nya udah
        // ada — jangan sampai job-nya di-retry cuma gara-gara ini, karena retry
        // bakal ngulang dari nol dan bikin nomor baru.
        try {
            $folder->tautkanSertifikat($sertifikat->fresh()->load('session.equipment.customer'));
            $this->kabarin($sertifikat);
            // Sinyal realtime: sertifikat baru terbit → HP & desktop refresh barengan.
            PerubahanDataOrganisasi::dispatch(
                $sertifikat->organization_id, 'sertifikat', 'diterbitkan', $sertifikat->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Sertifikat terbit, tapi penautan folder/notifikasi gagal.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Kabarin teknisi yang ngerjain + admin yang nerbitin. */
    private function kabarin(Certificate $sertifikat): void
    {
        $sertifikat->loadMissing('session.equipment', 'session.teknisi');

        $penerima = User::query()
            ->whereIn('id', array_filter([$sertifikat->session?->teknisi_id, $sertifikat->issued_by]))
            ->where('status', User::STATUS_AKTIF)
            ->get();

        foreach ($penerima as $user) {
            $user->notify(SertifikatTerbit::dariSertifikat($sertifikat));
        }
    }

    /**
     * QR verifikasi buat dicetak di sertifikat. Bisa dimatiin lewat pengaturan
     * organisasi (`tampilkan_qr_di_pdf`) buat lab yang layout sertifikatnya
     * dikunci ketat sama dokumen mutu.
     */
    private function qrDataUri(QrCodeGenerator $qr, ?Organization $organization, Certificate $sertifikat): ?string
    {
        if (($organization?->settings['tampilkan_qr_di_pdf'] ?? true) === false) {
            return null;
        }

        try {
            return $qr->dataUri((string) $sertifikat->qr_payload, skala: 4);
        } catch (\Throwable $e) {
            // QR gagal dibikin (mis. ekstensi GD nggak aktif di server) nggak
            // boleh nahan penerbitan — sertifikatnya masih sah tanpa QR.
            Log::warning('QR sertifikat gagal dibuat.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Struktur baku sertifikat pH nggak punya baris keputusan PASS/FAIL, jadi
     * defaultnya NGGAK dicetak. Tapi buat sertifikat alat lain (yang formatnya
     * nggak dikunci pelanggan), keputusan itu informasi paling penting di
     * dokumennya — makanya disediain sakelarnya, bukan dihapus permanen.
     */
    private function tampilkanKeputusan(?Organization $organization): bool
    {
        return (bool) ($organization?->settings['tampilkan_keputusan_di_pdf'] ?? false);
    }

    /**
     * Logo lab sebagai data URI buat kop sertifikat. Prioritas logo milik
     * organisasi (`logo_path` di disk publik, diupload admin lewat panel);
     * kalau kosong/nggak ada, pakai logo bawaan di public/images. null kalau
     * dua-duanya nggak ada — kop-nya balik ke teks doang, PDF tetap kebuat.
     */
    private function logoDataUri(?Organization $organization): ?string
    {
        $path = null;

        if ($organization?->logo_path && Storage::disk('public')->exists($organization->logo_path)) {
            $path = Storage::disk('public')->path($organization->logo_path);
        } elseif (is_file(public_path('images/logo-sidik.png'))) {
            $path = public_path('images/logo-sidik.png');
        }

        if ($path === null) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Masa berlaku sertifikat: pilihan admin dulu, baru default organisasi.
     *
     * Interval kalibrasi itu keputusan teknis — tergantung jenis alat, seberapa
     * sering dipakai, dan permintaan pelanggan — jadi nggak bisa dipaksa satu
     * angka buat semua. Yang dipatok backend cuma batasnya (lihat validasi di
     * `CalibrationController::approve()`); angkanya milik admin.
     *
     * Dihitung dari TANGGAL KALIBRASI, bukan tanggal terbit. Sertifikat bisa
     * terbit beberapa hari sesudah alat dikerjain (di contoh Tirta Gracia:
     * kalibrasi 26 Mei, terbit 30 Mei) — kalau dihitung dari tanggal terbit,
     * masa berlakunya diam-diam kepanjangan, dan alat lewat jatuh tempo tanpa
     * ada yang sadar.
     */
    private function berlakuSampai(CalibrationSession $sesi): CarbonInterface
    {
        if ($this->berlakuSampai !== null) {
            return Carbon::parse($this->berlakuSampai)->startOfDay();
        }

        $dasar = $sesi->tanggal_kalibrasi ?? now();

        return $dasar->copy()->addMonthsNoOverflow(
            $sesi->organization?->masaBerlakuBulan() ?? Organization::DEFAULT_MASA_BERLAKU_BULAN,
        )->startOfDay();
    }

    /** Nomor sertifikat urut per organisasi per bulan: CAL/2026/07/0001. */
    private function nomorBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('CAL/%s/', now()->format('Y/m'));

        $terakhir = Certificate::where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    private function tokenUnik(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (Certificate::where('qr_token', $token)->exists());

        return $token;
    }
}
