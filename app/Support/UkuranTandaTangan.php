<?php

namespace App\Support;

/**
 * Menghitung ukuran cetak gambar tanda tangan supaya SELALU muat di kotaknya.
 *
 * ## Cacat yang bikin ini ada
 *
 * Blade sertifikat menyetel LEBAR gambar saja (`lebar_mm`, diatur admin), lalu
 * menempelkannya `position: absolute; bottom: 0` di dalam kotak setinggi 46px.
 * Tingginya tidak pernah dibatasi, dan kotaknya tidak memotong apa pun — jadi
 * gambar yang rasionya tidak lebar-mendatar meluber KE ATAS, menimpa tabel di
 * atasnya.
 *
 * Angkanya bukan selisih tipis. Kotaknya cuma 12,17 mm (46px @96dpi):
 *
 *   800x600  di lebar 35 mm -> tinggi 26,2 mm  -> luber 14,1 mm
 *   800x800  di lebar 35 mm -> tinggi 35,0 mm  -> luber 22,8 mm
 *   600x800  di lebar 35 mm -> tinggi 46,7 mm  -> luber 34,5 mm
 *
 * Di mode padat kotaknya cuma 24px (6,35 mm), jadi luapannya lebih parah lagi —
 * dan mode padat itu yang dipakai sertifikat Timbangan serta semua sertifikat
 * dengan lebih dari 12 baris hasil.
 *
 * Docblock di blade sudah menyatakan niatnya sejak awal: *"Tingginya DIPATOK —
 * BUKAN ngikut gambar."* Yang dipatok ternyata cuma KOTAKNYA, bukan gambarnya,
 * jadi niat itu tidak pernah benar-benar ditegakkan.
 *
 * ## Kenapa dihitung di PHP, bukan `max-height` di CSS
 *
 * dompdf tidak menangani `max-height` pada gambar dengan andal, dan `overflow:
 * hidden` di sana pun tidak konsisten. Yang bisa dipercaya cuma `width` dan
 * `height` yang eksplisit. Jadi ukurannya dihitung di sini dari dimensi asli
 * berkasnya, dan blade tinggal mencetak angka jadi.
 */
final class UkuranTandaTangan
{
    /**
     * Tinggi kotak tanda tangan, dalam piksel CSS.
     *
     * HARUS sama dengan `.ttd .ruang-ttd` di resources/views/sertifikat/pdf.blade.php.
     * Blade membacanya dari sini supaya dua angka itu tidak bisa berbeda diam-diam:
     * kalau kotaknya dikecilkan tanpa nilai ini ikut berubah, gambarnya meluber
     * lagi tanpa satu pun error.
     */
    public const TINGGI_KOTAK_PX = 46;

    /** Versi mode padat — `body.padat .ttd .ruang-ttd`. */
    public const TINGGI_KOTAK_PADAT_PX = 24;

    /** dompdf memetakan 1 px CSS pada 96 dpi. */
    private const DPI = 96;

    public static function tinggiKotakMm(bool $padat = false): float
    {
        $px = $padat ? self::TINGGI_KOTAK_PADAT_PX : self::TINGGI_KOTAK_PX;

        return $px / self::DPI * 25.4;
    }

    /**
     * Ukuran cetak untuk kedua mode sekaligus.
     *
     * Dihitung dua-duanya di sini, bukan di blade, karena blade baru tahu mode
     * padat atau tidak sesudah menghitung jumlah baris hasil — dan menaruh
     * aritmetika itu di template berarti dia tidak bisa diuji sendirian.
     *
     * @return array{normal: array{lebar_mm: ?float, tinggi_mm: float}, padat: array{lebar_mm: ?float, tinggi_mm: float}}
     */
    public static function keduaMode(?string $isiGambar, float $lebarMm): array
    {
        return [
            'normal' => self::pas($isiGambar, $lebarMm, false),
            'padat' => self::pas($isiGambar, $lebarMm, true),
        ];
    }

    /**
     * Lebar & tinggi cetak yang dijamin muat, rasio asli dijaga.
     *
     * Lebar pilihan admin dihormati SELAMA tingginya masih muat. Begitu tidak
     * muat, yang dikorbankan lebarnya — bukan rasionya. Tanda tangan yang
     * gepeng atau melar terbaca sebagai tanda tangan yang berbeda, dan ini
     * dokumen terkendali.
     *
     * @return array{lebar_mm: ?float, tinggi_mm: float}
     */
    public static function pas(?string $isiGambar, float $lebarMm, bool $padat = false): array
    {
        $tinggiKotak = self::tinggiKotakMm($padat);
        $rasio = self::rasio($isiGambar);

        // Dimensi aslinya tidak terbaca (berkas rusak, atau format yang tidak
        // dikenali getimagesize). Tingginya dipatok ke kotak dan LEBARNYA
        // dilepas, biar dompdf yang menskalakan proporsional. Menebak lebar di
        // sini berarti menerbitkan tanda tangan yang gepeng.
        if ($rasio === null) {
            return ['lebar_mm' => null, 'tinggi_mm' => $tinggiKotak];
        }

        $tinggi = $lebarMm * $rasio;

        if ($tinggi <= $tinggiKotak) {
            return ['lebar_mm' => $lebarMm, 'tinggi_mm' => $tinggi];
        }

        return ['lebar_mm' => $tinggiKotak / $rasio, 'tinggi_mm' => $tinggiKotak];
    }

    /** Tinggi dibagi lebar, atau null kalau dimensinya tidak terbaca. */
    private static function rasio(?string $isiGambar): ?float
    {
        if (! filled($isiGambar)) {
            return null;
        }

        $ukuran = @getimagesizefromstring($isiGambar);

        if ($ukuran === false) {
            return null;
        }

        [$lebar, $tinggi] = $ukuran;

        if ($lebar <= 0 || $tinggi <= 0) {
            return null;
        }

        return $tinggi / $lebar;
    }
}
