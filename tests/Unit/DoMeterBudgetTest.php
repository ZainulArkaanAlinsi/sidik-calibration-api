<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\DoMeterProfile;
use App\Services\GumCalculator;
use Tests\TestCase;

/**
 * Budget ketidakpastian DO Meter (alat ke-9), dicocokin ke `Master Olah Data_DO
 * Meter.xlsm` (sheet `PERHITUNGAN U95%`, titik 8,77 mg/L). Angka acuan DISALIN
 * dari workbook, bukan dari keluaran kode — kalau engine-nya bener, dia
 * reproduksi persis.
 *
 * **5 komponen** per titik, struktur persis Chlorin Meter:
 *   1. Sertifikat kalibrator : u = U95%_std / 2                          (vi 200)
 *   2. Daya baca alat        : u = (0,01/2)/√3                           (vi 1e6)
 *   3. Temperature           : u = UTemp/2 ; ci = (UTemp/400)·titik      (vi 200)
 *   4. Faktor pengenceran    : u = Uc_peng(L)/2 ; ci = (Uc_peng_ml/100)·titik (vi 200)
 *   5. Pengulangan (Type A)  : u = stdev/√5                              (vi 4)
 *
 *   UTemperature   = √((0,72/2)² + (0,06/2)²)          = 0,36124783736376886
 *   Uc pengenceran = √((0,89/2/1000)² + (0,033/2)²)    = 0,01650599966678783 (ml)
 *
 * Beda dari Chlorine: komponen pengenceran ci-nya BISA diturunkan dari sheet
 * (`(Uc_ml/100)·titik`), bukan konstanta yang nggak ketebak.
 */
class DoMeterBudgetTest extends TestCase
{
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

    public function test_konstanta_kepala_sheet_reproduksi_workbook(): void
    {
        $this->assertEqualsWithDelta(0.36124783736376886, $this->uTemperature(), 1e-15);
        $this->assertEqualsWithDelta(0.01650599966678783, DoMeterProfile::ucPengenceran(), 1e-17);
    }

    /**
     * Titik 8,77 diadu KOMPONEN-DEMI-KOMPONEN & agregat ke sheet.
     *
     * stdev [8,82 ×3, 8,83 ×2] = 0,005477225575051544 (sel PERHITUNGAN G44).
     */
    public function test_titik_877_reproduksi_workbook(): void
    {
        $komponen = $this->komponen(0.005477225575051544);
        $r = $this->gum->agregasiBudget($komponen);

        // Sel "Ketidakpastian Baku Gabungan, Uc" — cocok sampai digit terakhir.
        $this->assertEqualsWithDelta(0.07510912040209242, $r['ketidakpastian_gabungan'], 1e-14);
        // Sel "Derajat Kebebasan, v eff".
        $this->assertEqualsWithDelta(201.15502343260252, $r['derajat_kebebasan_efektif'], 1e-6);
        // Sel "Factor Cakupan, k" (TINV). Engine motong v_eff ke 201 sebelum
        // nyari t; di df setinggi ini bedanya di bawah 1e-4 dari TINV Excel.
        $this->assertEqualsWithDelta(1.9718365067798587, $r['faktor_cakupan_k'], 1e-4);
        // Sel "Ketidakpastian Bentangan, U = k·Uc".
        $this->assertEqualsWithDelta(0.14810290560096973, $r['ketidakpastian_diperluas'], 1e-6);
    }

    public function test_komponen_pengenceran_ci_turunan_titik(): void
    {
        $profil = new DoMeterProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => $this->uTemperature()]);
        $standard = new Standard(['nama' => 'Oxygen', 'ketidakpastian' => 0.15, 'faktor_cakupan' => 2]);
        $equipment = new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]);

        $komponen = $profil->komponenBudget($kemampuan, $equipment, $standard, 8.77, 0.002, 5);

        $this->assertCount(5, $komponen);
        $this->assertSame([
            'ketidakpastian_standar',
            'resolusi_alat',
            'ketidakpastian_temperature',
            'faktor_pengenceran',
            'pengulangan_pembacaan',
        ], array_column($komponen, 'sumber'));

        $suhu = collect($komponen)->firstWhere('sumber', 'ketidakpastian_temperature');
        // ci suhu = (UTemp/400)·8,77 — sel W25.
        $this->assertEqualsWithDelta(0.007920358834200633, $suhu['ci'], 1e-15);
        $this->assertEqualsWithDelta($this->uTemperature() / 2, $suhu['u'], 1e-15);

        $peng = collect($komponen)->firstWhere('sumber', 'faktor_pengenceran');
        // ci pengenceran = (Uc_ml/100)·8,77 — sel W26.
        $this->assertEqualsWithDelta(0.0014475761707772926, $peng['ci'], 1e-15);
        // u pengenceran = Uc_L/2 = (Uc_ml/1000)/2 — sel T26. Sama persis
        // angkanya kayak Chlorine (micropipette & labu ukur unit yang sama).
        $this->assertEqualsWithDelta(8.252999833393915e-06, $peng['u'], 1e-18);
    }

    public function test_tanpa_u_temperature_jatuh_ke_jalur_cmc_bukan_ngarang(): void
    {
        $profil = new DoMeterProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => null]);
        $standard = new Standard(['nama' => 'Oxygen', 'ketidakpastian' => 0.15, 'faktor_cakupan' => 2]);
        $equipment = new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]);

        $this->assertNull(
            $profil->komponenBudget($kemampuan, $equipment, $standard, 8.77, 0.002, 5),
        );
    }

    /**
     * Titiknya 8,77 — BUKAN 0,00 yang tercetak di form Rev.2. "Zero Oxygen Std.
     * 0.0" itu larutan penol alat, bukan titik kalibrasi; yang terakreditasi
     * 8,77 (satu-satunya titik yang punya CMC di DATABASE). Kalau ada yang
     * "mbenerin" ini ngikut kertasnya, test ini yang teriak — persis kayak
     * kasus Chlorine 0,4/4.
     */
    public function test_titik_ikut_master_bukan_yang_tercetak_nol(): void
    {
        $nilai = array_column(DoMeterProfile::TITIK, 'nilai');

        $this->assertSame([8.77], $nilai);
        $this->assertNotContains(0.0, $nilai);
    }

    /**
     * DO Meter NGGAK divonis PASS/FAIL — master nggak punya MPE, sertifikat
     * nggak nyetak vonis. `punyaToleransi()` wajib false; kalau ada yang
     * ngebalikin true, sesi bakal butuh toleransi yang nggak pernah ada dan
     * ngarang PASS.
     */
    public function test_do_meter_nggak_divonis(): void
    {
        $this->assertFalse((new DoMeterProfile)->punyaToleransi());
    }

    /**
     * `k` di sertifikat dicetak TANPA desimal.
     *
     * Master nyimpen 1,9718365067798587 di `SERTIFIKAT!V26` tapi selnya
     * diformat `0`, jadi yang kecetak `2`. Nilai cocok ≠ bentuk cocok — dan
     * selisih bentuk cuma ketahuan waktu ada yang mbandingin dua sertifikat
     * berdampingan, bukan waktu ngecek angkanya.
     *
     * Blok hasilnya sendiri (`E24`/`N24`/`S24`/`Q25`) diformat `0.00`, dan itu
     * ditangani jalur `desimal` biasa — 2 desimal, sama kayak bawaan.
     */
    public function test_k_dicetak_tanpa_desimal_ikut_format_sel_master(): void
    {
        $this->assertSame(0, (new DoMeterProfile)->desimalFaktorCakupan());
    }

    public function test_registry_nemu_profil_dari_nama_dan_kode(): void
    {
        $registry = new CalibrationProfileRegistry;

        $this->assertInstanceOf(DoMeterProfile::class, $registry->untukNamaAlat('DO Meter'));
        $this->assertInstanceOf(DoMeterProfile::class, $registry->untukKode('do_meter'));
    }

    /**
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(float $stdev): array
    {
        $uTemp = $this->uTemperature();
        $ucMl = DoMeterProfile::ucPengenceran();
        $titik = 8.77;

        return [
            ['u' => 0.15 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => (0.01 / 2) / sqrt(3), 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $uTemp / 2, 'ci' => ($uTemp / 400) * $titik, 'vi' => 200],
            ['u' => ($ucMl / 1000) / 2, 'ci' => ($ucMl / 100) * $titik, 'vi' => 200],
            ['u' => $stdev / sqrt(5), 'ci' => 1.0, 'vi' => 4],
        ];
    }
}
