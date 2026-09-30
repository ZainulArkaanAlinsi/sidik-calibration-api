<?php

namespace App\Jobs;

use App\Events\PerubahanDataOrganisasi;
use App\Models\Certificate;
use App\Notifications\Pelanggan\SertifikatBerubah;
use App\Notifications\SertifikatGagal;
use App\Services\FolderOrganizer;
use App\Services\Pelanggan\PreferensiNotifikasi;
use App\Services\PenerimaNotifikasi;
use App\Services\SertifikatSatuHalaman;
use App\Services\SinkronJadwalAlat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Render PDF sertifikat REVISI yang barisnya sudah dibuat `RevisiSertifikat`.
 *
 * Sengaja bukan perluasan `GenerateCertificate`: job itu `updateOrCreate` per
 * SESI, jadi dia akan menimpa baris sertifikat asli dengan nomor & token
 * revisi (§38.3). Di sini barisnya sudah lengkap — nomor, token, snapshot —
 * dan yang tersisa cuma mencetak lalu menyatakannya terbit.
 *
 * Tidak ada yang dihitung ulang dan tidak ada nomor yang dialokasikan, jadi
 * mengulang job ini aman: hasilnya PDF yang sama di jalur yang sama.
 */
class ReviseCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Sama dengan `GenerateCertificate` — render dompdf di worker kecil bisa lama. */
    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public int $certificateId) {}

    public function handle(): void
    {
        $sertifikat = Certificate::with(['organization', 'session.equipment.customer'])->find($this->certificateId);

        if ($sertifikat === null
            || $sertifikat->revision_of === null
            || ! in_array($sertifikat->status, [Certificate::STATUS_MENUNGGU_GENERATE, Certificate::STATUS_GAGAL], true)) {
            return;
        }

        try {
            $isi = app(SertifikatSatuHalaman::class)->isi($sertifikat);
            $path = "certificates/{$sertifikat->qr_token}.pdf";

            // Nilai balik `put()` diperiksa — disk di project ini `throw => false`.
            // Alasan lengkapnya di GenerateCertificate.
            if (Storage::disk('arsip')->put($path, $isi) === false) {
                throw new RuntimeException("Gagal nulis PDF revisi ke {$path}.");
            }

            // Terbit + jadwal alat dalam satu transaksi, sama seperti
            // GenerateCertificate: revisi yang mengubah masa berlaku tidak boleh
            // terbit sementara pengingat pagi masih membaca tanggal lama.
            DB::transaction(function () use ($sertifikat, $path): void {
                $sertifikat->update(['pdf_path' => $path, 'status' => Certificate::STATUS_TERBIT]);

                $alat = $sertifikat->session?->equipment?->fresh();

                if ($alat !== null) {
                    app(SinkronJadwalAlat::class)->untuk($alat);
                }
            });
        } catch (\Throwable $e) {
            $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);
            $this->kabarinKegagalan($sertifikat, $e);

            throw $e;
        }

        // Pelengkap — gagal di sini tidak membatalkan dokumen yang sudah terbit.
        try {
            app(FolderOrganizer::class)->tautkanSertifikat($sertifikat->fresh()->load('session.equipment.customer'));
            $this->kabarinPelanggan($sertifikat);
            PerubahanDataOrganisasi::siarkanAman($sertifikat->organization_id, 'sertifikat', 'diterbitkan', $sertifikat->id);
        } catch (\Throwable $e) {
            Log::warning('Revisi sertifikat terbit, tapi penautan folder/notifikasi gagal.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $sertifikat = Certificate::query()
            ->whereKey($this->certificateId)
            ->where('status', Certificate::STATUS_MENUNGGU_GENERATE)
            ->first();

        if ($sertifikat === null) {
            return;
        }

        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);
        $this->kabarinKegagalan($sertifikat, $exception ?? new RuntimeException('Render revisi berhenti tanpa pesan galat.'));
    }

    /**
     * Anggota aktif perusahaan pemilik alat diberi tahu — dokumen yang mereka
     * pegang sudah tidak berlaku, dan itu bukan kabar yang boleh dimatikan
     * lewat saklar preferensi (ISO/IEC 17025 §7.8.8).
     */
    private function kabarinPelanggan(Certificate $sertifikat): void
    {
        $pelanggan = $sertifikat->session?->equipment?->customer;

        if ($pelanggan === null) {
            return;
        }

        $penerima = app(PreferensiNotifikasi::class)->anggotaAktif($pelanggan);

        if ($penerima->isNotEmpty()) {
            Notification::send($penerima, SertifikatBerubah::direvisi($sertifikat));
        }
    }

    private function kabarinKegagalan(Certificate $sertifikat, \Throwable $penyebab): void
    {
        try {
            $sertifikat->loadMissing('session');
            $notifikasi = SertifikatGagal::dariSertifikat($sertifikat, $penyebab->getMessage());

            foreach (app(PenerimaNotifikasi::class)->adminAktif($sertifikat->organization_id) as $admin) {
                $admin->notify($notifikasi);
            }
        } catch (\Throwable $e) {
            Log::warning('Revisi sertifikat gagal, dan ngabarin admin soal itu juga gagal.', [
                'certificate_id' => $sertifikat->id,
                'penyebab_asli' => $penyebab->getMessage(),
                'error_notifikasi' => $e->getMessage(),
            ]);
        }
    }
}
