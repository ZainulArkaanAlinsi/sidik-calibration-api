<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

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

    /**
     * PESAN nomornya di dalam transaksi persetujuan — memanggil ini WAJIB di
     * dalam `DB::transaction` yang juga mengubah status sesi.
     *
     * `periksa()` saja cek-lalu-tulis: dua Setujui bersamaan (sesi berbeda,
     * nomor sama) sama-sama lolos, dan yang kalah baru ketahuan di job
     * penerbitan — saat sesinya sudah `disetujui`. Menyisipkan baris sertifikat
     * `gagal` yang memegang nomor itu membuat unique index
     * `certificates(organization_id, nomor)` menembak SEKARANG, sebelum
     * persetujuan di-commit. `GenerateCertificate::handle()` memakai ulang
     * baris ini (nomor & token dipertahankan), dan kalau antrean macet baris
     * itulah yang memunculkan tombol "Terbitkan ulang".
     *
     * @return array{kode: string, pesan: string, sertifikat?: array<string, mixed>}|null null = nomor terpesan
     */
    public static function pesan(CalibrationSession $sesi, ?int $issuedBy, ?string $berlakuSampai, string $nomor): ?array
    {
        $galat = self::periksa($sesi->organization_id, $nomor);

        if ($galat !== null) {
            return $galat;
        }

        do {
            $token = Str::lower(Str::random(10));
        } while (Certificate::query()->where('qr_token', $token)->exists());

        try {
            $sesi->certificate()->create([
                'organization_id' => $sesi->organization_id,
                'issued_by' => $issuedBy,
                'nomor' => $nomor,
                'qr_token' => $token,
                'qr_payload' => rtrim((string) config('app.url'), '/')."/verify/{$token}",
                'berlaku_sampai' => $berlakuSampai,
                'status' => Certificate::STATUS_GAGAL,
            ]);
        } catch (UniqueConstraintViolationException) {
            return [
                'kode' => self::KODE_DIPAKAI,
                'pesan' => "No. Sertifikat {$nomor} baru saja dipakai sertifikat lain. Pakai nomor lain.",
            ];
        }

        return null;
    }
}
