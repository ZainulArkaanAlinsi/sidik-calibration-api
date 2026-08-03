<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Budget ketidakpastian Turbidimeter, dicocokin ke `Master Olah Data_Turbidimeter
 * .xlsm` (sheet `PERHITUNGAN U95%`). Angka acuan DISALIN dari workbook, bukan
 * dari keluaran kode — kalau engine-nya bener, dia reproduksi persis.
 *
 * 4 komponen per titik (beda dari pH yang 5 — nggak ada "pengaruh perbedaan suhu"):
 *   1. Sertifikat kalibrator : u = U95%_std / 2                    (vi 200)
 *   2. Daya baca alat        : u = (resolusi_titik/2)/√3           (vi 1e6)
 *   3. Temperature           : u = UTemperature/√3 ; ci = (UTemp/400)·titik (vi 200)
 *   4. Pengulangan (Type A)  : u = stdev/√5                        (vi 4)
 *
 *   UTemperature = √((0.72/2)² + (0.06/2)²) = 0.36124783736376886
 *
 * Nilai acuan per titik (sel AB16/AB18/AB19 & Uncertainty sertifikat):
 *   titik   uc            k             U hitung      Uncert. sertifikat (max CMC)
 *     1     0.0206002139  1.9714346585  0.0406119757  0.041
 *   100     1.5005292834  1.9718962236  2.9588880273  3.1
 *  1000    10.5085114560  1.9718962236 20.7216940561  22
 */
class TurbidimeterBudgetTest extends TestCase
{
    use RefreshDatabase;

    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
    }

    private function uTemperature(): float
    {
        return sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);
    }

    /**
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(float $uStd95, float $resolusi, float $titik, float $stdev): array
    {
        $sqrt3 = sqrt(3);
        $uTemp = $this->uTemperature();

        return [
            ['u' => $uStd95 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => ($resolusi / 2) / $sqrt3, 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $uTemp / $sqrt3, 'ci' => ($uTemp / 400) * $titik, 'vi' => 200],
            ['u' => $stdev / sqrt(5), 'ci' => 1.0, 'vi' => 4],
        ];
    }

    public function test_titik_1ntu_reproduksi_workbook(): void
    {
        // stdev [1,1,1,1,1.02] = 0.008944271909999159
        $r = $this->gum->agregasiBudget($this->komponen(0.04, 0.01, 1.0, 0.008944271909999159));

        $this->assertEqualsWithDelta(0.020600213907162067, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta(1.9714346585202402, $r['faktor_cakupan_k'], 1e-6);
        $this->assertEqualsWithDelta(0.040611975669509956, $r['ketidakpastian_diperluas'], 1e-8);
    }

    public function test_titik_100ntu_reproduksi_workbook(): void
    {
        // stdev [100,100,100,100,100.1] = 0.044721359549995794
        $r = $this->gum->agregasiBudget($this->komponen(3.0, 0.1, 100.0, 0.044721359549995794));

        $this->assertEqualsWithDelta(1.5005292833558208, $r['ketidakpastian_gabungan'], 1e-8);
        $this->assertEqualsWithDelta(2.9588880273014397, $r['ketidakpastian_diperluas'], 1e-7);
    }

    public function test_titik_1000ntu_reproduksi_workbook(): void
    {
        // stdev [1000,1000,1001,1001,1001] = 0.5477225575051662
        $r = $this->gum->agregasiBudget($this->komponen(21.0, 1.0, 1000.0, 0.5477225575051662));

        $this->assertEqualsWithDelta(10.508511455997626, $r['ketidakpastian_gabungan'], 1e-7);
        $this->assertEqualsWithDelta(1.9718962236339095, $r['faktor_cakupan_k'], 1e-6);
        $this->assertEqualsWithDelta(20.721694056095394, $r['ketidakpastian_diperluas'], 1e-6);
    }

    public function test_profil_ngasih_koefisien_suhu_turunan_titik(): void
    {
        // ci = (UTemperature/400)·titik — bukan konstanta seed kayak pH.
        // Titik 1000: (0.36124783736/400)·1000 = 0.9031195934 (sel W41).
        $profil = new TurbidimeterProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => $this->uTemperature()]);
        $standard = new Standard(['nama' => 'Turbidity 1000', 'ketidakpastian' => 21.0, 'faktor_cakupan' => 2]);
        $equipment = new Equipment(['satuan' => 'NTU']);

        $komponen = $profil->komponenBudget($kemampuan, $equipment, $standard, 1000.0, 0.5477225575051662, 5);

        $suhu = collect($komponen)->firstWhere('sumber', 'ketidakpastian_temperature');
        $this->assertEqualsWithDelta(0.9031195934094222, $suhu['ci'], 1e-9);
        // resolusi titik 1000 = 1 (bukan 0.01 titik 1) → u = (1/2)/√3.
        $resolusi = collect($komponen)->firstWhere('sumber', 'resolusi_alat');
        $this->assertEqualsWithDelta((1.0 / 2) / sqrt(3), $resolusi['u'], 1e-12);
    }

    public function test_hitung_titik_lewat_profil_dilantai_ke_cmc(): void
    {
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::create(['organization_id' => $org->id, 'kode' => 'instrumen-analitik', 'nama' => 'Instrumen Analitik']);

        foreach ([[1, 0.041], [100, 3.1], [1000, 22]] as [$titik, $cmc]) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Turbidimeter',
                'parameter' => 'Turbidity',
                'range_min' => $titik,
                'range_max' => $titik,
                'satuan' => 'NTU',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'NTU',
                'faktor_cakupan' => 2,
                'metode' => 'SIDIK-IK-CAL-0507',
                'u_temperature' => $this->uTemperature(),
            ]);
        }

        $equipment = new Equipment([
            'nama_alat' => 'Turbidimeter',
            'nama_alat_kemampuan' => 'Turbidimeter',
            'satuan' => 'NTU',
            'resolusi' => 0.01,
            'toleransi' => 24,
        ]);
        $equipment->equipment_category_id = $kategori->id;

        $std1000 = new Standard(['nama' => 'Turbidity 1000', 'ketidakpastian' => 21.0, 'satuan_ketidakpastian' => 'NTU', 'faktor_cakupan' => 2]);

        $hasil = $this->gum->hitungTitik(3, 1000.0, [1000, 1000, 1001, 1001, 1001], $equipment, $std1000);

        // U hitung 20.72 < CMC 22 → yang DILAPORKAN CMC-nya (aturan max, ILAC).
        $this->assertEqualsWithDelta(22.0, $hasil['ketidakpastian_diperluas'], 1e-6);
        // Guarded acceptance: |error| 0.6 + U 22 = 22.6 <= toleransi 24 → PASS.
        $this->assertSame('PASS', $hasil['keputusan']);
        $this->assertSame('SIDIK-IK-CAL-0507', $hasil['metode']);
    }

    public function test_titik_pecahan_di_bawah_satu_tetap_nemu_cmc(): void
    {
        // Regresi: titik 0,04 NTU dulu dibulatin jadi 0 sama kemampuanUntukTitik,
        // jadi CMC-nya nggak ketemu & U95-nya jatuh ke jalur generik (nggak
        // dilantai CMC). Sekarang dicocokin ke nilai titiknya langsung.
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::create(['organization_id' => $org->id, 'kode' => 'instrumen-analitik', 'nama' => 'Instrumen Analitik']);
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Turbidimeter',
            'parameter' => 'Turbidity',
            'range_min' => 0.04,
            'range_max' => 0.04,
            'satuan' => 'NTU',
            'ketidakpastian_terbaik' => 0.02,
            'satuan_ketidakpastian' => 'NTU',
            'faktor_cakupan' => 2,
            'metode' => 'SIDIK-IK-CAL-0523',
            'u_temperature' => $this->uTemperature(),
        ]);

        $equipment = new Equipment([
            'nama_alat' => 'Turbidimeter',
            'nama_alat_kemampuan' => 'Turbidimeter',
            'satuan' => 'NTU', 'resolusi' => 0.01, 'toleransi' => 50,
        ]);
        $equipment->equipment_category_id = $kategori->id;

        $std = new Standard(['nama' => 'Turbidity 0.04', 'ketidakpastian' => 0.01, 'satuan_ketidakpastian' => 'NTU', 'faktor_cakupan' => 2]);

        $hasil = $this->gum->hitungTitik(1, 0.04, [0.04, 0.04, 0.05, 0.04, 0.04], $equipment, $std);

        // U hitung kecil (< 0.02) → dilantai ke CMC 0.02, bukan lolos jalur generik.
        $this->assertEqualsWithDelta(0.02, $hasil['ketidakpastian_diperluas'], 1e-9);
    }
}
