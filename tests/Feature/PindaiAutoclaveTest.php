<?php

namespace Tests\Feature;

use App\Services\Ocr\TemplateLembarKerja;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga lembar Autoklaf di pipeline pindai.
 *
 * Sebelum ini template Autoklaf isinya NOL sel. Bukan karena koordinatnya
 * belum diukur — `TemplateLembarKerja` cuma paham bentuk "baris × pengulangan ×
 * kolom-field", dan Autoklaf nggak punya bentuk itu sama sekali. Akibatnya
 * berantai: rangka geometri kosong, lembar cetak tanpa satu pun kotak, dan
 * kamera yang nggak akan pernah bisa dinyalakan seberapa pun telitinya lab
 * mengukur kertasnya.
 *
 * Dua hal yang dijaga di sini:
 *
 *  1. **Urutan barisnya.** Di kertas baris ke-6 itu `Indikator Pressure`.
 *     Kalau urutan di template geser, angka tekanan mendarat di baris suhu —
 *     dan itu lolos semua penjagaan lain, karena satuannya nggak ikut difoto.
 *  2. **Baris `Time` bukan angka.** `02:00:00` yang dipaksa lewat jalur angka
 *     keluar sebagai `20000`, dan angka itu lolos karena sel jam nggak punya
 *     rentang buat menolaknya.
 */
class PindaiAutoclaveTest extends TestCase
{
    // Bentuk lembar kerjanya menautkan daftar standar dari DB.
    use RefreshDatabase;

    private ValidasiSel $validasi;

    /** @var array<string, mixed> */
    private array $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validasi = app(ValidasiSel::class);
        $this->template = app(TemplateLembarKerja::class)->untukKode('autoclave') ?? [];
    }

    /**
     * Delapan baris kertas × lima titik waktu. Angka 40 itu yang dipakai
     * `ocr:rangka-geometri` buat nggambar grid dan `ocr:cetak-lembar` buat
     * nggambar kotaknya — nol berarti kertasnya kecetak tanpa tabel.
     */
    public function test_template_punya_sel_dan_urutan_barisnya_ikut_kertas(): void
    {
        $this->assertCount(40, $this->template['sel']);

        $this->assertSame(
            [
                'Time',
                'Temp. Disk 1',
                'Temp. Disk 2',
                'Temp. Disk 3',
                'Indikator Suhu',
                'Indikator Pressure',
                'Tekanan atm awal',
                'Suhu Ruang',
            ],
            array_column($this->template['tabel'][0]['baris'], 'label'),
        );

        $this->assertSame(
            [1, 2, 3, 4, 5],
            $this->template['tabel'][0]['pengulangan'],
        );
    }

    public function test_jam_kebaca_utuh_bukan_dipaksa_jadi_angka(): void
    {
        $hasil = $this->periksa(1, '02:00:00');

        $this->assertSame('02:00:00', $hasil['teks_normal']);
        // Yang penting BUKAN cuma teksnya benar: `nilai` wajib null. Jam yang
        // kesimpen sebagai 20000 di kolom desimal nggak bisa dibedain lagi dari
        // pembacaan alat.
        $this->assertNull($hasil['nilai']);
        $this->assertSame(ValidasiSel::KUNING, $hasil['status']);
    }

    /**
     * Jam tanpa detik ("4:00") diterima APA ADANYA. Dulu "dilengkapi" jadi
     * "04:00:00" (nol dan detik ":00" ditambahkan mesin); dicabut 23 Nov 2026:
     * hasil OCR tidak boleh diubah agar cocok dengan pola, dan format kertas
     * Autoklaf (empat kelompok) belum dipastikan pemilik lab. Selnya kuning,
     * dikonfirmasi mata teknisi.
     */
    public function test_jam_tanpa_detik_tidak_dilengkapi(): void
    {
        $hasil = $this->periksa(1, '4:00');

        $this->assertSame('4:00', $hasil['teks_normal']);
        $this->assertNull($hasil['nilai']);
        $this->assertSame(ValidasiSel::KUNING, $hasil['status']);
    }

    /**
     * Titik dua tulisan tangan sering tipis dan hilang waktu difoto. Dulu mesin
     * menyisipkannya ("020000" jadi "02:00:00", alasan `pemisah_jam_disisipkan`);
     * dicabut 23 Nov 2026. Sekarang teksnya dikembalikan apa adanya, tanpa
     * sisipan, dan selnya kuning: `0200` bisa 02:00, bisa juga 20:0x yang satu
     * digitnya kepotong, dan yang memutuskan mata teknisi, bukan mesin.
     */
    public function test_pemisah_jam_yang_ilang_tidak_disisipkan_dan_selnya_kuning(): void
    {
        $hasil = $this->periksa(1, '020000');

        $this->assertSame('020000', $hasil['teks_normal']);
        $this->assertNotContains('pemisah_jam_disisipkan', $hasil['alasan']);
        $this->assertSame([], $hasil['normalisasi']);
        $this->assertSame(ValidasiSel::KUNING, $hasil['status']);
    }

    /**
     * Empat kelompok seperti yang dicetak kertas Rev.4 (`____:____:____:____`)
     * diterima apa adanya; artinya belum diketahui, jadi tidak ada batas dan
     * tidak ada penafsiran. Selnya kuning.
     */
    public function test_empat_kelompok_seperti_kertas_diterima_apa_adanya(): void
    {
        $hasil = $this->periksa(1, '00:02:00:00');

        $this->assertSame('00:02:00:00', $hasil['teks_normal']);
        $this->assertSame(ValidasiSel::KUNING, $hasil['status']);
    }

    /**
     * Huruf dan pemisah selain `:` TIDAK diganti. Dulu `O`→0, `l`→1, `S`→5 dan
     * `. ; - / spasi` diubah menjadi `:`.
     */
    #[DataProvider('jamTidakDiubah')]
    public function test_jam_berhuruf_atau_berpemisah_lain_ditolak_bukan_diubah(string $teks): void
    {
        $hasil = $this->periksa(1, $teks);

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertNull($hasil['teks_normal']);
        $this->assertSame($teks, $hasil['teks_mentah'], 'teks mentah harus tersimpan apa adanya');
        $this->assertContains('karakter_di_luar_whitelist', $hasil['alasan']);
        $this->assertNotContains('ada_koreksi_karakter', $hasil['alasan']);
    }

    /** @return array<string, array{string}> */
    public static function jamTidakDiubah(): array
    {
        return [
            'huruf O' => ['02:O0:00'], 'huruf l' => ['l2:00:00'], 'huruf S' => ['0S:00:00'], 'huruf B' => ['B:00:00'],
            'titik sebagai pemisah' => ['02.00.00'], 'strip' => ['02-00-00'], 'titik koma' => ['02;00;00'],
            'garis miring' => ['02/00/00'], 'spasi di tengah' => ['02 00 00'], 'garis tegak' => ['02|00|00'],
        ];
    }

    #[DataProvider('jamBentukTakWajar')]
    public function test_jam_bentuk_tak_wajar_ditolak(string $teks): void
    {
        $hasil = $this->periksa(1, $teks);

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertNull($hasil['teks_normal']);
        $this->assertContains('bentuk_jam_tidak_wajar', $hasil['alasan']);
    }

    /** @return array<string, array{string}> */
    public static function jamBentukTakWajar(): array
    {
        return [
            'kelompok kosong' => ['02::00'], 'awalan titik dua' => [':02:00'], 'akhiran titik dua' => ['02:00:'],
            'kelompok tiga digit' => ['123:45:00'], 'lima kelompok' => ['1:2:3:4:5'], 'sembilan digit tanpa pemisah' => ['123456789'],
        ];
    }

    /**
     * Masukan acak (seed tetap): yang DITERIMA harus persis teks masukannya
     * (hanya digit dan `:`), tidak pernah diubah; yang lain ditolak dengan alasan.
     */
    public function test_masukan_acak_jam_tidak_pernah_diubah(): void
    {
        mt_srand(20261123);
        $alfabet = array_merge(
            str_split('0123456789'), str_split('0123456789'), [':', ':', ':', '.', ';', '-', '/', ' ', '|'],
            str_split('OolIDSB'),
        );

        $diterima = 0;
        $ditolak = 0;

        for ($i = 0; $i < 3000; $i++) {
            $teks = '';
            for ($j = mt_rand(0, 10); $j > 0; $j--) {
                $teks .= $alfabet[mt_rand(0, count($alfabet) - 1)];
            }

            $hasil = $this->periksa(1, $teks);
            $pangkas = trim($teks);

            if ($hasil['status'] === ValidasiSel::KOSONG) {
                $this->assertSame('', $pangkas);

                continue;
            }

            if ($hasil['status'] === ValidasiSel::MERAH) {
                $ditolak++;
                $this->assertNull($hasil['teks_normal'], "`{$teks}` ditolak tapi punya teks_normal");
                $this->assertNotSame([], $hasil['alasan'], "`{$teks}` merah tanpa alasan");

                continue;
            }

            $diterima++;
            $this->assertSame($pangkas, $hasil['teks_normal'], "`{$teks}` berubah menjadi `{$hasil['teks_normal']}`");
            $this->assertMatchesRegularExpression('/^[0-9:]+$/', $pangkas);
            $this->assertNotContains('ada_koreksi_karakter', $hasil['alasan']);
            $this->assertNotSame(ValidasiSel::HIJAU, $hasil['status'], 'tulisan tangan tidak pernah hijau');
        }

        $this->assertGreaterThan(20, $diterima);
        $this->assertGreaterThan(1000, $ditolak);
    }

    #[DataProvider('jamMustahil')]
    public function test_jam_mustahil_ditolak(string $teks): void
    {
        $hasil = $this->periksa(1, $teks);

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertNull($hasil['nilai']);
    }

    /** @return list<array{string}> */
    public static function jamMustahil(): array
    {
        return [
            'jam lewat 23' => ['52:00:00'],
            'menit lewat 59' => ['02:70:00'],
            'detik lewat 59' => ['02:00:75'],
            'bukan jam sama sekali' => ['121.27'],
        ];
    }

    /**
     * Rentang suhunya dari jangkauan tabel kalibrator (`config/autoclave.php`
     * berhenti di 21 °C & 140 °C). Di luar itu koreksinya nggak ada, jadi
     * angkanya nggak bisa dipakai walau kebaca benar.
     */
    public function test_suhu_disk_di_luar_jangkauan_kalibrator_ditolak(): void
    {
        $this->assertSame(ValidasiSel::KUNING, $this->periksa(2, '121.27')['status']);

        $hasil = $this->periksa(2, '1212.7');

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertContains('di_luar_rentang', $hasil['alasan']);
    }

    /**
     * Baris tekanan SENGAJA tanpa rentang: satuannya beda-beda per autoklaf
     * (MPa, bar, psi). Rentang apa pun yang dipatok bakal nolak angka yang benar
     * begitu ketemu alat bersatuan lain — `0,112 MPa` dan `1,12 bar` itu tekanan
     * yang sama.
     */
    #[DataProvider('tekananSah')]
    public function test_bacaan_tekanan_apa_pun_satuannya_diterima(string $teks): void
    {
        $hasil = $this->periksa(6, $teks);

        $this->assertNotSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertNotContains('di_luar_rentang', $hasil['alasan']);
    }

    /** @return list<array{string}> */
    public static function tekananSah(): array
    {
        return [
            'MPa' => ['0.112'],
            'bar' => ['1.12'],
            'psi' => ['16.24'],
        ];
    }

    /**
     * Suhu ruang punya rentangnya sendiri (5–45 °C). Kalau dia ikut rentang
     * disk, `121` di baris Suhu Ruang — salah salin satu baris — bakal lolos.
     */
    public function test_suhu_ruang_nggak_ikut_rentang_disk(): void
    {
        $hasil = $this->periksa(8, '121');

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertContains('di_luar_rentang', $hasil['alasan']);
    }

    /**
     * Autoklaf belum boleh dipindai: koordinat selnya belum diadu ke lembar
     * cetak yang beneran difoto. Test ini yang bakal merah kalau ada yang
     * menyetel `terverifikasi` tanpa bukti itu.
     */
    public function test_belum_siap_dipindai_sampai_geometrinya_diverifikasi(): void
    {
        $this->assertFalse($this->template['siap_pindai']);
        $this->assertSame('geometri_belum_diverifikasi', $this->template['alasan_belum_siap']);
    }

    /**
     * @return array<string, mixed>
     */
    private function periksa(int $baris, string $teks): array
    {
        return $this->validasi->periksa(
            $this->template['sel']["sesudah_adjustment|{$baris}|1|pembacaan"],
            [
                'teks_mentah' => $teks,
                'confidence_ocr' => 0.95,
                'kotak_teks_di_dalam_sel' => true,
            ],
        );
    }
}
