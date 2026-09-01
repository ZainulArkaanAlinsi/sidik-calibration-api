<?php

namespace Tests\Unit;

use App\Support\UkuranTandaTangan;
use PHPUnit\Framework\TestCase;

/**
 * Gambar tanda tangan tidak boleh meluber keluar kotaknya.
 *
 * ## Cacat yang dijaga di sini
 *
 * Blade cuma menyetel LEBAR gambar dan menempelkannya `bottom: 0` di kotak
 * setinggi 46px. Tingginya dibiarkan ikut rasio gambar, dan kotaknya tidak
 * memotong apa pun — jadi gambar yang tidak lebar-mendatar meluber KE ATAS,
 * menimpa tabel STANDARD USED di atasnya. Persis yang terjadi di produksi 1 Sep
 * 2026: tanda tangan tercetak melayang di tengah tabel standar.
 *
 * Yang bikin dia lolos selama ini: dengan gambar contoh yang lebar-mendatar,
 * tingginya kebetulan muat. Baru gambar asli yang mendekati persegi yang
 * memunculkannya — dan waktu itu tidak ada satu pun error, cuma PDF yang jelek.
 */
class UkuranTandaTanganTest extends TestCase
{
    /** PNG polos dengan dimensi yang diminta. */
    private function png(int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor($lebar, $tinggi);
        ob_start();
        imagepng($gambar);
        $isi = (string) ob_get_clean();
        imagedestroy($gambar);

        return $isi;
    }

    /** Tanda tangan lebar-mendatar memang muat — lebar pilihan admin dihormati. */
    public function test_gambar_mendatar_dipakai_apa_adanya(): void
    {
        $hasil = UkuranTandaTangan::pas($this->png(1000, 200), 35.0);

        $this->assertSame(35.0, $hasil['lebar_mm']);
        $this->assertEqualsWithDelta(7.0, $hasil['tinggi_mm'], 0.01);
    }

    /**
     * Gambar persegi: inilah kasus yang merusak sertifikat di produksi.
     *
     * 800x800 di lebar 35 mm = tinggi 35 mm, sementara kotaknya cuma 12,17 mm.
     */
    public function test_gambar_persegi_dikecilkan_sampai_muat(): void
    {
        $kotak = UkuranTandaTangan::tinggiKotakMm();
        $hasil = UkuranTandaTangan::pas($this->png(800, 800), 35.0);

        $this->assertEqualsWithDelta($kotak, $hasil['tinggi_mm'], 0.01);
        $this->assertLessThan(35.0, $hasil['lebar_mm']);
    }

    /** Rasio asli dijaga — tanda tangan yang gepeng terbaca sebagai tanda tangan lain. */
    public function test_rasio_asli_dijaga_waktu_dikecilkan(): void
    {
        $hasil = UkuranTandaTangan::pas($this->png(600, 800), 35.0);

        // Aslinya tinggi/lebar = 800/600.
        $this->assertEqualsWithDelta(
            800 / 600,
            $hasil['tinggi_mm'] / $hasil['lebar_mm'],
            0.001,
        );
    }

    /** Mode padat kotaknya separuh, jadi batasnya ikut mengecil. */
    public function test_mode_padat_pakai_kotak_yang_lebih_pendek(): void
    {
        $normal = UkuranTandaTangan::pas($this->png(800, 800), 35.0, 0.0, false);
        $padat = UkuranTandaTangan::pas($this->png(800, 800), 35.0, 0.0, true);

        $this->assertLessThan($normal['tinggi_mm'], $padat['tinggi_mm']);
        $this->assertEqualsWithDelta(UkuranTandaTangan::tinggiKotakMm(true), $padat['tinggi_mm'], 0.01);
    }

    /**
     * Penjaga yang sebenarnya: apa pun rasionya, apa pun lebar yang disetel
     * admin, tingginya TIDAK PERNAH melewati kotak. Satu saja yang lolos,
     * sertifikatnya rusak tanpa satu pun error.
     */
    public function test_apa_pun_rasionya_nggak_pernah_lewat_kotak(): void
    {
        foreach ([[1000, 100], [800, 600], [800, 800], [600, 800], [200, 1000], [50, 4000]] as [$w, $h]) {
            foreach ([10.0, 35.0, 80.0] as $lebarMm) {
                foreach ([false, true] as $padat) {
                    $hasil = UkuranTandaTangan::pas($this->png($w, $h), $lebarMm, 0.0, $padat);

                    $this->assertLessThanOrEqual(
                        UkuranTandaTangan::tinggiKotakMm($padat) + 0.01,
                        $hasil['tinggi_mm'],
                        "Luber: {$w}x{$h} pada lebar {$lebarMm}mm, padat=".var_export($padat, true),
                    );
                }
            }
        }
    }

    /**
     * Dimensinya tidak terbaca (berkas rusak): tingginya dipatok ke kotak dan
     * LEBARNYA dilepas, biar dompdf menskalakan proporsional. Menebak lebar di
     * sini berarti menerbitkan tanda tangan yang gepeng.
     */
    public function test_berkas_rusak_nggak_bikin_tanda_tangan_gepeng(): void
    {
        foreach ([null, '', 'ini-bukan-gambar'] as $isi) {
            $hasil = UkuranTandaTangan::pas($isi, 35.0);

            $this->assertNull($hasil['lebar_mm']);
            $this->assertEqualsWithDelta(UkuranTandaTangan::tinggiKotakMm(), $hasil['tinggi_mm'], 0.01);
        }
    }

    /** Kedua mode dihitung sekaligus, karena blade baru tahu modenya belakangan. */
    public function test_kedua_mode_dihitung_sekaligus(): void
    {
        $hasil = UkuranTandaTangan::keduaMode($this->png(800, 800), 35.0);

        $this->assertArrayHasKey('normal', $hasil);
        $this->assertArrayHasKey('padat', $hasil);
        $this->assertEqualsWithDelta(UkuranTandaTangan::tinggiKotakMm(false), $hasil['normal']['tinggi_mm'], 0.01);
        $this->assertEqualsWithDelta(UkuranTandaTangan::tinggiKotakMm(true), $hasil['padat']['tinggi_mm'], 0.01);
    }

    /**
     * Menjepit TINGGI saja tidak cukup — temuan review sebelum merge.
     *
     * Blade menempelkan gambar dengan `bottom: <geser_y>mm`, dan `geser_y_mm`
     * boleh sampai +40 mm sementara kotaknya cuma 12,17 mm. Jadi gambar yang
     * sudah pas pun tetap terangkat keluar kotak begitu digeser ke atas —
     * luapannya persis sebesar geserannya, dan yang ditimpanya tabel di atasnya.
     */
    public function test_geser_ke_atas_nggak_bisa_ngangkat_gambar_keluar_kotak(): void
    {
        foreach ([[1000, 200], [800, 800], [600, 800]] as [$w, $h]) {
            foreach ([1.0, 5.0, 40.0] as $geser) {
                foreach ([false, true] as $padat) {
                    $hasil = UkuranTandaTangan::pas($this->png($w, $h), 35.0, $geser, $padat);

                    $puncak = $hasil['tinggi_mm'] + $hasil['geser_y_mm'];

                    $this->assertLessThanOrEqual(
                        UkuranTandaTangan::tinggiKotakMm($padat) + 0.01,
                        $puncak,
                        "Puncak gambar keluar kotak: {$w}x{$h}, geser {$geser}mm, padat=".var_export($padat, true),
                    );
                }
            }
        }
    }

    /**
     * Geseran ke BAWAH sengaja dibiarkan apa adanya.
     *
     * Yang ditimpanya garis tanda tangan dan nama penanda tangan — tanda tangan
     * yang sedikit memotong garisnya itu wajar, dan menjepitnya bakal merebut
     * penyetelan halus yang memang hak admin.
     */
    public function test_geser_ke_bawah_nggak_dijepit(): void
    {
        $hasil = UkuranTandaTangan::pas($this->png(1000, 200), 35.0, -4.0);

        $this->assertSame(-4.0, $hasil['geser_y_mm']);
    }

    /**
     * Konstanta di PHP dan angka di CSS blade wajib sama.
     *
     * Blade sengaja menulis angkanya literal biar CSS-nya kebaca apa adanya,
     * jadi tidak ada yang memaksa keduanya sejalan selain test ini. Kalau
     * kotaknya dikecilkan di CSS tanpa konstanta ini ikut berubah, penjepitnya
     * memakai ukuran yang salah dan gambarnya meluber lagi — tanpa satu pun
     * error.
     */
    public function test_konstanta_cocok_dengan_css_blade(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/sertifikat/pdf.blade.php',
        );

        $this->assertMatchesRegularExpression(
            '/\.ttd \.ruang-ttd \{ height: '.UkuranTandaTangan::TINGGI_KOTAK_PX.'px;/',
            $css,
            'TINGGI_KOTAK_PX beda dari `.ttd .ruang-ttd` di blade.',
        );

        $this->assertMatchesRegularExpression(
            '/body\.padat \.ttd \.ruang-ttd \{ height: '.UkuranTandaTangan::TINGGI_KOTAK_PADAT_PX.'px;/',
            $css,
            'TINGGI_KOTAK_PADAT_PX beda dari `body.padat .ttd .ruang-ttd` di blade.',
        );
    }
}
