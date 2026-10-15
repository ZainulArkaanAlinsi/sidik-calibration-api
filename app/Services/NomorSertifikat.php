<?php

namespace App\Services;

use App\Models\Certificate;

/**
 * Aturan satu-satunya untuk NOMOR SERTIFIKAT yang diketik Master Data/super
 * admin saat Setujui (K-50-04, 15 Okt 2026).
 *
 * Nomor itu tercetak di dokumen terakreditasi dan tidak bisa ditarik kembali,
 * jadi aturannya satu pintu — dipakai `CalibrationController::approve()` dan
 * tombol Setujui panel:
 *
 * - wajib, dipangkas spasinya, paling panjang 100 karakter;
 * - karakter aman: huruf, angka, `.` `_` `/` `-` (spasi & karakter lain
 *   ditolak: nomor dipakai di nama berkas, URL, dan QR);
 * - TIDAK boleh berakhiran `-R<n>`: akhiran itu milik revisi (`RevisiSertifikat`)
 *   dan `Certificate::nomorDasar()` memangkasnya — nomor manual berakhiran itu
 *   akan terbaca sebagai revisi dari sertifikat lain;
 * - unik per organisasi di SEMUA status (terbit, gagal, dibatalkan, ditarik):
 *   nomor sertifikat yang pernah dipakai tidak dipakai ulang. 422
 *   `nomor_sertifikat_dipakai` menyebut sertifikat pemiliknya.
 *
 * Penomoran otomatis (`GenerateCertificate::nomorBerikutnya`) tinggal untuk data
 * lama & seeder.
 */
final class NomorSertifikat
{
    public const KODE_WAJIB = 'nomor_sertifikat_wajib';

    public const KODE_TIDAK_VALID = 'nomor_sertifikat_tidak_valid';

    public const KODE_DIPAKAI = 'nomor_sertifikat_dipakai';

    public const MAKS_PANJANG = 100;

    /**
     * @return array{kode: string, pesan: string, sertifikat?: array<string, mixed>}|null null = lolos
     */
    public static function periksa(int $organizationId, mixed $nomor): ?array
    {
        $nomor = is_string($nomor) ? trim($nomor) : '';

        if ($nomor === '') {
            return [
                'kode' => self::KODE_WAJIB,
                'pesan' => 'No. Sertifikat wajib diisi — nomor itu tercetak di sertifikat dan diketik Master Data/super admin saat menyetujui.',
            ];
        }

        if (mb_strlen($nomor) > self::MAKS_PANJANG || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]*$#', $nomor)) {
            return [
                'kode' => self::KODE_TIDAK_VALID,
                'pesan' => 'No. Sertifikat hanya boleh berisi huruf, angka, titik, garis bawah, garis miring, dan tanda hubung (maksimal '
                    .self::MAKS_PANJANG.' karakter, tanpa spasi).',
            ];
        }

        if (preg_match('/-R\d+$/i', $nomor)) {
            return [
                'kode' => self::KODE_TIDAK_VALID,
                'pesan' => 'No. Sertifikat tidak boleh berakhiran -R<angka>: akhiran itu dipakai revisi sertifikat dan dibuat otomatis.',
            ];
        }

        $ada = Certificate::query()
            ->where('organization_id', $organizationId)
            ->where('nomor', $nomor)
            ->with('session:id,nomor_sesi')
            ->first();

        if ($ada !== null) {
            return [
                'kode' => self::KODE_DIPAKAI,
                'pesan' => "No. Sertifikat {$nomor} sudah dipakai sertifikat {$ada->nomor} (status {$ada->status}"
                    .($ada->session?->nomor_sesi ? ", sesi {$ada->session->nomor_sesi}" : '').'). Pakai nomor lain.',
                'sertifikat' => [
                    'id' => $ada->id,
                    'nomor' => $ada->nomor,
                    'status' => $ada->status,
                    'nomor_sesi' => $ada->session?->nomor_sesi,
                ],
            ];
        }

        return null;
    }
}
