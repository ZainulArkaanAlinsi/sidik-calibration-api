<?php

namespace Tests\Unit;

use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use App\Services\GumCalculator;
use Tests\TestCase;

/**
 * Budget ketidakpastian Volumetric diadu ke `PERHITUNGAN_U95%` kedua workbook.
 *
 * Agregasinya lewat `GumCalculator::agregasiBudget()` — mesin yang sama dengan
 * alat lain, bukan tiruan kedua.
 */
class VolumetricGlasswareBudgetTest extends TestCase
{
    /** Masukan budget Fixed, contoh Pipet Volume 1 mL (kolom D & catatan J3..J8). */
    private function masukanFixed(): array
    {
        return [
            'massa' => 0.9997000000000001,
            'rho_udara' => 0.001101287184161609,
            'rho_air' => 0.9964251596993278,
            'suhu_air' => 27.32502900705911,
            'gamma' => 1.5e-05,
            'u_massa' => 5.7735026918962585e-05,        // C20
            'u_suhu' => 0.36221540552549664,            // H25
            'u_meniskus' => 0.0008670325000000002,      // B32
            'u_rho_air' => 5e-05,                       // 0,05/1000
            'u_keterulangan' => 0.00010045589341722838 / sqrt(3),   // N47/√3
            'tanda_ci_muai' => 1,
        ];
    }

    /** Masukan budget Graduated, contoh Gelas Ukur 10/50/100 mL. */
    private function masukanGraduated(float $h55): array
    {
        return [
            'massa' => 99.23039999999999,               // R38 = MAX massa rata-rata
            'rho_udara' => 0.0011008519333332652,
            'rho_air' => 0.9968847850530534,            // R85 = MAX densitas
            'suhu_air' => 25.680584562614666,           // Z49 = rata-rata gabungan
            'gamma' => 1.5e-05,
            'u_massa' => 0.00095,                       // C20 = U95 neraca / 2
            'u_suhu' => 0.37242448899072145,            // H25
            'u_meniskus' => 0.2886751345948129,         // B28 = resolusi / 2√3
            'u_rho_air' => 5e-08,                       // angka mati di master
            'u_keterulangan' => $h55 / sqrt(3),
            'tanda_ci_muai' => -1,
        ];
    }

    private function agregasi(array $komponen): array
    {
        return app(GumCalculator::class)->agregasiBudget($komponen);
    }

    public function test_fixed_koefisien_sensitivitas_cocok_dengan_master(): void
    {
        $ciMaster = [
            1.0044485425325806, 0.8825394640041497, -0.8825394640041497,
            1.7499429021467064e-05, -0.00011034323539704559, 1.004257551205218, 1.0, 1.0,
        ];

        foreach (V::komponenBudget($this->masukanFixed()) as $i => $k) {
            $this->assertEqualsWithDelta($ciMaster[$i], $k['ci'], 1e-12, "ci {$k['nama']} meleset dari master.");
        }
    }

    /**
     * `uc` Fixed cocok persis. `Veff`-nya SENGAJA tidak.
     *
     * Master membagi dengan baris terakhir (`K43` = 11.846,5); rumus yang
     * benar membagi dengan jumlahnya (53,12). Aturan AGENTS.md untuk kerusakan
     * salin-tempel: hitungan kita harus lebih BESAR, bukan sekadar berbeda.
     */
    public function test_fixed_uc_cocok_dan_u_lebih_besar_dari_master(): void
    {
        $hasil = $this->agregasi(V::komponenBudget($this->masukanFixed()));

        $this->assertEqualsWithDelta(0.000508808998503895, $hasil['ketidakpastian_gabungan'], 1e-15);
        $this->assertEqualsWithDelta(53.11949372298425, $hasil['derajat_kebebasan_efektif'], 1e-9);

        $uMaster = 0.0009973492160271296;
        $this->assertGreaterThan(
            $uMaster, $hasil['ketidakpastian_diperluas'],
            'U Fixed tidak lebih besar dari master — Veff kembali dibagi satu baris?',
        );
    }

    /**
     * Graduated cocok SAMPAI U — workbook ini sudah membagi Veff dengan SUM.
     *
     * Diadu dengan keterulangan milik master (lima nol hantu ikut) supaya
     * angkanya sama dengan yang tercetak; test berikutnya memakai yang benar.
     */
    public function test_graduated_budget_cocok_persis_dengan_master(): void
    {
        $komponen = V::komponenBudget($this->masukanGraduated(0.03537981705907074));

        $ciMaster = [
            1.004009301452632, 87.51647212548828, -87.51647212548828,
            0.001735550568607743, -0.008489923438928546, -99.63673451030418, 1.0, 1.0,
        ];
        foreach ($komponen as $i => $k) {
            $this->assertEqualsWithDelta($ciMaster[$i], $k['ci'], 1e-10, "ci {$k['nama']} meleset dari master.");
        }

        $hasil = $this->agregasi($komponen);
        $this->assertEqualsWithDelta(0.16801586265445564, $hasil['ketidakpastian_gabungan'], 1e-14);
        $this->assertEqualsWithDelta(51.34909756723706, $hasil['derajat_kebebasan_efektif'], 1e-9);
        $this->assertEqualsWithDelta(2.007583770315835, $hasil['faktor_cakupan_k'], 1e-9);
        $this->assertEqualsWithDelta(0.33730591902069956, $hasil['ketidakpastian_diperluas'], 1e-9);
    }

    /**
     * Keterulangan tanpa lima nol hantu (keputusan pemilik proyek 21 Sep).
     *
     * U turun sedikit, tapi di contoh ini tetap tercetak 0,34 — sama dengan CMC.
     */
    public function test_graduated_tanpa_nol_hantu(): void
    {
        $benar = $this->agregasi(V::komponenBudget($this->masukanGraduated(0.03339744467165117)));

        $this->assertLessThan(0.33730591902069956, $benar['ketidakpastian_diperluas']);
        $this->assertSame('0.34', number_format($benar['ketidakpastian_diperluas'], 2));
    }

    public function test_meniskus_kedua_keluarga_cocok_dengan_master(): void
    {
        $this->assertEqualsWithDelta(0.0008670325000000002, V::meniskusFixed(4.7), 1e-15);
        $this->assertEqualsWithDelta(0.2886751345948129, V::meniskusGraduated(1.0), 1e-15);
    }
}
