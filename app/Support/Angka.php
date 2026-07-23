<?php

namespace App\Support;

/**
 * Format angka buat dokumen resmi (sertifikat PDF & Excel).
 *
 * Sertifikatnya dipakai di Indonesia: pemisah desimalnya KOMA (`4,01`), bukan
 * titik. Ini bukan selera — angka `4.01` di dokumen berbahasa Indonesia kebaca
 * "empat ribu sepuluh" sama sebagian orang. Snapshot sertifikat nyimpen angka
 * mentah (float), pemformatannya baru di sini biar satu aturan dipakai semua
 * tampilan.
 */
class Angka
{
    /** Kalau alatnya nggak punya resolusi, jatuh ke sini. */
    public const DESIMAL_DEFAULT = 4;

    /**
     * Jumlah desimal yang wajar buat alat dengan resolusi segini.
     *
     * Resolusi 0,01 → 2 desimal; 0,001 → 3. Nulis lebih banyak desimal daripada
     * yang bisa dibaca alatnya itu ngaku-ngaku presisi yang nggak ada.
     */
    public static function desimalDariResolusi(?float $resolusi): int
    {
        if ($resolusi === null || $resolusi <= 0) {
            return self::DESIMAL_DEFAULT;
        }

        // log10(0.01) = -2 → 2 desimal. Dibatesin 0..6 biar resolusi aneh
        // (0 sekian belas nol) nggak bikin kolom sertifikat kepanjangan.
        $desimal = (int) max(0, ceil(-log10($resolusi)));

        return min($desimal, 6);
    }

    /** `4.005` → `4,01`. null → tanda pisah, bukan `0`. */
    public static function id(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        return number_format($nilai, $desimal, ',', '.');
    }

    /**
     * Sama kayak `id()`, tapi nol di belakang koma dibuang: `4,0100` → `4,01`.
     * Dipakai buat nilai yang jumlah desimalnya beda-beda (mis. kapasitas alat).
     */
    public static function idRingkas(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        $teks = number_format($nilai, $desimal, ',', '.');

        return str_contains($teks, ',') ? rtrim(rtrim($teks, '0'), ',') : $teks;
    }
}
