<?php

namespace Tests\Unit;

use App\Services\Vision\PemilahPotonganSel;
use App\Support\PengamanCitra;
use Tests\Support\CitraSintetis;
use Tests\TestCase;

/**
 * Penentu potongan sel yang boleh dikirim ke Google (`POST /api/ocr/sel-awan`), dan parameter batas piksel
 * `PengamanCitra::tolak($isi, $batas)`. Logika murni: tanpa database, tanpa jaringan.
 *
 * Tes ini memakai templat BUATAN, supaya pembedaan baris `jam` / `angka` / tipe lain terbukti tanpa
 * bergantung pada isi templat alat sungguhan (yang diuji lewat `OcrSelUnggahTest`).
 */
class PemilahPotonganSelTest extends TestCase
{
    private PemilahPotonganSel $pemilah;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD tidak ada: bahan uji citra tidak bisa dibuat di mesin seperti ini.');
        }

        config([
            'ocr.awan_sel.maks_byte_potongan' => 1_000,
            'ocr.awan_sel.maks_piksel_potongan' => 500_000,
        ]);

        $this->pemilah = new PemilahPotonganSel;
    }

    /** @return array<string, mixed> */
    private function templat(): array
    {
        return [
            'tabel' => [
                ['tabel_id' => 'tabel_a', 'baris' => [
                    ['baris_ke' => 1, 'tipe' => 'angka'],
                    ['baris_ke' => 2, 'tipe' => 'jam'],
                    ['baris_ke' => 3, 'tipe' => 'teks'],
                    ['baris_ke' => 4],
                ]],
            ],
            'sel' => [
                'tabel_a|1|1|pembacaan' => ['kunci' => 'tabel_a|1|1|pembacaan', 'tabel_id' => 'tabel_a', 'baris_ke' => 1],
                'tabel_a|2|1|pembacaan' => ['kunci' => 'tabel_a|2|1|pembacaan', 'tabel_id' => 'tabel_a', 'baris_ke' => 2],
                'tabel_a|3|1|pembacaan' => ['kunci' => 'tabel_a|3|1|pembacaan', 'tabel_id' => 'tabel_a', 'baris_ke' => 3],
                'tabel_a|4|1|pembacaan' => ['kunci' => 'tabel_a|4|1|pembacaan', 'tabel_id' => 'tabel_a', 'baris_ke' => 4],
                'tabel_x|1|1|pembacaan' => ['kunci' => 'tabel_x|1|1|pembacaan', 'tabel_id' => 'tabel_x', 'baris_ke' => 1],
            ],
        ];
    }

    public function test_hanya_baris_bertipe_angka_masuk_peta_angka(): void
    {
        $peta = $this->pemilah->petaKunci($this->templat());

        $this->assertSame(['tabel_a|1|1|pembacaan'], array_keys($peta['angka']));
        $this->assertCount(5, $peta['ada']);
    }

    public function test_templat_kosong_atau_rusak_tidak_membuka_apa_pun(): void
    {
        foreach ([[], ['tabel' => 'bukan array', 'sel' => 'bukan array'], ['sel' => ['k' => 'bukan array']]] as $templat) {
            $peta = $this->pemilah->petaKunci($templat);

            $this->assertSame([], $peta['angka']);
            $this->assertSame([], $peta['ada']);
        }
    }

    public function test_sel_tanpa_baris_di_tabel_gagal_tutup_bukan_angka(): void
    {
        $peta = $this->pemilah->petaKunci($this->templat());

        // `tabel_x` tidak punya baris di `tabel[]`: tipenya tidak diketahui, jadi tidak boleh keluar.
        $this->assertArrayNotHasKey('tabel_x|1|1|pembacaan', $peta['angka']);
        $this->assertSame('bukan_sel_angka', $this->pemilah->alasanKunci('tabel_x|1|1|pembacaan', $peta));
        // Baris tanpa `tipe` juga tidak lolos.
        $this->assertSame('bukan_sel_angka', $this->pemilah->alasanKunci('tabel_a|4|1|pembacaan', $peta));
    }

    public function test_alasan_kunci_untuk_setiap_jenis_penolakan(): void
    {
        $peta = $this->pemilah->petaKunci($this->templat());

        $this->assertNull($this->pemilah->alasanKunci('tabel_a|1|1|pembacaan', $peta));
        $this->assertSame('bukan_sel_angka', $this->pemilah->alasanKunci('tabel_a|2|1|pembacaan', $peta));
        $this->assertSame('bukan_sel_angka', $this->pemilah->alasanKunci('tabel_a|3|1|pembacaan', $peta));
        $this->assertSame('isian_tidak_dikirim', $this->pemilah->alasanKunci('isian|suhu_awal', $peta));
        $this->assertSame('centang_tidak_dikirim', $this->pemilah->alasanKunci('centang|ya', $peta));
        $this->assertSame('tidak_ada_di_templat', $this->pemilah->alasanKunci('nama_pelanggan', $peta));
        $this->assertSame('tidak_ada_di_templat', $this->pemilah->alasanKunci('tabel_a|99|1|pembacaan', $peta));
        $this->assertSame('tidak_ada_di_templat', $this->pemilah->alasanKunci('', $peta));
        // Kunci mirip tetapi bukan awalan `isian|`: tetap dinilai oleh templat.
        $this->assertSame('tidak_ada_di_templat', $this->pemilah->alasanKunci('isiankah|x', $peta));
    }

    public function test_ukuran_byte_batas_inklusif(): void
    {
        $this->assertNull($this->pemilah->alasanUkuran(1));
        $this->assertNull($this->pemilah->alasanUkuran(1_000));
        $this->assertSame('potongan_kebesaran_byte', $this->pemilah->alasanUkuran(1_001));
        $this->assertSame('potongan_bukan_citra', $this->pemilah->alasanUkuran(0));
        $this->assertSame('potongan_bukan_citra', $this->pemilah->alasanUkuran(-5));
    }

    public function test_batas_config_rusak_tidak_membuka_semua_atau_menolak_semua(): void
    {
        config(['ocr.awan_sel.maks_byte_potongan' => 0, 'ocr.awan_sel.maks_piksel_potongan' => -3]);

        $this->assertSame(1, $this->pemilah->maksByte());
        $this->assertSame(1, $this->pemilah->maksPiksel());
    }

    public function test_isi_jpeg_dan_png_sah_lolos_dan_selain_itu_bukan_citra(): void
    {
        $this->assertNull($this->pemilah->alasanIsi(CitraSintetis::jpegSungguhan(60, 30)));
        $this->assertNull($this->pemilah->alasanIsi(CitraSintetis::pngMengaku(60, 30)));

        foreach (['', 'GIF89a....', '%PDF-1.7', '<svg></svg>', "\xFF\xD8", 'RIFF....WEBP'] as $isi) {
            $this->assertSame('potongan_bukan_citra', $this->pemilah->alasanIsi($isi), 'Isi: '.json_encode($isi));
        }
    }

    public function test_jpeg_dengan_penanda_awal_tetapi_header_rusak_bukan_citra(): void
    {
        $this->assertSame('potongan_bukan_citra', $this->pemilah->alasanIsi("\xFF\xD8\xFF\xE0sampah tanpa header ukuran"));
    }

    public function test_batas_piksel_potongan_inklusif_dan_dibaca_dari_header(): void
    {
        $this->assertNull($this->pemilah->alasanIsi(CitraSintetis::jpegMengaku(500, 1000)));
        $this->assertSame('potongan_kebesaran_piksel', $this->pemilah->alasanIsi(CitraSintetis::jpegMengaku(500, 1001)));
        $this->assertSame('potongan_kebesaran_piksel', $this->pemilah->alasanIsi(CitraSintetis::pngMengaku(900, 900)));
        // Foto halaman utuh 12 MP: lolos pengaman global, tetapi bukan potongan sel.
        $this->assertSame('potongan_kebesaran_piksel', $this->pemilah->alasanIsi(CitraSintetis::jpegMengaku(4000, 3000)));
    }

    public function test_pengaman_citra_batas_tambahan_hanya_mengetatkan(): void
    {
        $satuMp = CitraSintetis::jpegMengaku(1000, 1000);
        $duaPuluhLimaMp = CitraSintetis::jpegMengaku(5000, 5000);

        $this->assertNull(PengamanCitra::tolak($satuMp), 'Tanpa batas tambahan, plafon global berlaku.');
        $this->assertSame(PengamanCitra::TERLALU_BESAR, PengamanCitra::tolak($satuMp, 500_000));
        $this->assertNull(PengamanCitra::tolak($satuMp, 1_000_000), 'Tepat di batas tambahan lolos.');

        // Batas tambahan yang lebih longgar daripada plafon global TIDAK melonggarkan plafon.
        $this->assertSame(PengamanCitra::TERLALU_BESAR, PengamanCitra::tolak($duaPuluhLimaMp, 100_000_000));

        // Nol atau negatif diabaikan (bukan "tolak semua" dan bukan "buka semua").
        $this->assertNull(PengamanCitra::tolak($satuMp, 0));
        $this->assertNull(PengamanCitra::tolak($satuMp, -1));
    }
}
