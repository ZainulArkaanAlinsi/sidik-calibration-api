<?php

namespace App\Services\Studio\Workbook;

/**
 * Penanda sheet workbook master yang isinya JUGA ada di tabel aplikasi.
 *
 * ## K-49-12 (pemilik, 13 Okt 2026): isi TIDAK lagi dikecualikan
 *
 * Dulu (K-49-08) isi sheet DATABASE / SERTIFIKAT / konsep tidak diekstrak.
 * K-49-12 mencabutnya untuk sumber `.xlsm` DAN `.csv`: workbook ditampilkan apa
 * adanya, termasuk data pelanggan di sheet-sheet itu. Batasnya pindah ke tempat
 * penyimpanan: berkas & DB tetap di luar repo (repo PUBLIC), dan isinya tetap
 * terkurung organisasi (API menjawab 404 untuk lab lain).
 *
 * Yang tersisa dari kelas ini cuma PENANDA informatif: `tautan_tabel`
 * (`customers` / `certificates`) supaya UI bisa menulis "lembar ini juga ada
 * di tabel X". Lembarnya selalu `dikecualikan = false`. Nama kelas sengaja
 * tidak diganti (dirujuk SDD, Task Breakdown, dan test S1b).
 *
 * Pencocokan atas nama sheet yang dinormalkan: huruf besar, `_` → spasi,
 * spasi beruntun jadi satu, tepi dipangkas. Jadi "DATABASE", "database ",
 * "SERTIFIKAT_(2)" dan "Sertifikat  Style 1" sama-sama tertangkap.
 *
 * Daftar nama (sensus 46 folder CSV di repo, 13 Okt 2026):
 *
 *  - `DATABASE` (46 folder) → `customers`.
 *  - `SERTIFIKAT` (45), `SERTIFIKAT (2)`, `SERTIFIKAT STYLE 1/2` → `certificates`.
 *  - `Certificate (2)` (Centrifuge, Tachometer): tata letak sertifikat
 *    berbahasa Inggris dengan blok Owner/Address → `certificates`.
 *  - `konsep` (Centrifuge, Tachometer, Timer): KONSEP sertifikat — blok
 *    "CALIBRATION CERTIFICATE" dengan Owner/Address yang sama → `certificates`.
 *
 * SENGAJA TIDAK ditandai: `SERTIFIKAT KALIBRATOR` — itu data sertifikat
 * STANDAR milik lab sendiri, bukan cermin tabel `certificates`.
 */
final class PengecualianLembar
{
    public const TAUTAN_PELANGGAN = 'customers';

    public const TAUTAN_SERTIFIKAT = 'certificates';

    /**
     * Pola nama ternormal → tabel tautan.
     *
     * @var array<string, string>
     */
    private const POLA = [
        '/^DATABASE( \(\d+\))?$/' => self::TAUTAN_PELANGGAN,
        '/^(SERTIFIKAT|CERTIFICATE)( \(\d+\)| STYLE \d+)?$/' => self::TAUTAN_SERTIFIKAT,
        '/^KONSEP$/' => self::TAUTAN_SERTIFIKAT,
    ];

    /** Tabel yang juga memuat isi lembar ini, atau null untuk lembar biasa. */
    public function tautan(string $namaSheet): ?string
    {
        $normal = self::normalkan($namaSheet);

        foreach (self::POLA as $pola => $tautan) {
            if (preg_match($pola, $normal) === 1) {
                return $tautan;
            }
        }

        return null;
    }

    public static function normalkan(string $nama): string
    {
        $nama = str_replace('_', ' ', mb_strtoupper($nama, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $nama));
    }
}
