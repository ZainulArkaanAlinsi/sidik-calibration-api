<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\GumCalculator;
use Tests\TestCase;

/**
 * Budget ketidakpastian Refractometer, dicocokin ke `Master Olah
 * Data_Refractometer.xlsm` (sheet `PERHITUNGAN U95%`, dua blok n20D). Angka
 * acuan DISALIN dari workbook, bukan dari keluaran kode — kalau engine-nya
 * bener, dia reproduksi persis.
 *
 * **5 komponen** per titik:
 *   1. Kemurnian larutan std : u = U95%_std ÷ 1 (!)                (vi 200)
 *   2. Daya baca alat        : u = (0,0001/2)/√3                   (vi 1e6)
 *   3. Perbedaan temperature : u = deviasi_tabel/√3                (vi 50)
 *   4. Temperature           : u = UTemp/2 ; ci = resolusi 0,0001  (vi 200)
 *   5. Pengulangan (Type A)  : u = stdev/√5                        (vi 4)
 *
 *   UTemperature = √((0,07/2)² + (0,036/2)²) = 0,03935733730830886
 *
 * DUA hal yang gampang "dibenerin" padahal justru bakal bikin meleset — sheet
 * lab emang nulis begini, dan tiga alat lain BEDA:
 *
 *  - komponen 1 divisor **1**, bukan `/k=2` kayak pH/Turbidimeter/Chlorine;
 *  - komponen 4 `ci` = **resolusi alat**, bukan koefisien fisik 0,00045/°C.
 *
 * Lihat docblock `RefractometerProfile` & `RefractometerCapabilitySeeder`.
 */
class RefractometerBudgetTest extends TestCase
{
    private GumCalculator $gum;

    private RefractometerProfile $profil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
        $this->profil = new RefractometerProfile;
    }

    private function uTemperature(): float
    {
        return sqrt((0.07 / 2) ** 2 + (0.036 / 2) ** 2);
    }

    /**
     * Suhu ruang sesi contoh: (20,9 + 21,2)/2 = 21,05 → baris tabel 21,0 →
     * deviasi n20D −0,00045. Itu yang dipakai DUA blok budget di sheet.
     */
    private const SUHU_RUANG = 21.05;

    private const DEVIASI_SUHU = -0.00045;

    /**
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(float $uStd95, float $stdev): array
    {
        return [
            // Divisor 1 — sengaja, lihat docblock kelas.
            ['u' => $uStd95, 'ci' => 1.0, 'vi' => 200],
            ['u' => (0.0001 / 2) / sqrt(3), 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => self::DEVIASI_SUHU / sqrt(3), 'ci' => 1.0, 'vi' => 50],
            ['u' => $this->uTemperature() / 2, 'ci' => 0.0001, 'vi' => 200],
            ['u' => $stdev / sqrt(5), 'ci' => 1.0, 'vi' => 4],
        ];
    }

    public function test_konstanta_kepala_sheet_reproduksi_workbook(): void
    {
        // Sel `Utemp. (oC)` di kepala sheet PERHITUNGAN U95%.
        $this->assertEqualsWithDelta(0.03935733730830886, $this->uTemperature(), 1e-15);
    }

    /**
     * "Tab Konversi Temperatur" itu linear murni — 113 baris (19,0–30,2 °C step
     * 0,1) dicek ulang di sini, bukan diseed sebagai tabel. Kalau suatu saat lab
     * bikin tabelnya beneran melengkung, test inilah yang merah duluan.
     */
    public function test_tabel_konversi_temperatur_linear_di_seluruh_rentang(): void
    {
        for ($t = 19.0; $t <= 30.2001; $t += 0.1) {
            $suhu = round($t, 1);

            $this->assertEqualsWithDelta(
                -0.00045 * ($suhu - 20),
                $this->profil->deviasiSuhu($suhu, 'n20D'),
                1e-15,
                "deviasi n20D pada {$suhu} °C",
            );

            $this->assertEqualsWithDelta(
                -0.07 * ($suhu - 20),
                $this->profil->deviasiSuhu($suhu, '°Brix'),
                1e-13,
                "deviasi °Brix pada {$suhu} °C",
            );
        }
    }

    /**
     * VLOOKUP *approximate* Excel: suhunya TURUN ke kelipatan 0,1, nggak
     * dibulatkan. 21,05 → baris 21,0, bukan 21,1.
     *
     * Kasus 21,1 itu jebakan IEEE754 yang beneran kejadian: `21.1 * 10` =
     * `210.99999999999997`, jadi `floor()` polos ngasih baris 21,0 — satu baris
     * kelewat, diam-diam.
     */
    public function test_lookup_suhu_turun_ke_kelipatan_sepersepuluh(): void
    {
        $this->assertEqualsWithDelta(-0.00045, $this->profil->deviasiSuhu(21.05, 'n20D'), 1e-15);
        $this->assertEqualsWithDelta(-0.000495, $this->profil->deviasiSuhu(21.1, 'n20D'), 1e-15);
        $this->assertEqualsWithDelta(-0.000495, $this->profil->deviasiSuhu(21.19, 'n20D'), 1e-15);
        $this->assertEqualsWithDelta(0.0, $this->profil->deviasiSuhu(20.0, 'n20D'), 1e-15);

        // Di luar rentang tabel di-clamp ke ujungnya (Excel-nya `#N/A`).
        $this->assertEqualsWithDelta(
            $this->profil->deviasiSuhu(19.0, 'n20D'),
            $this->profil->deviasiSuhu(12.0, 'n20D'),
            1e-15,
        );
        $this->assertEqualsWithDelta(
            $this->profil->deviasiSuhu(30.2, 'n20D'),
            $this->profil->deviasiSuhu(45.0, 'n20D'),
            1e-15,
        );
    }

    /**
     * Normalisasi pembacaan ke 20 °C — kolom "Corrected Value" sheet
     * `PERHITUNGAN` baris 46–47. Ini yang kecetak di sertifikat sebagai
     * "Unit Under Test", bukan pembacaan mentahnya.
     */
    public function test_pembacaan_dinormalisasi_ke_suhu_acuan(): void
    {
        $alat = new Equipment(['satuan' => 'n20D', 'resolusi' => 0.0001]);

        // 1,3362 dibaca pada rata-rata 27 °C → 1,3362 + 0,00045 × 7
        $this->assertEqualsWithDelta(
            1.33935,
            $this->profil->rataRataPadaSuhuAcuan(1.3362, 27.0, $alat),
            1e-12,
        );

        // 1,3986 dibaca pada 25 °C → 1,3986 + 0,00045 × 5
        $this->assertEqualsWithDelta(
            1.40085,
            $this->profil->rataRataPadaSuhuAcuan(1.3986, 25.0, $alat),
            1e-12,
        );

        // Tepat di suhu acuan → nggak digeser sama sekali.
        $this->assertEqualsWithDelta(
            1.3362,
            $this->profil->rataRataPadaSuhuAcuan(1.3362, 20.0, $alat),
            1e-15,
        );

        // Suhu nggak dicatat → pembacaan dipakai apa adanya, bukan ditebak.
        $this->assertSame(1.3362, $this->profil->rataRataPadaSuhuAcuan(1.3362, null, $alat));
    }

    /** Satuan °Brix pakai kemiringan 0,07/°C, bukan 0,00045/°C. */
    public function test_normalisasi_ikut_satuan_alat(): void
    {
        $brix = new Equipment(['satuan' => 'oBrix', 'resolusi' => 0.1]);

        $this->assertEqualsWithDelta(
            20.35,
            $this->profil->rataRataPadaSuhuAcuan(20.0, 25.0, $brix),
            1e-12,
        );
    }

    public function test_titik_133659_reproduksi_workbook(): void
    {
        // Pembacaan 1,3362 ×5 → STDEV 0 (sel PERHITUNGAN baris 41).
        $r = $this->gum->agregasiBudget($this->komponen(2.6e-05, 0.0));

        $this->assertEqualsWithDelta(0.00026270364640284643, $r['ketidakpastian_gabungan'], 1e-15);
        $this->assertEqualsWithDelta(52.26560349571864, $r['derajat_kebebasan_efektif'], 1e-8);
        $this->assertEqualsWithDelta(2.006646805061686, $r['faktor_cakupan_k'], 1e-9);
        $this->assertEqualsWithDelta(0.0005271534327323267, $r['ketidakpastian_diperluas'], 1e-15);

        // U hitung (5,3e-04) jauh DI ATAS CMC (6,2e-05), jadi yang dilaporkan
        // hasil hitungnya — bukan CMC. Sheet `SERTIFIKAT` baris 18 sepakat.
        $this->assertGreaterThan(6.2e-05, $r['ketidakpastian_diperluas']);
    }

    public function test_titik_139986_reproduksi_workbook(): void
    {
        $r = $this->gum->agregasiBudget($this->komponen(3.7e-05, 0.0));

        $this->assertEqualsWithDelta(0.00026401932852227275, $r['ketidakpastian_gabungan'], 1e-15);
        $this->assertEqualsWithDelta(53.316383793910745, $r['derajat_kebebasan_efektif'], 1e-8);
        $this->assertEqualsWithDelta(2.0057459953178696, $r['faktor_cakupan_k'], 1e-9);
        $this->assertEqualsWithDelta(0.0005295557108700615, $r['ketidakpastian_diperluas'], 1e-15);

        $this->assertGreaterThan(6.7e-05, $r['ketidakpastian_diperluas']);
    }

    /**
     * Profil nyusun kelima komponen persis kayak tabel acuan di atas. Test ini
     * yang njaga penyimpangan disengaja (divisor 1, ci = resolusi) nggak
     * diam-diam "dibenerin" jadi konvensi tiga alat lain.
     */
    public function test_profil_nyusun_lima_komponen_sesuai_sheet(): void
    {
        $kemampuan = new CalibrationCapability([
            'nama_alat' => 'Refractometer',
            'ketidakpastian_terbaik' => 6.2e-05,
            'u_temperature' => $this->uTemperature(),
        ]);
        $alat = new Equipment(['satuan' => 'n20D', 'resolusi' => 0.0001]);
        $standard = new Standard([
            'nama' => 'Refractometer Std Solution 1.33659 n20D',
            'ketidakpastian' => 2.6e-05,
            'faktor_cakupan' => 2,
        ]);

        $komponen = $this->profil->komponenBudget(
            $kemampuan, $alat, $standard, 1.33659, 0.0, 5, self::SUHU_RUANG,
        );

        $this->assertNotNull($komponen);
        $this->assertCount(5, $komponen);
        $this->assertSame(
            [
                'ketidakpastian_standar',
                'resolusi_alat',
                'perbedaan_suhu',
                'ketidakpastian_temperature',
                'pengulangan_pembacaan',
            ],
            array_column($komponen, 'sumber'),
        );

        $per = array_column($komponen, null, 'sumber');

        // Divisor 1: u SAMA PERSIS sama U95% sertifikat larutannya, nggak dibagi 2.
        $this->assertEqualsWithDelta(2.6e-05, $per['ketidakpastian_standar']['u'], 1e-18);
        $this->assertSame(1.0, $per['ketidakpastian_standar']['ci']);

        // ci komponen suhu = resolusi alat, bukan 0,00045.
        $this->assertEqualsWithDelta(0.0001, $per['ketidakpastian_temperature']['ci'], 1e-18);
        $this->assertEqualsWithDelta(
            $this->uTemperature() / 2,
            $per['ketidakpastian_temperature']['u'],
            1e-15,
        );

        $this->assertEqualsWithDelta(
            self::DEVIASI_SUHU / sqrt(3),
            $per['perbedaan_suhu']['u'],
            1e-18,
        );

        // Agregasinya mesti ngasih angka yang sama kayak tabel acuan manual.
        $agg = $this->gum->agregasiBudget(array_map(
            fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        ));
        $this->assertEqualsWithDelta(0.0005271534327323267, $agg['ketidakpastian_diperluas'], 1e-15);
    }

    /**
     * Tanpa suhu ruang, komponen "Perbedaan Temperature" nggak bisa dihitung —
     * profil balik null dan pemanggil jatuh ke jalur CMC. Ngarang 0 di situ
     * bakal ngasih U yang terlalu kecil, dan itu klaim yang nggak kebukti.
     */
    public function test_tanpa_suhu_ruang_balik_null_biar_jatuh_ke_cmc(): void
    {
        $kemampuan = new CalibrationCapability([
            'nama_alat' => 'Refractometer',
            'ketidakpastian_terbaik' => 6.2e-05,
            'u_temperature' => $this->uTemperature(),
        ]);
        $alat = new Equipment(['satuan' => 'n20D', 'resolusi' => 0.0001]);
        $standard = new Standard(['nama' => 'std', 'ketidakpastian' => 2.6e-05, 'faktor_cakupan' => 2]);

        $this->assertNull($this->profil->komponenBudget(
            $kemampuan, $alat, $standard, 1.33659, 0.0, 5, null,
        ));
    }

    /**
     * Titik °Brix belum punya budget penuh (blok sheet-nya `#REF!`), jadi
     * `u_temperature`-nya null dan profil balik null → jalur CMC. Disengaja;
     * lihat `RefractometerCapabilitySeeder`.
     */
    public function test_titik_brix_belum_punya_budget_penuh(): void
    {
        $kemampuan = new CalibrationCapability([
            'nama_alat' => 'Refractometer',
            'ketidakpastian_terbaik' => 0.019,
            'u_temperature' => null,
        ]);
        $alat = new Equipment(['satuan' => '°Brix', 'resolusi' => 0.1]);
        $standard = new Standard(['nama' => 'std', 'ketidakpastian' => 0.018, 'faktor_cakupan' => 2]);

        $this->assertNull($this->profil->komponenBudget(
            $kemampuan, $alat, $standard, 2.5, 0.0, 5, self::SUHU_RUANG,
        ));
    }
}
