<?php

namespace App\Support;

/**
 * Statistik regresi linier yang dipakai sertifikat — sekarang cuma R².
 *
 * Dipisah dari kalkulator alat karena rumusnya nggak punya urusan sama alat
 * mana pun: dia cuma bilang seberapa rapat sepasang deret angka jatuh di satu
 * garis lurus. Yang alat-spesifik itu DERET MANA yang dipasangin, dan itu
 * diputus di profil alatnya.
 *
 * Hasilnya sengaja disamain sama `RSQ()` Excel, karena semua angka pembanding
 * yang dipegang lab lahir dari sana.
 */
class Regresi
{
    /**
     * Minimal titik buat R² yang ada artinya.
     *
     * Dua titik SELALU ngasih R² = 1 — garis lurus lewat dua titik itu selalu
     * ada, seberapa pun ngawurnya alatnya. Angka `1` yang lahir begitu bukan
     * bukti linieritas, dan di sertifikat terakreditasi dia klaim yang nggak
     * pernah diuji.
     */
    public const MIN_TITIK = 3;

    /**
     * Koefisien determinasi (R²) sepasang deret — `RSQ(x, y)` di Excel.
     *
     * `null`, bukan angka, kalau R²-nya nggak punya arti:
     *
     *  - titiknya kurang dari [MIN_TITIK];
     *  - panjang kedua deret beda (data pasangannya nggak utuh);
     *  - salah satu deret nggak punya sebaran sama sekali (semua nilainya sama)
     *    — pembaginya nol, dan Excel pun mbalikin `#DIV/0!` di situ. `NAN` yang
     *    lolos ke snapshot bakal kecetak jadi `0,0000` di PDF, yaitu angka yang
     *    kelihatan sah padahal nggak pernah kehitung.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public static function koefisienDeterminasi(array $x, array $y): ?float
    {
        $n = count($x);

        if ($n !== count($y) || $n < self::MIN_TITIK) {
            return null;
        }

        $rataX = array_sum($x) / $n;
        $rataY = array_sum($y) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $rataX;
            $dy = $y[$i] - $rataY;

            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }

        return ($sxy * $sxy) / ($sxx * $syy);
    }
}
