<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarSieve;
use PHPUnit\Framework\TestCase;

/**
 * Tiga sel Tabel_MPE master menyimpang dari ASTM E11, dan dibetulkan saat
 * tabelnya dibaca (jawaban lab §14.9, 16 Sep 2026).
 *
 * Yang paling berbahaya baris 80 µm: Ø kawat preferred tertulis 0,56 mm —
 * kawat tujuh kali lebih tebal dari lubangnya. Angka itu dipakai sebagai
 * nominal pembanding Ø kawat, jadi deviasi yang tercetak meleset 0,5 mm pada
 * sieve yang lubangnya sendiri cuma 0,08 mm.
 */
class SieveMpeAstmTest extends TestCase
{
    public function test_tiga_sel_dibetulkan_ke_astm_e11(): void
    {
        $tabel = new TabelStandarSieve;

        $delapanPuluhMikron = $tabel->barisMpe(0.08, 'mm');
        $this->assertNotNull($delapanPuluhMikron);
        $this->assertEqualsWithDelta(0.056, (float) $delapanPuluhMikron['kawat_preferred_mm'], 1e-12);
        $this->assertSame(0.56, $delapanPuluhMikron['dikoreksi_astm']['kawat_preferred_mm']);

        $satuMm = $tabel->barisMpe(1.0, 'mm');
        $this->assertNotNull($satuMm);
        $this->assertEqualsWithDelta(1000.0, (float) $satuMm['ukuran_um'], 1e-12, 'Master menyalin 18000 dari baris 18 mm.');

        $sepuluhMm = $tabel->barisMpe(10.0, 'mm');
        $this->assertNotNull($sepuluhMm);
        $this->assertEqualsWithDelta(0.394, (float) $sepuluhMm['ukuran_inch'], 1e-12, '10 mm = 0,394 inch; 0,279 itu kolom y.');
    }

    /**
     * Baris LAIN tidak ikut tersentuh — koreksinya dipatok ke ukuran DAN nilai
     * master, jadi sieve 1,12 mm yang juga ber-`kawat_preferred` 0,56 mm (dan
     * di situ memang benar) tetap apa adanya.
     */
    public function test_baris_lain_tidak_ikut_dikoreksi(): void
    {
        $baris = (new TabelStandarSieve)->barisMpe(1.12, 'mm');

        $this->assertNotNull($baris);
        $this->assertEqualsWithDelta(0.56, (float) $baris['kawat_preferred_mm'], 1e-12);
        $this->assertArrayNotHasKey('dikoreksi_astm', $baris);
    }

    /** Sieve 1 mm tetap ketemu lewat satuan µm — kolom yang dibetulkan itu tampilan. */
    public function test_pencarian_per_satuan_tetap_jalan(): void
    {
        $tabel = new TabelStandarSieve;

        $this->assertNotNull($tabel->barisMpe(1000.0, 'µm'));
        $this->assertNotNull($tabel->barisMpe(0.394, 'inch'), 'Baris 10 mm sekarang kebaca lewat inch yang benar.');
    }
}
