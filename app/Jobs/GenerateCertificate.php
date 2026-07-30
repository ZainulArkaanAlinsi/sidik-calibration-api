<?php

namespace App\Jobs;

use App\Events\PerubahanDataOrganisasi;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SertifikatGagal;
use App\Notifications\SertifikatTerbit;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\FolderOrganizer;
use App\Services\PenerimaNotifikasi;
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
        $tampilan = app(DataTampilanSertifikat::class);
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

            // Bahannya dirakit `DataTampilanSertifikat`, bukan di sini — halaman
            // hasil scan QR merender blade yang SAMA dengan bahan yang sama,
            // jadi lembar yang dipegang pelanggan nggak bisa beda dari lembar
            // yang muncul waktu QR-nya discan.
            $pdf = Pdf::loadView('sertifikat.pdf', $tampilan->untuk($sertifikat));

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

            // Kabarin admin (fase-2 §2). Ini kegagalan paling sunyi di sistem:
            // sesinya udah disetujui, teknisi udah dikabarin "disetujui", tapi
            // PDF-nya nggak pernah jadi. Sebelum ini cuma keliatan kalau ada yang
            // kebetulan buka layar sertifikatnya — sementara pelanggan nungguin
            // dokumen yang dari sisi lab kelihatan udah selesai.
            $this->kabarinKegagalan($sertifikat, $e);

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
     * Kabarin admin kalau pembuatan sertifikat gagal.
     *
     * Dibungkus `try` sendiri: kalau ngirim notifikasinya juga meledak, yang harus
     * kelihatan di log tetap error ASLINYA (yang bikin sertifikatnya gagal), bukan
     * error notifikasinya. Salah nutupin di sini bikin penyebab sebenernya ilang.
     */
    private function kabarinKegagalan(Certificate $sertifikat, \Throwable $penyebab): void
    {
        try {
            $sertifikat->loadMissing('session');
            $notifikasi = SertifikatGagal::dariSertifikat($sertifikat, $penyebab->getMessage());

            foreach (app(PenerimaNotifikasi::class)->adminAktif($sertifikat->organization_id) as $admin) {
                $admin->notify($notifikasi);
            }
        } catch (\Throwable $e) {
            Log::warning('Sertifikat gagal, dan ngabarin admin soal itu juga gagal.', [
                'certificate_id' => $sertifikat->id,
                'penyebab_asli' => $penyebab->getMessage(),
                'error_notifikasi' => $e->getMessage(),
            ]);
        }
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
