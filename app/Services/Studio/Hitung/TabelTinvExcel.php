<?php

namespace App\Services\Studio\Hitung;

/**
 * Tabel acuan TINV(p, df) HASIL EXCEL untuk df bulat 1..N (Studio tahap C).
 *
 * Kenapa tabel, bukan rumus: TINV/T.INV.2T Excel tidak dibulatkan-benar —
 * dibanding kuantil presisi 60 digit selisihnya sampai 11 ULP (p = 0,05,
 * df 1..80), dan BETA.INV yang dipakainya sendiri meleset sampai 3 ULP. Tanpa
 * algoritma internal Excel, satu-satunya cara bit-identik adalah nilai Excel
 * sendiri. TINV memotong df ke bilangan bulat, jadi domainnya terhitung.
 *
 * Berkas `resources/studio/tinv-excel/p<p>.bin` = double little-endian berurutan
 * untuk df = 1..N, dibangkitkan `docs/skrip/gen-tabel-tinv-excel.ps1` (Excel
 * COM) — JANGAN disunting tangan. Bukti & angka: `excel-audit.md` tahap C.
 */
final class TabelTinvExcel
{
    /** @var array<string, list<float>|false> p (G17) → nilai per df (indeks 0 = df 1) */
    private static array $muat = [];

    private function __construct() {}

    /** Nilai Excel, atau null bila (p, df) di luar tabel yang tersedia. */
    public static function nilai(float $p, int $df): ?float
    {
        $kunci = self::kunci($p);

        if (! array_key_exists($kunci, self::$muat)) {
            self::$muat[$kunci] = self::baca($kunci);
        }

        $tabel = self::$muat[$kunci];

        if ($tabel === false || $df < 1 || $df > count($tabel)) {
            return null;
        }

        return $tabel[$df - 1];
    }

    /** Batas df tabel untuk p ini (0 = tidak ada tabel). */
    public static function dfMaks(float $p): int
    {
        self::nilai($p, 1);
        $tabel = self::$muat[self::kunci($p)];

        return $tabel === false ? 0 : count($tabel);
    }

    private static function kunci(float $p): string
    {
        // Representasi round-trip TERPENDEK, sama dengan nama berkas generator
        // ([double]::ToString('R')): 0.05 → "0.05". Bukan `(string) $p`
        // (presisi 14 digit): 1-0.95 = 0.050000000000000044 tidak boleh jatuh
        // ke tabel 0.05.
        for ($digit = 1; $digit <= 17; $digit++) {
            $teks = sprintf('%.'.$digit.'G', $p);

            if ((float) $teks === $p) {
                return $teks;
            }
        }

        return sprintf('%.17G', $p);
    }

    /** @return list<float>|false */
    private static function baca(string $kunci): array|false
    {
        $jalur = resource_path('studio/tinv-excel/p'.$kunci.'.bin');

        if (! is_file($jalur)) {
            return false;
        }

        $isi = (string) file_get_contents($jalur);

        if ($isi === '' || strlen($isi) % 8 !== 0) {
            return false;
        }

        /** @var array<int, float> $angka */
        $angka = unpack('e*', $isi);

        return array_values($angka);
    }
}
