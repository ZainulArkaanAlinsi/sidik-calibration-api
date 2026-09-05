<?php

namespace Tests\Unit;

use App\Services\Dokumen\AmbangKeyakinan;
use App\Services\Dokumen\PenguraiStrukturDokumen;
use PHPUnit\Framework\TestCase;

/**
 * Yang diuji di sini bukan "pengurainya jalan", tapi janji-janji yang kalau
 * dilanggar bikin angka mendarat di baris yang salah tanpa ada yang tahu.
 */
class PenguraiStrukturDokumenTest extends TestCase
{
    private function urai(array $mentah): array
    {
        return (new PenguraiStrukturDokumen(new AmbangKeyakinan))->urai($mentah);
    }

    /**
     * INI yang paling penting. Model melewatkan sel (1,1). Sel (1,2) HARUS
     * tetap di kolom 2 — bukan naik ke kolom 1.
     */
    public function test_sel_yang_hilang_tidak_menggeser_sel_sesudahnya(): void
    {
        $hasil = $this->urai(['sections' => [[
            'name' => 'Before adjustment',
            'tables' => [[
                'headers' => ['Reading', 'C', 'Reading'],
                'cells' => [
                    ['row' => 1, 'column' => 0, 'value' => '84,1', 'confidence' => 0.97],
                    // kolom 1 sengaja HILANG
                    ['row' => 1, 'column' => 2, 'value' => '1413', 'confidence' => 0.95],
                ],
            ]],
        ]]]);

        $baris = $hasil['sections'][0]['tables'][0]['rows'][1];

        $this->assertSame('84,1', $baris[0]['value']);
        $this->assertNull($baris[1]['value'], 'sel hilang harus KOSONG, bukan diisi tetangganya');
        $this->assertSame(AmbangKeyakinan::PERLU_REVIEW, $baris[1]['status']);
        $this->assertSame('1413', $baris[2]['value'], 'sel sesudahnya TIDAK boleh bergeser');

        // Baris 0 nggak dilaporkan sama sekali, tapi tetap ada supaya indeks
        // baris di UI sama dengan indeks baris di kertas.
        $this->assertCount(2, $hasil['sections'][0]['tables'][0]['rows']);
        $this->assertSame(0, $hasil['sections'][0]['tables'][0]['rows'][0][0]['row']);
    }

    public function test_indeks_eksplisit_menang_atas_posisi_di_array(): void
    {
        $hasil = $this->urai(['sections' => [['tables' => [[
            'rows' => [[
                ['column' => 0, 'value' => 'a'],
                ['column' => 3, 'value' => 'd'],
            ]],
        ]]]]]);

        $baris = $hasil['sections'][0]['tables'][0]['rows'][0];

        $this->assertCount(4, $baris);
        $this->assertSame('a', $baris[0]['value']);
        $this->assertNull($baris[1]['value']);
        $this->assertNull($baris[2]['value']);
        $this->assertSame('d', $baris[3]['value']);
    }

    public function test_kolom_di_luar_jumlah_judul_tidak_dipotong(): void
    {
        $hasil = $this->urai(['sections' => [['tables' => [[
            'headers' => ['A', 'B'],
            'cells' => [['row' => 0, 'column' => 4, 'value' => '9']],
        ]]]]]);

        $baris = $hasil['sections'][0]['tables'][0]['rows'][0];

        $this->assertCount(5, $baris, 'data di kolom 4 berarti tabelnya 5 kolom');
        $this->assertSame('9', $baris[4]['value']);
    }

    public function test_dua_bentuk_tabel_sama_sama_terbaca(): void
    {
        $bersarang = $this->urai(['sections' => [['tables' => [[
            'rows' => [[['value' => '7,02']]],
        ]]]]]);

        $bernomor = $this->urai(['sections' => [['tables' => [[
            'cells' => [['row' => 0, 'column' => 0, 'value' => '7,02']],
        ]]]]]);

        $this->assertSame(
            $bersarang['sections'][0]['tables'][0]['rows'],
            $bernomor['sections'][0]['tables'][0]['rows'],
        );
    }

    public function test_keyakinan_rendah_tetap_membawa_nilainya(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'Temperature', 'value' => '25,4', 'confidence' => 0.96],
            ['label' => 'Humidity', 'value' => '5?', 'confidence' => 0.43],
        ]]]]);

        $f = $hasil['sections'][0]['fields'];

        $this->assertSame(AmbangKeyakinan::OK, $f[0]['status']);
        $this->assertSame(AmbangKeyakinan::TINGGI, $f[0]['confidence_level']);

        $this->assertSame('5?', $f[1]['value'], 'tebakan rendah tetap dibawa buat dikoreksi');
        $this->assertSame(AmbangKeyakinan::PERLU_REVIEW, $f[1]['status']);
        $this->assertSame(AmbangKeyakinan::RENDAH, $f[1]['confidence_level']);
    }

    public function test_tanpa_keyakinan_dianggap_perlu_review_bukan_ok(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'Serial', 'value' => 'ABC-1'],
        ]]]]);

        $f = $hasil['sections'][0]['fields'][0];

        $this->assertSame(AmbangKeyakinan::TIDAK_DIKETAHUI, $f['confidence_level']);
        $this->assertSame(AmbangKeyakinan::PERLU_REVIEW, $f['status']);
    }

    public function test_persen_dinormalkan_jadi_pecahan(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'x', 'value' => '1', 'confidence' => 94],
        ]]]]);

        $this->assertSame(0.94, $hasil['sections'][0]['fields'][0]['confidence']);
    }

    public function test_sumber_asing_jadi_unknown_bukan_ditebak(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'a', 'value' => '1', 'source' => 'handwriting'],
            ['label' => 'b', 'value' => '2', 'source' => 'printed'],
            ['label' => 'c', 'value' => '3'],
        ]]]]);

        $f = $hasil['sections'][0]['fields'];

        $this->assertSame(PenguraiStrukturDokumen::SUMBER_TULISAN, $f[0]['source']);
        $this->assertSame(PenguraiStrukturDokumen::SUMBER_TIDAK_DIKETAHUI, $f[1]['source']);
        $this->assertSame(PenguraiStrukturDokumen::SUMBER_TIDAK_DIKETAHUI, $f[2]['source']);
    }

    public function test_bbox_separuh_dibuang_utuh(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'a', 'value' => '1', 'bbox' => ['x' => 10, 'y' => 20, 'width' => 5, 'height' => 3]],
            ['label' => 'b', 'value' => '2', 'bbox' => ['x' => 10, 'y' => 20]],
        ]]]]);

        $f = $hasil['sections'][0]['fields'];

        $this->assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 5.0, 'height' => 3.0], $f[0]['bbox']);
        $this->assertNull($f[1]['bbox'], 'kotak separuh nggak bisa dipakai menyorot apa pun');
    }

    public function test_nama_bagian_mengikuti_dokumen_apa_adanya(): void
    {
        $hasil = $this->urai(['sections' => [
            ['name' => 'Wavelength Calibration'],
            ['name' => 'Effect of Tare'],
        ]]);

        $this->assertSame('Wavelength Calibration', $hasil['sections'][0]['name']);
        $this->assertSame('Effect of Tare', $hasil['sections'][1]['name']);
    }

    public function test_peringatan_model_dan_pengurai_digabung(): void
    {
        $hasil = $this->urai([
            'warnings' => ['Halaman 2 buram'],
            'sections' => [['tables' => [[
                'cells' => [
                    ['row' => 0, 'column' => 0, 'value' => 'a'],
                    ['row' => 0, 'column' => 0, 'value' => 'b'],
                    ['value' => 'tanpa nomor'],
                ],
            ]]]],
        ]);

        $this->assertContains('Halaman 2 buram', $hasil['warnings']);
        $this->assertNotEmpty(array_filter($hasil['warnings'], fn ($p) => str_contains($p, 'ganda')));
        $this->assertNotEmpty(array_filter($hasil['warnings'], fn ($p) => str_contains($p, 'tanpa nomor')));

        // Yang pertama menang; yang kedua nggak diam-diam menimpa.
        $this->assertSame('a', $hasil['sections'][0]['tables'][0]['rows'][0][0]['value']);
    }

    public function test_bool_dan_angka_tidak_dipaksa_jadi_string(): void
    {
        $hasil = $this->urai(['sections' => [['fields' => [
            ['label' => 'Checkbox', 'value' => true, 'type' => 'boolean'],
            ['label' => 'Reading', 'value' => 7.02, 'type' => 'number'],
        ]]]]);

        $f = $hasil['sections'][0]['fields'];

        $this->assertTrue($f[0]['value']);
        $this->assertSame(7.02, $f[1]['value']);
    }

    public function test_masukan_sampah_tidak_bikin_meledak(): void
    {
        foreach ([[], ['sections' => 'bukan array'], ['sections' => [null, 5]]] as $sampah) {
            $hasil = $this->urai($sampah);

            $this->assertIsArray($hasil['sections']);
            $this->assertIsArray($hasil['warnings']);
        }
    }
}
