<?php

namespace Tests\Concerns;

use Dompdf\Adapter\CPDF;

/**
 * Canvas dompdf yang MENCATAT ukuran huruf tiap teks yang digambar — alat ukur
 * gerbang huruf sertifikat (`SertifikatSemuaAlatSatuHalamanTest`).
 *
 * ## Kenapa canvas, bukan membaca PDF-nya
 *
 * Audit sertifikat 11/13 Nov 2026 mengukur "median ukuran huruf semua karakter
 * bukan-spasi di halaman 1" dari PDF hasil render (pypdfium2). Dompdf menulis
 * teks sebagai glyph TrueType yang di-subset dan dikompres, jadi membaca ukuran
 * dari bytes PDF di PHP berarti menulis pembaca PDF. Canvas ini menangkap angka
 * yang SAMA tepat sebelum dompdf menuliskannya.
 *
 * Ukuran dicatat dengan pembulatan yang sama persis dengan yang ditulis dompdf
 * ke PDF (`sprintf('%.1F', $ukuran)` pada operator `Tf` di `Cpdf::addText`),
 * supaya angkanya sama dengan yang dilihat pypdfium2 di berkas jadi: 8,25 pt
 * (11 px) tercetak di PDF sebagai 8,3 (atau 8,2), bukan 8,25.
 *
 * ## Mencocokkan dengan PDF yang diterbitkan
 *
 * `SertifikatSatuHalaman` merender BANYAK kali (tangga kerapatan + kotak uji),
 * dan hanya satu hasilnya yang sampai ke arsip. Tiap `output()` dicatat dengan
 * md5 bytes PDF-nya; pengujinya mencari catatan berdasarkan md5 PDF yang
 * benar-benar diarsipkan, jadi yang diukur terbukti render yang terbit — bukan
 * "render terakhir" yang ditebak.
 *
 * Dipasang lewat `dompdf.options.pdf_backend` (lihat `pasang()`); kelasnya wajib
 * sudah dimuat karena `CanvasFactory` memeriksanya dengan `class_exists(…, false)`.
 */
class PengukurHurufCanvas extends CPDF
{
    /** @var array<string, array{halaman: int, ukuran: array<string, int>}> md5 PDF => catatan */
    private static array $render = [];

    /** @var array<string, int> ukuran huruf (pt, "%.1F") => jumlah karakter bukan-spasi */
    private array $ukuran = [];

    /** Pasang canvas ini untuk SEMUA render dompdf berikutnya di proses tes. */
    public static function pasang(): void
    {
        class_exists(self::class);
        config(['dompdf.options.pdf_backend' => self::class]);
        self::$render = [];
    }

    /**
     * Catatan render yang menghasilkan PDF ini, atau `null` bila bytes ini bukan
     * keluaran canvas ini (mis. PDF dari jalur lain, atau canvas belum terpasang).
     *
     * @return array{halaman: int, ukuran: array<string, int>}|null
     */
    public static function catatan(string $isiPdf): ?array
    {
        return self::$render[md5($isiPdf)] ?? null;
    }

    /**
     * Median ukuran huruf (pt) per karakter — sama dengan `statistics.median`
     * pada daftar ukuran tiap karakter bukan-spasi (skrip audit `pdfmetrik.py`),
     * dibulatkan dua desimal.
     *
     * @param  array<string, int>  $ukuran
     */
    public static function median(array $ukuran): float
    {
        $ukuran = array_filter($ukuran, fn (int $n): bool => $n > 0);
        $jumlah = array_sum($ukuran);

        if ($jumlah === 0) {
            return 0.0;
        }

        uksort($ukuran, fn (string $a, string $b): int => (float) $a <=> (float) $b);

        // Indeks (basis 0) elemen tengah; genap = rata-rata dua elemen tengah.
        $bawah = intdiv($jumlah - 1, 2);
        $atas = intdiv($jumlah, 2);
        $nilaiBawah = $nilaiAtas = 0.0;
        $lewat = 0;

        foreach ($ukuran as $pt => $n) {
            if ($bawah >= $lewat && $bawah < $lewat + $n) {
                $nilaiBawah = (float) $pt;
            }
            if ($atas >= $lewat && $atas < $lewat + $n) {
                $nilaiAtas = (float) $pt;
            }
            $lewat += $n;
        }

        return round(($nilaiBawah + $nilaiAtas) / 2, 2);
    }

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        $n = preg_match_all('/[^\s\x{00A0}]/u', (string) $text);

        if ($n === false) {
            $n = preg_match_all('/\S/', (string) $text);
        }

        if ($n) {
            $kunci = sprintf('%.1F', $size);
            $this->ukuran[$kunci] = ($this->ukuran[$kunci] ?? 0) + $n;
        }

        parent::text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
    }

    public function output($options = [])
    {
        $isi = parent::output($options);

        self::$render[md5($isi)] = ['halaman' => $this->get_page_count(), 'ukuran' => $this->ukuran];

        return $isi;
    }
}
