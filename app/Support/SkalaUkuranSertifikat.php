<?php

namespace App\Support;

/**
 * Huruf sertifikat yang MENGIKUTI ukuran median lembar master (A3, audit 11 Nov
 * 2026) — alih-alih tangga kerapatan tetap (`longgar-2 ... rapat-2`, `padat`).
 *
 * ## Masalahnya
 *
 * Tangga lama punya lompatan kasar: 13,5 / 12,5 / 11 / 10,5 / 9,5 / 8 px. Yang
 * dipilih selalu tingkat TERBESAR yang muat, jadi
 *
 *  - lembar yang muat di 11 px terbit 8,2 pt padahal masternya 7,3 pt (Pressure
 *    Gauge, Timer: 1,12 - 1,14 x master — terlalu besar), dan
 *  - lembar yang tidak muat di 9,5 px jatuh ke `padat` 8 px = 6,0 pt padahal
 *    masternya 7,4 pt (Oven: 0,81 x — terlalu kecil), padahal di antaranya
 *    masih ada ruang (isi Oven hanya 78 % tinggi halaman).
 *
 * ## Caranya
 *
 * Profil yang ukuran masternya dinyatakan (`tata_letak.font_master_pt`, dibekukan
 * di snapshot BARU) mencoba ukuran tabel dari 1,00 x ukuran master turun ke
 * 0,95 x, per 0,25 px. Dua keluarga jarak:
 *
 *  - `rapat`: jarak `rapat-2` (nyaman) — dicoba lebih dulu;
 *  - `padat`: jarak `padat` (ketat, tanda tangan 28 px) — bila `rapat` tak muat.
 *
 * Semua ukuran huruf keluarga itu dikali `ukuran / ukuran-tabel-dasar`, jarak
 * TIDAK diubah. Gerbang muat satu halaman + sisa 6 px (`.cadangan-uji`) tetap
 * sama dengan tangga lama; tidak ada yang muat -> tangga lama dipakai apa adanya.
 *
 * Snapshot terbit sebelum A3 tidak punya `font_master_pt`, jadi tangga lama,
 * markup, dan CSS-nya tidak berubah satu byte (`SertifikatBekuTidakBerubahTest`).
 */
final class SkalaUkuranSertifikat
{
    /** 1 px CSS = 0,75 pt di dompdf (96 dpi). */
    public const PT_PER_PX = 0.75;

    /** Rasio ukuran master yang dicoba: dari persis master (1,00) sampai batas bawah target (0,95). */
    public const RASIO_BAWAH = 0.95;

    /** Langkah pencarian (px). */
    public const LANGKAH_PX = 0.25;

    /** Batas atas ukuran tabel (px): normal. Tidak pernah lebih besar dari ini. */
    public const MAKS_PX = 11.0;

    /**
     * Ukuran tabel dasar (px) tiap keluarga = nilai `table.data` pada CSS-nya
     * (`body.rapat-2 table.data`, `body.padat table.data`).
     */
    private const DASAR_TABEL = ['rapat' => 9.5, 'padat' => 8.0];

    /**
     * Ukuran huruf (px) tiap selektor pada keluarga itu — disalin dari
     * `resources/views/sertifikat/pdf.blade.php`. Selektor yang tak dinyatakan
     * keluarga itu (mis. `.blok-catatan` di `rapat`) memakai nilai bawaan lembar.
     *
     * @var array<string, array<string, float>>
     */
    private const DASAR = [
        'rapat' => [
            'body' => 10,
            'table.data' => 9.5,
            'table.info' => 9.5,
            '.judul' => 14,
            '.judul-sub' => 10.5,
            '.judul-kelompok' => 10,
            '.ket-k' => 8,
            '.catatan' => 9,
            '.baris-angka' => 9.5,
            '.blok-catatan' => 10,
            'table.ttd td' => 10.5,
            '.kode-dokumen' => 8.5,
        ],
        'padat' => [
            'table.data' => 8,
            // 8 (bukan 8,5 seperti `body.padat table.info`): kepala identitas ikut
            // ukuran tabel, supaya median huruf lembar = ukuran tabel (terukur:
            // tits 8,5 pt vs master 8,04 bila kepala tetap 8,5/8 x tabel).
            'table.info' => 8,
            '.judul' => 14,
            '.judul-sub' => 10.5,
            '.judul-kelompok' => 9.5,
            '.ket-k' => 7.5,
            '.catatan' => 8,
            '.baris-angka' => 8.5,
            '.blok-catatan' => 8.5,
            'table.ttd' => 9.5,
            'table.ttd td' => 9.5,
            'table.data tr.satuan th' => 7.5,
            '.kode-dokumen' => 8,
        ],
    ];

    /**
     * Ukuran tabel (px) yang dicoba, berurutan dari yang diinginkan (1,00 x master)
     * ke batas bawah (0,95 x), dibatasi [MAKS_PX].
     *
     * @return list<float>
     */
    public static function kandidat(float $fontMasterPt): array
    {
        $target = min(self::MAKS_PX, $fontMasterPt / self::PT_PER_PX);
        $bawah = $target * self::RASIO_BAWAH;
        $hasil = [];

        for ($f = $target; $f >= $bawah - 1e-9; $f -= self::LANGKAH_PX) {
            $hasil[] = round($f, 2);
        }

        return $hasil;
    }

    /**
     * CSS penimpa untuk `body.skala-ukuran`: semua ukuran huruf keluarga itu
     * dikali `$ukuranTabelPx / dasar`. `!important` supaya menang atas aturan
     * `body.padat ...`/`body.rapat-2 ...` yang lebih spesifik atau lebih belakang.
     */
    public static function css(float $ukuranTabelPx, string $keluarga): string
    {
        $keluarga = $keluarga === 'padat' ? 'padat' : 'rapat';
        $k = $ukuranTabelPx / self::DASAR_TABEL[$keluarga];
        $css = '';

        foreach (self::DASAR[$keluarga] as $selektor => $px) {
            $css .= ($selektor === 'body' ? 'body.skala-ukuran' : 'body.skala-ukuran '.$selektor)
                .' { font-size: '.round($px * $k, 2)."px !important; }\n";
        }

        return $css;
    }
}
