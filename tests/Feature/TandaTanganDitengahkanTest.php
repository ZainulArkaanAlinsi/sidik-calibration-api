<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Organization;
use App\Services\SertifikatSatuHalaman;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tanda tangan ditengahkan di atas garisnya, diukur dari PDF yang jadi.
 *
 * ## Kenapa diukur dari PDF, bukan dari HTML
 *
 * Yang dikeluhkan pemilik proyek posisi CETAK. Memeriksa `text-align: center`
 * ada di CSS cuma membuktikan atributnya tertulis — bukan bahwa dompdf
 * menghormatinya. dompdf memang punya lubang di beberapa properti posisi, dan
 * `left: 50%` + margin negatif salah satunya. Yang mengikat di sini koordinat
 * yang benar-benar ditulis ke berkas.
 *
 * ## Cara mengukurnya
 *
 * Content stream PDF menaruh gambar lewat matriks `w 0 0 h x y cm /Obj Do`
 * (satuan poin), dan garis tanda tangan lewat `x1 y1 m x2 y2 l S`. Dua-duanya
 * diambil, dibandingkan titik tengahnya.
 *
 * Sebelum perbaikan, terukur di sertifikat `012-CAL-524`: garisnya
 * 10,76 → 82,06 mm, gambarnya 10,76 → 29,98 mm — 26,0 mm di kiri dari tengah.
 * Sebabnya `left: 0` pada gambar `position: absolute`.
 */
class TandaTanganDitengahkanTest extends TestCase
{
    use RefreshDatabase;

    /** Toleransi (mm). Pembulatan mm→pt dompdf jauh di bawah ini. */
    private const TOLERANSI_MM = 0.5;

    public function test_tanda_tangan_ditengahkan_di_atas_garisnya(): void
    {
        $this->seed(DatabaseSeeder::class);

        Storage::disk('arsip')->put('ttd-uji.png', $this->coretan());
        $organisasi = Organization::query()->firstOrFail();
        $organisasi->tanda_tangan_path = 'ttd-uji.png';
        $organisasi->save();

        $sertifikat = Certificate::query()->whereNotNull('snapshot')->firstOrFail();
        $pdf = app(SertifikatSatuHalaman::class)->isi($sertifikat);

        [$gambar, $garis] = $this->ukur($pdf);

        $this->assertNotNull($gambar, 'tanda tangan nggak kegambar sama sekali di PDF-nya');
        $this->assertNotNull($garis, 'garis tanda tangan nggak ketemu di PDF-nya');

        $tengahGambar = $gambar['x'] + $gambar['lebar'] / 2;
        $tengahGaris = ($garis['x1'] + $garis['x2']) / 2;

        $this->assertEqualsWithDelta(
            $tengahGaris,
            $tengahGambar,
            self::TOLERANSI_MM,
            sprintf(
                'Tanda tangan nggak di tengah garisnya. Garis %.2f → %.2f mm (tengah %.2f), '
                .'gambar %.2f → %.2f mm (tengah %.2f) — meleset %.2f mm.',
                $garis['x1'], $garis['x2'], $tengahGaris,
                $gambar['x'], $gambar['x'] + $gambar['lebar'], $tengahGambar,
                $tengahGambar - $tengahGaris,
            ),
        );

        // Jangkar BAWAH nggak boleh ikut bergeser waktu penengahan dipasang:
        // gambar tumbuh ke atas dari garis, dan `geser_y_mm` bergantung ke situ.
        $this->assertEqualsWithDelta(
            $garis['y'],
            $gambar['y'],
            1.0,
            'Dasar tanda tangan mestinya duduk di garisnya.',
        );
    }

    /**
     * Koordinat gambar tanda tangan & garisnya, dalam mm dari tepi kiri halaman.
     *
     * @return array{0: ?array{lebar: float, tinggi: float, x: float, y: float}, 1: ?array{x1: float, x2: float, y: float}}
     */
    private function ukur(string $pdf): array
    {
        $isi = '';
        foreach (self::potongStream($pdf) as $blok) {
            $mentah = @gzuncompress($blok);
            if ($mentah !== false) {
                $isi .= $mentah."\n";
            }
        }

        $pt = 72 / 25.4;
        $gambar = null;
        $garis = null;

        // Kop halaman ikut kejaring; yang dicari yang selebar tanda tangan,
        // jadi yang selebar halaman (>100 mm) dibuang.
        preg_match_all(
            '/([-\d.]+)\s+0\s+0\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+cm\s*\/\w+\s+Do/',
            $isi,
            $cocok,
            PREG_SET_ORDER,
        );
        foreach ($cocok as $c) {
            $lebar = (float) $c[1] / $pt;
            if ($lebar < 100) {
                $gambar = [
                    'lebar' => $lebar,
                    'tinggi' => (float) $c[2] / $pt,
                    'x' => (float) $c[3] / $pt,
                    'y' => (float) $c[4] / $pt,
                ];
            }
        }

        // Garis tanda tangan: horizontal, dan yang paling DEKAT ke dasar
        // gambarnya. Versi pertama cuma menyaring "panjang & di bawah", dan
        // ketangkap rule lain selebar 189 mm di area kaki — lalu test-nya merah
        // dengan alasan yang salah. Yang menentukan hubungannya ke tanda
        // tangan, bukan panjangnya.
        $jarakTerdekat = null;
        preg_match_all('/([-\d.]+)\s+([-\d.]+)\s+m\s+([-\d.]+)\s+([-\d.]+)\s+l\s+S/', $isi, $cocok, PREG_SET_ORDER);
        foreach ($cocok as $c) {
            [$x1, $y1, $x2, $y2] = [(float) $c[1] / $pt, (float) $c[2] / $pt, (float) $c[3] / $pt, (float) $c[4] / $pt];

            if (abs($y1 - $y2) >= 0.5 || $gambar === null) {
                continue;
            }

            $jarak = abs($y1 - $gambar['y']);

            if ($jarak < 3.0 && ($jarakTerdekat === null || $jarak < $jarakTerdekat)) {
                $jarakTerdekat = $jarak;
                $garis = ['x1' => $x1, 'x2' => $x2, 'y' => $y1];
            }
        }

        return [$gambar, $garis];
    }

    /**
     * Potongan mentah tiap `stream … endstream` di berkas PDF.
     *
     * Dipotong sendiri, bukan pakai pustaka: yang dibutuhkan cuma isi stream
     * apa adanya buat di-inflate, dan menambah dependensi pengurai PDF penuh
     * demi satu test bikin ongkos rawatnya jauh lebih besar daripada gunanya.
     *
     * @return list<string>
     */
    private static function potongStream(string $pdf): array
    {
        $keluar = [];
        $posisi = 0;

        while (($awal = strpos($pdf, 'stream', $posisi)) !== false) {
            $mulai = $awal + 6;
            $mulai += (substr($pdf, $mulai, 2) === "\r\n") ? 2 : 1;
            $akhir = strpos($pdf, 'endstream', $mulai);

            if ($akhir === false) {
                break;
            }

            $keluar[] = substr($pdf, $mulai, $akhir - $mulai);
            $posisi = $akhir + 9;
        }

        return $keluar;
    }

    /**
     * Tanda tangan buatan pada kanvas 1475x1746 — rasio yang sama dengan hasil
     * pindai asli, supaya jalur "tinggi dijepit kotak, lebar ikut rasio" yang
     * kepakai, persis seperti di produksi.
     */
    private function coretan(): string
    {
        $im = imagecreatetruecolor(1475, 1746);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));

        $hitam = imagecolorallocatealpha($im, 0, 0, 0, 0);
        imagesetthickness($im, 14);

        for ($x = 60; $x < 1415; $x += 6) {
            imageline(
                $im,
                $x,
                900 + (int) (600 * sin($x / 190)),
                $x + 6,
                900 + (int) (600 * sin(($x + 6) / 190)),
                $hitam,
            );
        }

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }
}
