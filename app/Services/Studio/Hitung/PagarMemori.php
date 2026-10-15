<?php

namespace App\Services\Studio\Hitung;

/**
 * Pagar memori LUNAK mesin hitung Studio (tinjau C-3 #1).
 *
 * `memory_limit` request `/hitung` (`studio.hitung.memori`) adalah pagar KERAS:
 * melewatinya = PHP Fatal (500) untuk satu request — jauh lebih baik daripada
 * container Render 512 MB yang di-OOM-kill, tetapi tetap bukan jawaban bersih.
 * Pagar ini memeriksa pemakaian memori di titik-titik panas evaluasi dan
 * melempar `GagalHitung::terlaluBerat()` (422 `hitung_terlalu_berat`) begitu
 * melewati `studio.hitung.ambang_memori` × `memory_limit` (bawaan 0,8):
 *
 *  - setiap kali sel/rentang dibaca (`KalkulasiBerpagar::extractCellRange`,
 *    sebelum DAN sesudah rentang dirinci);
 *  - sebelum tiap sel kotor dihitung (`MesinHitung`);
 *  - per UNSUR larik di pembungkus fungsi teks (`BatasFungsiExcel`: REPT,
 *    SUBSTITUTE, TEXT, FIXED, DOLLAR) — `REPT("a",32767+0*ROW(A1:A10000))`
 *    tumbuh 32 KB per unsur dan terputus di tengah.
 *
 * Yang TIDAK bisa diputus di sini: operator `&` dan siaran larik (`+ - * / ^`)
 * dijalankan PhpSpreadsheet di dalam loop privat
 * `Calculation::processTokenStack` (5.10: baris 1984–2033 dan
 * `executeNumericBinaryOperation`) tanpa titik kait. Untuk rumus EDIT, bentuk
 * itu dicegah sebelum mesin jalan (`PagarRumus`: teks × larik → 422
 * `rumus_terlalu_berat`); sisanya dijaga `memory_limit`.
 *
 * Dipasang/dilepas `MesinHitung::hitung` (ambang dibaca sekali per hitung).
 * `memory_limit` -1 = tanpa pagar.
 */
final class PagarMemori
{
    private static int $ambang = PHP_INT_MAX;

    private function __construct() {}

    public static function pasang(): void
    {
        $batas = self::byte((string) ini_get('memory_limit'));
        $rasio = (float) config('studio.hitung.ambang_memori', 0.8);

        self::$ambang = $batas <= 0 || $rasio <= 0.0 ? PHP_INT_MAX : (int) floor($batas * min($rasio, 1.0));
    }

    public static function lepas(): void
    {
        self::$ambang = PHP_INT_MAX;
    }

    /** Ambang aktif dalam byte (`PHP_INT_MAX` = tanpa pagar). */
    public static function ambang(): int
    {
        return self::$ambang;
    }

    /**
     * Dibandingkan dengan `memory_get_usage(true)` — ukuran yang sama dengan
     * yang ditegakkan `memory_limit` (blok yang dipesan dari sistem).
     *
     * @throws GagalHitung
     */
    public static function periksa(): void
    {
        if (memory_get_usage(true) > self::$ambang) {
            throw GagalHitung::terlaluBerat();
        }
    }

    /** "384M" / "1G" / "524288K" / "-1" → byte (-1 bila tanpa batas atau tak terbaca). */
    public static function byte(string $nilai): int
    {
        $nilai = trim($nilai);

        if ($nilai === '' || $nilai === '-1') {
            return -1;
        }

        $angka = (int) $nilai;

        return match (strtoupper(substr($nilai, -1))) {
            'G' => $angka * 1024 ** 3,
            'M' => $angka * 1024 ** 2,
            'K' => $angka * 1024,
            default => $angka,
        };
    }
}
