<?php

namespace App\Services;

use App\Models\Equipment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat yang tahu kenapa sebuah nomor seri ditolak.
 *
 * Indeks DB `(organization_id, serial_number)` tidak mengabaikan baris soft
 * delete, jadi alat yang sudah DIHAPUS tetap memegang nomor serinya. Dulu
 * validasi mengabaikan baris terhapus (`whereNull('deleted_at')`): nomor seri
 * itu lolos validasi, lalu INSERT-nya gagal di indeks dan jadi 500.
 *
 * Aturannya sekarang:
 *  - dipakai alat yang masih hidup  -> 422 `Nomor seri sudah dipakai alat lain.`
 *  - dipakai alat yang sudah dihapus -> 422 dengan pesan sendiri. Baris lamanya
 *    TIDAK dipulihkan otomatis: itu memunculkan kembali alat beserta riwayat
 *    kalibrasinya tanpa ada yang meminta.
 *  - balapan (dua request lolos validasi bersamaan, indeks yang memutuskan) ->
 *    `UniqueConstraintViolationException` diterjemahkan ke 422 yang SAMA lewat
 *    [terjemahkan], bukan 500.
 *
 * Unik per LABORATORIUM (`organization_id`), bukan per PT pelanggan dan bukan
 * global. Keputusan pemilik: tidak diubah.
 */
final class KonflikSerialAlat
{
    public const PESAN_DIPAKAI_ALAT_LAIN = 'Nomor seri sudah dipakai alat lain.';

    public const PESAN_DIPAKAI_ALAT_DIHAPUS = 'Nomor seri ini masih tercatat pada alat yang sudah dihapus, '
        .'dan nomor seri harus unik di satu laboratorium. Pakai nomor seri lain, atau minta admin '
        .'memulihkan alat lamanya. Alat yang dihapus tidak dipulihkan otomatis.';

    /**
     * Pesan bila nomor seri dipegang alat yang sudah dihapus, atau null kalau
     * tidak ada baris terhapus yang memegangnya.
     */
    public static function pesanBilaDipegangAlatDihapus(int $organisasiId, string $serial, ?int $kecualiId = null): ?string
    {
        $dipegang = Equipment::onlyTrashed()
            ->where('organization_id', $organisasiId)
            ->where('serial_number', $serial)
            ->when($kecualiId !== null, fn ($q) => $q->whereKeyNot($kecualiId))
            ->exists();

        return $dipegang ? self::PESAN_DIPAKAI_ALAT_DIHAPUS : null;
    }

    /**
     * Ubah galat indeks unik (balapan) menjadi 422 yang sama dengan validasi.
     * Galat unik yang BUKAN soal nomor seri dilempar ulang apa adanya.
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException
     */
    public static function terjemahkan(
        UniqueConstraintViolationException $galat,
        int $organisasiId,
        ?string $serial,
        ?int $kecualiId = null,
    ): never {
        if ($serial === null || ! str_contains($galat->getMessage(), 'serial_number')) {
            throw $galat;
        }

        $pesan = self::pesanBilaDipegangAlatDihapus($organisasiId, $serial, $kecualiId)
            ?? self::PESAN_DIPAKAI_ALAT_LAIN;

        throw ValidationException::withMessages(['serial_number' => [$pesan]]);
    }
}
