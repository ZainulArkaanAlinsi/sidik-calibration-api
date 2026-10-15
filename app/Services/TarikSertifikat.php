<?php

namespace App\Services;

use App\Events\PerubahanDataOrganisasi;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TARIK sertifikat terbit kembali ke lembar kerja (K-50-03, 15 Okt 2026).
 *
 * Dipakai bila angka/isi sertifikat yang SUDAH terbit perlu dikoreksi. Beda
 * dari revisi (§38, hanya data administratif, angka disalin) dan pembatalan
 * (final): di sini sesinya kembali ke `menunggu_approval`, Master Data/super
 * admin mengoreksi lembar lewat `PUT` seperti biasa, lalu menyetujui lagi —
 * dan sertifikat baru terbit sebagai `<nomor>-R<n>` (`GenerateCertificate`
 * dengan `revisiDariId`) yang menggantikan yang ditarik.
 *
 * ## Yang dijamin
 *
 * - Satu transaksi: sertifikat → `ditarik` (waktu, pelaku, alasan, additive),
 *   sesi `disetujui` → `menunggu_approval`, jadwal alat disinkronkan. Gagal di
 *   tengah = tidak ada yang berubah.
 * - Input mentah TIDAK disentuh: tidak ada tulis ke `raw_measurements`,
 *   `uncertainty_calculations`, `input_asli`. Dijaga test (hash).
 * - Baris, PDF, dan QR sertifikat lama tetap ada; nomornya tidak dipakai ulang.
 * - Perubahan status tercatat di `audit_logs` (Diaudit, pelaku = yang menarik).
 *
 * Penjaga `CalibrationSession::booted()` (sesi disetujui tak bisa mundur lewat
 * Eloquent) dilonggarkan HANYA di sini, lewat `tanpaPenjagaMundur()`.
 */
class TarikSertifikat
{
    public function __construct(private readonly SinkronJadwalAlat $sinkron) {}

    /**
     * Sertifikat terakhir sesi ini bila statusnya `ditarik` (belum ada
     * penggantinya); null kalau tidak ada yang menunggu terbit ulang.
     */
    public static function yangDitarik(CalibrationSession $sesi): ?Certificate
    {
        $terakhir = Certificate::query()
            ->where('calibration_session_id', $sesi->getKey())
            ->orderByDesc('revisi_ke')
            ->orderByDesc('id')
            ->first();

        return $terakhir?->status === Certificate::STATUS_DITARIK ? $terakhir : null;
    }

    /**
     * @throws ValidationException kalau sertifikat bukan `terbit`, sudah punya revisi, atau sesinya bukan `disetujui`
     */
    public function tarik(Certificate $sertifikat, string $alasan, User $oleh): Certificate
    {
        $hasil = DB::transaction(function () use ($sertifikat, $alasan, $oleh): Certificate {
            /** @var Certificate $baris */
            $baris = Certificate::query()->whereKey($sertifikat->getKey())->lockForUpdate()->firstOrFail();

            if (! $baris->bisaDiubahStatusnya()) {
                throw ValidationException::withMessages([
                    'sertifikat' => match (true) {
                        $baris->status === Certificate::STATUS_DITARIK => 'Sertifikat ini sudah ditarik.',
                        $baris->status === Certificate::STATUS_DIBATALKAN => 'Sertifikat ini sudah dibatalkan.',
                        $baris->status !== Certificate::STATUS_TERBIT => 'Sertifikat ini belum terbit.',
                        default => 'Sertifikat ini sudah punya revisi. Yang ditarik harus revisi terakhirnya.',
                    },
                ]);
            }

            /** @var CalibrationSession $sesi */
            $sesi = CalibrationSession::query()->whereKey($baris->calibration_session_id)->lockForUpdate()->firstOrFail();

            if ($sesi->status !== CalibrationSession::STATUS_DISETUJUI) {
                throw ValidationException::withMessages([
                    'sertifikat' => 'Sesi sertifikat ini tidak berstatus disetujui, jadi tidak bisa ditarik.',
                ]);
            }

            $baris->update([
                'status' => Certificate::STATUS_DITARIK,
                'ditarik_pada' => now(),
                'ditarik_oleh' => $oleh->id,
                'alasan_ditarik' => $alasan,
            ]);

            CalibrationSession::tanpaPenjagaMundur(
                fn () => $sesi->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]),
            );

            // Sertifikat yang ditarik bukan sumber jadwal lagi (jadwal hanya
            // membaca `terbit`). Jalur yang sama dengan pembatalan: boleh
            // mengosongkan kalau tidak ada sertifikat lain yang berlaku.
            $alat = $sesi->equipment?->fresh();

            if ($alat !== null) {
                $this->sinkron->sesudahPembatalan($alat);
            }

            return $baris;
        });

        PerubahanDataOrganisasi::siarkanAman($hasil->organization_id, 'sertifikat', 'ditarik', $hasil->id);
        PerubahanDataOrganisasi::siarkanAman($hasil->organization_id, 'kalibrasi', 'diubah', $hasil->calibration_session_id);

        return $hasil;
    }
}
