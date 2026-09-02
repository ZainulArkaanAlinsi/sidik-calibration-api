<?php

namespace App\Support;

/**
 * Menghitung ukuran cetak gambar tanda tangan supaya SELALU muat di kotaknya.
 *
 * ## Cacat yang bikin ini ada
 *
 * Blade sertifikat menyetel LEBAR gambar saja (`lebar_mm`, diatur admin), lalu
 * menempelkannya `position: absolute; bottom: 0` di dalam kotaknya. Tingginya
 * tidak pernah dibatasi, dan kotaknya tidak memotong apa pun — jadi gambar yang
 * rasionya tidak lebar-mendatar meluber KE ATAS, menimpa tabel di atasnya.
 *
 * Angkanya bukan selisih tipis. Waktu kotaknya masih 46px (12,17 mm):
 *
 *   800x600  di lebar 35 mm -> tinggi 26,2 mm  -> luber 14,1 mm
 *   800x800  di lebar 35 mm -> tinggi 35,0 mm  -> luber 22,8 mm
 *   600x800  di lebar 35 mm -> tinggi 46,7 mm  -> luber 34,5 mm
 *
 * Docblock di blade sudah menyatakan niatnya sejak awal: *"Tingginya DIPATOK —
 * BUKAN ngikut gambar."* Yang dipatok ternyata cuma KOTAKNYA, bukan gambarnya,
 * jadi niat itu tidak pernah benar-benar ditegakkan.
 *
 * ## Kenapa kotaknya sendiri ikut digedein (1 Sep 2026)
 *
 * Menjepitnya saja malah melahirkan cacat kedua, dan kali ini kelihatan:
 * sertifikat CAL-2026-08-0003 mencetak tanda tangan **13,33 x 12,17 mm** di
 * bawah garis tanda tangan yang **71,3 mm** — 19% lebar garisnya. Bukan lagi
 * merusak tabel, tapi jelas bukan tanda tangan dokumen resmi.
 *
 * Sebabnya kotak 46px itu terlalu pendek buat tanda tangan mana pun yang bukan
 * lebar-mendatar. Tanda tangan aslinya 2248x2052 px (rasio 0,91 — ada ekor
 * turun panjang, dan mengkropnya malah bikin rasionya makin tinggi: kotak
 * tintanya 1475x1746). Di lebar 35 mm dia butuh 31,9 mm, jadi yang dikorbankan
 * lebarnya sampai tinggal 13,3 mm.
 *
 * Batas atas kotaknya DIUKUR, bukan ditebak: seluruh 24 sesi bawaan diterbitkan
 * lalu dirender ulang sambil tinggi kotaknya disapu. Yang paling mepet
 * **Conductivity Meter** — masih muat mode normal di 86px, kedorong ke mode
 * padat di 88px. Yang dipakai 80px, menyisakan margin ~1,6 mm buat catatan yang
 * lebih panjang dari sesi contoh.
 *
 * ## Mode padat TIDAK ikut digedein, dan itu hasil percobaan yang gagal
 *
 * Percobaan pertama menaikkannya 24 -> 44px. Sapuan waktu itu bilang aman —
 * tapi sapuannya bohong: tujuh sesi bawaan nggak pernah keterbit karena
 * approve-nya balik 422 minta konfirmasi, dan sapuannya melewatinya diam-diam.
 * Salah satu yang kelewat **Visible Spectrofotometer**, lembar terpadat di
 * sistem (24 titik ketidakpastian) — dan di 44px dia jadi DUA HALAMAN.
 *
 * Disapu ulang dengan semua 24 sesi: 30px masih muat, 32px sudah meluap. Jadi
 * 24px bukan angka konservatif, itu nyaris seluruh margin yang ada — dan mode
 * padat nggak punya jaring pengaman lagi di bawahnya (lihat
 * `App\Services\SertifikatSatuHalaman`). Menukar margin itu buat tanda tangan
 * yang 1 mm lebih besar di enam alat jelas rugi.
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
     * Tinggi kotak tanda tangan, dalam piksel CSS. 80px = 21,17 mm @96dpi.
     *
     * HARUS sama dengan `.ttd .ruang-ttd` di resources/views/sertifikat/pdf.blade.php.
     *
     * Blade tetap menulis angkanya literal — bukan menginterpolasi konstanta ini —
     * supaya CSS-nya kebaca apa adanya. Yang menjaga keduanya tidak melenceng
     * `UkuranTandaTanganTest::test_konstanta_cocok_dengan_css_blade()`: begitu
     * salah satunya diubah sendirian, test itu merah dengan alasan yang kebaca.
     *
     * Jangan dikecilkan tanpa mengukur ulang. Angka ini yang menentukan seberapa
     * besar tanda tangan tercetak — 46px bikin dia cuma 19% lebar garisnya — dan
     * batas atasnya dipatok sertifikat Conductivity Meter, yang kedorong ke mode
     * padat begitu kotaknya lewat 86px.
     */
    public const TINGGI_KOTAK_PX = 80;

    /**
     * Versi mode padat — `body.padat .ttd .ruang-ttd`. 24px = 6,35 mm.
     *
     * Sengaja TIDAK ikut naik waktu kotak normal digedein: sertifikat Visible
     * Spectrofotometer meluap ke halaman dua begitu angka ini lewat 30px, dan
     * mode padat nggak punya jaring pengaman lagi di bawahnya.
     */
    public const TINGGI_KOTAK_PADAT_PX = 24;

    /** dompdf memetakan 1 px CSS pada 96 dpi. */
    private const DPI = 96;

    public static function tinggiKotakMm(bool $padat = false): float
    {
        $px = $padat ? self::TINGGI_KOTAK_PADAT_PX : self::TINGGI_KOTAK_PX;

        return $px / self::DPI * 25.4;
    }

    /**
     * Ukuran cetak + geseran vertikal untuk kedua mode sekaligus.
     *
     * Dihitung dua-duanya di sini, bukan di blade, karena blade baru tahu mode
     * padat atau tidak sesudah menghitung jumlah baris hasil — dan menaruh
     * aritmetika itu di template berarti dia tidak bisa diuji sendirian.
     *
     * @return array{normal: array{lebar_mm: ?float, tinggi_mm: float, geser_y_mm: float}, padat: array{lebar_mm: ?float, tinggi_mm: float, geser_y_mm: float}}
     */
    public static function keduaMode(?string $isiGambar, float $lebarMm, float $geserYMm = 0.0): array
    {
        return [
            'normal' => self::pas($isiGambar, $lebarMm, $geserYMm, false),
            'padat' => self::pas($isiGambar, $lebarMm, $geserYMm, true),
        ];
    }

    /**
     * Lebar, tinggi, dan geseran vertikal yang dijamin muat di kotaknya.
     *
     * Lebar pilihan admin dihormati SELAMA tingginya masih muat. Begitu tidak
     * muat, yang dikorbankan lebarnya — bukan rasionya. Tanda tangan yang
     * gepeng atau melar terbaca sebagai tanda tangan yang berbeda, dan ini
     * dokumen terkendali.
     *
     * ## Kenapa geserannya ikut dihitung di sini
     *
     * Menjepit tingginya saja tidak cukup. Blade menempelkan gambar dengan
     * `bottom: <geser_y>mm`, dan `geser_y_mm` boleh sampai +40 mm
     * (Organization::MAKS_TTD_GESER_MM) sementara kotaknya cuma 12,17 mm. Jadi
     * gambar yang sudah pas pun tetap terangkat keluar kotak begitu digeser ke
     * atas — luapannya persis sebesar geserannya.
     *
     * Dua arah dijepit, tapi dengan alasan yang BEDA — dan batasnya ikut beda:
     *
     *   ATAS  — batasnya ketat, cuma sampai sisa ruang di atas gambar. Di atas
     *           kotak ada tabel hasil dan tabel standar; menimpanya merusak
     *           penyajian data.
     *   BAWAH — batasnya longgar, sampai setinggi kotaknya sendiri. Tanda
     *           tangan yang sedikit memotong garisnya itu wajar, bahkan
     *           diinginkan, jadi penyetelan halus ke bawah tetap hak admin.
     *           Yang dicegah cuma nilai ekstrem: konfigurasinya mengizinkan
     *           sampai -40 mm, dan di situ tanda tangan mendarat jauh di bawah
     *           nama penanda tangan — bukan penyetelan halus lagi, tapi salah
     *           ketik yang tidak ada yang menangkap.
     *
     * @return array{lebar_mm: ?float, tinggi_mm: float, geser_y_mm: float}
     */
    public static function pas(
        ?string $isiGambar,
        float $lebarMm,
        float $geserYMm = 0.0,
        bool $padat = false,
    ): array {
        $tinggiKotak = self::tinggiKotakMm($padat);
        $rasio = self::rasio($isiGambar);

        // Dimensi aslinya tidak terbaca (berkas rusak, atau format yang tidak
        // dikenali getimagesize). Tingginya dipatok ke kotak dan LEBARNYA
        // dilepas, biar dompdf yang menskalakan proporsional. Menebak lebar di
        // sini berarti menerbitkan tanda tangan yang gepeng.
        if ($rasio === null) {
            return [
                'lebar_mm' => null,
                'tinggi_mm' => $tinggiKotak,
                'geser_y_mm' => self::geserPas($geserYMm, $tinggiKotak, $tinggiKotak),
            ];
        }

        $tinggi = $lebarMm * $rasio;

        if ($tinggi > $tinggiKotak) {
            $lebarMm = $tinggiKotak / $rasio;
            $tinggi = $tinggiKotak;
        }

        return [
            'lebar_mm' => $lebarMm,
            'tinggi_mm' => $tinggi,
            'geser_y_mm' => self::geserPas($geserYMm, $tinggi, $tinggiKotak),
        ];
    }

    /** Ke atas dibatasi sisa ruang; ke bawah setinggi kotaknya sendiri. */
    private static function geserPas(float $geserYMm, float $tinggiMm, float $tinggiKotakMm): float
    {
        if ($geserYMm < 0) {
            return max($geserYMm, -$tinggiKotakMm);
        }

        return min($geserYMm, max(0.0, $tinggiKotakMm - $tinggiMm));
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
