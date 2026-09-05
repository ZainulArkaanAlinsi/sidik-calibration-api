<?php

namespace Tests\Unit;

use App\Services\Dokumen\AmbangKeyakinan;
use App\Services\Dokumen\PembuatSkemaDinamis;
use App\Services\Dokumen\PenguraiStrukturDokumen;
use PHPUnit\Framework\TestCase;

class PembuatSkemaDinamisTest extends TestCase
{
    private function skema(array $mentah): array
    {
        $dokumen = (new PenguraiStrukturDokumen(new AmbangKeyakinan))->urai($mentah);

        return (new PembuatSkemaDinamis)->dari($dokumen);
    }

    /** Inti fitur: dua lembar beda -> dua skema beda, tanpa parser tambahan. */
    public function test_lembar_berbeda_menghasilkan_skema_berbeda(): void
    {
        $a = $this->skema(['sections' => [[
            'name' => 'Wavelength Calibration',
            'fields' => [['label' => 'Holmium', 'value' => '361,5', 'confidence' => 0.95]],
            'tables' => [['headers' => ['X1', 'X2', 'X3'], 'cells' => [
                ['row' => 0, 'column' => 0, 'value' => '1'],
            ]]],
        ]]]);

        $b = $this->skema(['sections' => [[
            'name' => 'Effect of Tare',
            'fields' => [['label' => 'Massa Standar', 'value' => '200 g', 'confidence' => 0.9]],
            'tables' => [['headers' => ['Position', 'Standard'], 'cells' => [
                ['row' => 0, 'column' => 0, 'value' => 'Center'],
            ]]],
        ]]]);

        $this->assertSame('Wavelength Calibration', $a['bagian'][0]['nama']);
        $this->assertSame('Effect of Tare', $b['bagian'][0]['nama']);

        $this->assertCount(3, $a['bagian'][0]['tabel'][0]['kolom']);
        $this->assertCount(2, $b['bagian'][0]['tabel'][0]['kolom']);

        $this->assertSame('Holmium', $a['bagian'][0]['field'][0]['label']);
        $this->assertSame('Massa Standar', $b['bagian'][0]['field'][0]['label']);
    }

    /**
     * Kunci HARUS unik walau labelnya sama persis — lembar Conductivity punya
     * empat kolom "Reading" dan empat "°C".
     */
    public function test_kunci_unik_walau_label_berulang(): void
    {
        $skema = $this->skema(['sections' => [['fields' => [
            ['label' => 'Reading', 'value' => '1'],
            ['label' => 'Reading', 'value' => '2'],
            ['label' => 'Reading', 'value' => '3'],
        ]]]]);

        $kunci = array_column($skema['bagian'][0]['field'], 'kunci');

        $this->assertCount(3, array_unique($kunci));
        $this->assertSame(['1', '2', '3'], array_column($skema['bagian'][0]['field'], 'nilai'));
    }

    public function test_kunci_sel_tabel_unik_dan_menunjuk_posisinya(): void
    {
        $skema = $this->skema(['sections' => [['tables' => [[
            'headers' => ['A', 'B'],
            'cells' => [
                ['row' => 0, 'column' => 0, 'value' => '1'],
                ['row' => 1, 'column' => 1, 'value' => '2'],
            ],
        ]]]]]);

        $semua = [];

        foreach ($skema['bagian'][0]['tabel'][0]['baris'] as $baris) {
            foreach ($baris as $sel) {
                $semua[] = $sel['kunci'];
                $this->assertStringContainsString('-'.$sel['baris'].'-'.$sel['kolom'], $sel['kunci']);
            }
        }

        $this->assertCount(4, $semua);
        $this->assertCount(4, array_unique($semua));
    }

    public function test_kolom_semua_angka_jadi_numerik(): void
    {
        $skema = $this->skema(['sections' => [['tables' => [[
            'headers' => ['Reading'],
            'cells' => [
                ['row' => 0, 'column' => 0, 'value' => '84,1'],
                ['row' => 1, 'column' => 0, 'value' => '-12'],
                ['row' => 2, 'column' => 0, 'value' => null],
            ],
        ]]]]]);

        $this->assertSame(
            PembuatSkemaDinamis::TIPE_ANGKA,
            $skema['bagian'][0]['tabel'][0]['kolom'][0]['tipe'],
            'sel kosong nggak boleh bikin kolom angka turun jadi teks',
        );
    }

    public function test_kolom_campur_dibiarkan_teks_bukan_dipaksa_angka(): void
    {
        $skema = $this->skema(['sections' => [['tables' => [[
            'headers' => ['Reading'],
            'cells' => [
                ['row' => 0, 'column' => 0, 'value' => '84,1'],
                ['row' => 1, 'column' => 0, 'value' => 'n/a'],
            ],
        ]]]]]);

        $this->assertSame(
            PembuatSkemaDinamis::TIPE_TEKS,
            $skema['bagian'][0]['tabel'][0]['kolom'][0]['tipe'],
        );
    }

    public function test_kolom_kosong_semua_tidak_diklaim_angka(): void
    {
        $skema = $this->skema(['sections' => [['tables' => [[
            'headers' => ['Reading'],
            'cells' => [['row' => 0, 'column' => 0, 'value' => null]],
        ]]]]]);

        $this->assertSame(
            PembuatSkemaDinamis::TIPE_TEKS,
            $skema['bagian'][0]['tabel'][0]['kolom'][0]['tipe'],
        );
    }

    public function test_teks_tercetak_ditandai_tidak_bisa_diisi(): void
    {
        $skema = $this->skema(['sections' => [['fields' => [
            ['label' => 'Standard Name', 'value' => 'Victor 123', 'source' => 'static_document'],
            ['label' => 'Reading', 'value' => '25,4', 'source' => 'handwriting'],
        ]]]]);

        $f = $skema['bagian'][0]['field'];

        $this->assertFalse($f[0]['bisa_diisi'], 'yang tercetak di formulir bukan isian teknisi');
        $this->assertTrue($f[1]['bisa_diisi']);
    }

    public function test_aturan_validasi_diturunkan_dari_tipe(): void
    {
        $skema = $this->skema(['sections' => [['fields' => [
            ['label' => 'Suhu', 'value' => '25,4', 'type' => 'number'],
            ['label' => 'Tanggal', 'value' => '2026-08-28', 'type' => 'date'],
            ['label' => 'TTD', 'value' => null, 'type' => 'signature'],
            ['label' => 'Lulus', 'value' => true, 'type' => 'boolean'],
        ]]]]);

        $f = $skema['bagian'][0]['field'];

        $this->assertSame(['numerik' => true], $f[0]['aturan']);
        $this->assertSame(['tanggal' => true], $f[1]['aturan']);
        $this->assertSame(['baca_saja' => true], $f[2]['aturan'], 'tanda tangan nggak boleh disuruh diketik');
        $this->assertSame(['boolean' => true], $f[3]['aturan']);
    }

    public function test_tipe_asing_tidak_dipaksa_dan_jatuh_ke_tebakan_aman(): void
    {
        $skema = $this->skema(['sections' => [['fields' => [
            ['label' => 'a', 'value' => '25,4', 'type' => 'wavelength_nm'],
            ['label' => 'b', 'value' => 'Fluke 123', 'type' => 'kode_alat'],
        ]]]]);

        $f = $skema['bagian'][0]['field'];

        $this->assertSame(PembuatSkemaDinamis::TIPE_ANGKA, $f[0]['tipe']);
        $this->assertSame(PembuatSkemaDinamis::TIPE_TEKS, $f[1]['tipe']);
    }

    public function test_nilai_koma_tidak_diubah_jadi_titik(): void
    {
        $skema = $this->skema(['sections' => [['fields' => [
            ['label' => 'Suhu', 'value' => '25,4', 'type' => 'number'],
        ]]]]);

        $this->assertSame(
            '25,4',
            $skema['bagian'][0]['field'][0]['nilai'],
            'angka harus tampil persis seperti di kertas biar bisa dibandingkan',
        );
    }

    public function test_ringkasan_menghitung_yang_perlu_dilihat_teknisi(): void
    {
        $skema = $this->skema(['sections' => [[
            'fields' => [
                ['label' => 'a', 'value' => '1', 'confidence' => 0.99],
                ['label' => 'b', 'value' => '2', 'confidence' => 0.40],
            ],
            'tables' => [['headers' => ['A', 'B'], 'cells' => [
                ['row' => 0, 'column' => 0, 'value' => '1', 'confidence' => 0.99],
                // (0,1) hilang -> sel kosong PERLU_REVIEW
            ]]],
        ]]]);

        $this->assertSame(2, $skema['ringkasan']['jumlah_field']);
        $this->assertSame(2, $skema['ringkasan']['jumlah_sel']);
        $this->assertSame(2, $skema['ringkasan']['perlu_review'], 'field b + sel yang hilang');
    }

    public function test_bbox_dibawa_sampai_skema_buat_sorot_di_gambar(): void
    {
        $skema = $this->skema(['sections' => [['tables' => [[
            'headers' => ['A'],
            'cells' => [['row' => 0, 'column' => 0, 'value' => '7,02', 'confidence' => 0.94,
                'bbox' => ['x' => 100, 'y' => 200, 'width' => 120, 'height' => 30]]],
        ]]]]]);

        $sel = $skema['bagian'][0]['tabel'][0]['baris'][0][0];

        $this->assertSame(['x' => 100.0, 'y' => 200.0, 'width' => 120.0, 'height' => 30.0], $sel['bbox']);
    }

    public function test_dokumen_kosong_tidak_meledak(): void
    {
        $skema = $this->skema([]);

        $this->assertSame([], $skema['bagian']);
        $this->assertSame(0, $skema['ringkasan']['perlu_review']);
    }
}
