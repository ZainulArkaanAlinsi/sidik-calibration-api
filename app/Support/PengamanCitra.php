<?php

namespace App\Support;

/**
 * SATU-SATUNYA tempat di `app/` yang boleh mendekode citra (`imagecreatefromstring`, `imagecreatefrom*`,
 * `Imagick`). Dijaga `PengamanCitraTest::test_tidak_ada_decode_citra_di_luar_pengaman`: pemanggil baru yang
 * mendekode langsung membuat tes itu merah.
 *
 * ## Kenapa ada
 *
 * Mendekode citra memakan memori sebanding dengan PIKSEL-nya, bukan ukuran berkasnya. JPEG 6000x8000
 * hanya ~750 KB (jauh di bawah batas unggah 8 MB) tetapi memerlukan ~190 MB di GD. Kehabisan memori di
 * PHP itu FATAL: prosesnya mati, jawabannya 500 polos, `@` tidak menangkapnya, dan teknisi tidak
 * mendapat jalan keluar "isi manual". Pernah ditemukan di tiga tempat sekaligus (potong sel lama, potong
 * sel OCR awan, penebal tanda tangan), semuanya tanpa batas piksel.
 *
 * ## Cara kerja
 *
 * Dimensi dibaca dari HEADER (`getimagesizefromstring`, tanpa dekode penuh). Citra ditolak (null) bila
 * bukan citra yang bisa dibaca, sisinya nol atau negatif, atau lebar x tinggi melebihi batas. Baru
 * sesudah itu didekode. Penolakan memakai jalur "gagal dekode" yang sudah dimiliki tiap pemanggil.
 *
 * ## Batas piksel (`config('ocr.maks_piksel_citra')`)
 *
 * Memori GD truecolor ~4 byte per piksel (diukur 25 Nov 2026 dengan `memory_get_peak_usage`: 12 MP =
 * 48 MB, 16 MP = 68 MB, 20 MP = 78 MB, 48 MP = 194 MB; JPEG progresif tidak berbeda). Anggaran:
 *
 *     piksel maks = (memory_limit - dasar request - isi berkas) / 4 byte x faktor aman
 *                 = (128 MB - ~45 MB - 8 MB) / 4 x ~0,8 = ~16 juta piksel
 *
 * `memory_limit` produksi 128 MB (komentar `docker/entrypoint.sh`), isi berkas paling banyak 8 MB (batas
 * unggah), dasar request Laravel ~45 MB. 16 MP meloloskan foto HP 12 MP (4000x3000) dan 4608x3456, dan
 * menolak 48 MP (8000x6000) serta 108 MP.
 */
final class PengamanCitra
{
    /**
     * Cadangan bila `config()` tidak ada (tes unit murni tanpa aplikasi). HARUS sama dengan
     * `ocr.maks_piksel_citra` di `config/ocr.php`; dijaga `PengamanCitraTest`.
     */
    public const BATAS_PIKSEL_BAWAAN = 16_000_000;

    /** Isi bukan citra yang bisa dibaca (rusak, format tak dikenal, atau sisi nol/negatif). */
    public const BUKAN_CITRA = 'bukan_citra';

    /** Piksel melebihi batas: tidak didekode. */
    public const TERLALU_BESAR = 'terlalu_besar';

    /**
     * Dekode `$isi` menjadi citra GD, atau null bila tidak layak. `$alasan` diisi `BUKAN_CITRA` atau
     * `TERLALU_BESAR` pada kegagalan, null pada keberhasilan.
     */
    public static function dekode(string $isi, ?string &$alasan = null): ?\GdImage
    {
        $alasan = self::tolak($isi);

        if ($alasan !== null) {
            return null;
        }

        $gambar = @imagecreatefromstring($isi);

        if ($gambar === false) {
            $alasan = self::BUKAN_CITRA;

            return null;
        }

        return $gambar;
    }

    /**
     * Alasan menolak mendekode `$isi`, null bila boleh. Hanya membaca header.
     *
     * `$batasPiksel` null = plafon umum ([batasPiksel]). Pemanggil yang punya batas lebih ketat (potongan sel
     * yang diunggah HP, `OcrSelUnggahController`) menyebutnya di sini; batas itu hanya boleh MENGETATKAN, jadi
     * nilai di atas plafon umum tetap dipotong plafon.
     */
    public static function tolak(string $isi, ?int $batasPiksel = null): ?string
    {
        $ukuran = $isi === '' ? false : @getimagesizefromstring($isi);

        if ($ukuran === false) {
            return self::BUKAN_CITRA;
        }

        $lebar = (int) $ukuran[0];
        $tinggi = (int) $ukuran[1];

        if ($lebar <= 0 || $tinggi <= 0) {
            return self::BUKAN_CITRA;
        }

        // Header PNG boleh mengaku sisi sampai 2^31; kalau hasil kalinya meluap, PHP menjadikannya float
        // dan perbandingannya tetap benar (ditolak), bukan membungkus jadi angka kecil.
        $batas = self::batasPiksel();

        if ($batasPiksel !== null && $batasPiksel > 0) {
            $batas = min($batas, $batasPiksel);
        }

        return $lebar * $tinggi > $batas ? self::TERLALU_BESAR : null;
    }

    public static function batasPiksel(): int
    {
        $nilai = null;

        try {
            if (function_exists('app') && app()->bound('config')) {
                $nilai = config('ocr.maks_piksel_citra');
            }
        } catch (\Throwable) {
            $nilai = null;
        }

        return is_numeric($nilai) && (int) $nilai > 0 ? (int) $nilai : self::BATAS_PIKSEL_BAWAAN;
    }
}
