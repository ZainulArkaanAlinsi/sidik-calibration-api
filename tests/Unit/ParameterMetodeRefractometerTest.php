<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\GumCalculator;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter `refractometer.sumber_deviasi_suhu` (butir TM refractometer#24).
 *
 * Angka acuan di bawah DISALIN dari cache sel `Master Olah Data_Refractometer.xlsm`
 * (dibuka dengan sandi dari pemilik; bukan keluaran kode):
 *
 *   'Tab Konversi Temperatur'!B24 = 21 (baris 24 = 21,0 C)
 *   'Tab Konversi Temperatur'!H24 = F24-G24 = -0,00045000000000006146  (n20D)
 *   'Tab Konversi Temperatur'!E24 = C24-D24 = -0,07000000000000028     (oBrix)
 *   'PERHITUNGAN U95%'!N11 dan N26 menunjuk sel tetap itu (bukan suhu ruang)
 *   'PERHITUNGAN U95%'!AC15 = 0,00026270364640284643 (uc), AC20 = 0,0005271534327323267 (U)
 *
 * Toleransi bukan dipilih agar lulus: selisih -0,00045 lawan H24 hanya galat
 * pengurangan double dua bilangan ~1,3 (ulp 2,2e-16, jadi 4 ulp = 9e-16 < 1e-15);
 * untuk oBrix bilangannya ~20 (ulp 3,6e-15, 1e-13 menampung beberapa ulp).
 */
class ParameterMetodeRefractometerTest extends TestCase
{
    use PenetapanParameterUji;

    private const H24_N20D = -0.00045000000000006146;

    private const E24_BRIX = -0.07000000000000028;

    private const UC_EXCEL = 0.00026270364640284643;

    private const U_EXCEL = 0.0005271534327323267;

    private const KUNCI = 'refractometer.sumber_deviasi_suhu';

    private RefractometerProfile $profil;

    protected function setUp(): void
    {
        parent::setUp();
        ParameterKeputusanMetode::lupakan();
        $this->profil = new RefractometerProfile;
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    /** @return array{0: CalibrationCapability, 1: Equipment, 2: Standard} */
    private function bahan(): array
    {
        return [
            new CalibrationCapability(['nama_alat' => 'Refractometer', 'ketidakpastian_terbaik' => 6.2e-05, 'u_temperature' => sqrt((0.07 / 2) ** 2 + (0.036 / 2) ** 2)]),
            new Equipment(['satuan' => 'n20D', 'resolusi' => 0.0001]),
            new Standard(['nama' => 'Refractometer Std Solution 1.33659 n20D', 'ketidakpastian' => 2.6e-05, 'faktor_cakupan' => 2]),
        ];
    }

    /** @return array<string, float> */
    private function agregat(float $suhuRuang): array
    {
        [$kem, $alat, $std] = $this->bahan();
        $komponen = $this->profil->komponenBudget($kem, $alat, $std, 1.33659, 0.0, 5, $suhuRuang);

        return (new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        ));
    }

    public function test_baris_tetap_cocok_sel_excel_di_semua_suhu_ruang(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'baris_tetap_21c');
        $gerbang = app(ParameterKeputusanMetode::class);

        $gerbang->untukTanggal('2026-12-05', function (): void {
            foreach ([12.0, 19.0, 20.0, 21.05, 23.0, 26.4, 30.2, 35.0] as $suhu) {
                $this->assertEqualsWithDelta(self::H24_N20D, $this->profil->deviasiSuhu($suhu, 'n20D'), 1e-15, "n20D @ {$suhu} C harus sel H24");
                $this->assertEqualsWithDelta(self::E24_BRIX, $this->profil->deviasiSuhu($suhu, '°Brix'), 1e-13, "oBrix @ {$suhu} C harus sel E24");
            }
        });
    }

    public function test_u95_cocok_workbook_pada_suhu_ruang_di_luar_21_0_sampai_21_1(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'baris_tetap_21c');

        // Suhu ruang 23,0 C: bawaan memberi deviasi -0,00135; workbook tetap -0,00045.
        $agregat = app(ParameterKeputusanMetode::class)->untukTanggal('2026-12-05', fn (): array => $this->agregat(23.0));

        $this->assertEqualsWithDelta(self::UC_EXCEL, $agregat['ketidakpastian_gabungan'], 1e-15);
        $this->assertEqualsWithDelta(self::U_EXCEL, $agregat['ketidakpastian_diperluas'], 1e-15);
    }

    public function test_bawaan_pada_23_c_memberi_u_sekitar_tiga_kali_workbook(): void
    {
        // Hitung tangan, tanpa memanggil profil: kelima komponen sheet dengan deviasi -0,00135.
        $u = [2.6e-05 * 1.0, (0.0001 / 2) / sqrt(3), (-0.00135) / sqrt(3), (sqrt((0.07 / 2) ** 2 + (0.036 / 2) ** 2) / 2) * 0.0001, 0.0];
        $uc = sqrt(array_sum(array_map(static fn (float $x): float => $x ** 2, $u)));

        $agregat = $this->agregat(23.0);

        $this->assertEqualsWithDelta($uc, $agregat['ketidakpastian_gabungan'], 1e-15);
        $this->assertGreaterThan(2.9 * self::U_EXCEL, $agregat['ketidakpastian_diperluas']);
        $this->assertLessThan(3.1 * self::U_EXCEL, $agregat['ketidakpastian_diperluas']);
    }

    public function test_sesi_lama_tidak_bergeser_saat_penetapan_baru_ditambahkan(): void
    {
        $sebelum = $this->agregat(23.0);

        $this->pakaiPenetapan(self::KUNCI, 'baris_tetap_21c', '2026-12-01');
        $gerbang = app(ParameterKeputusanMetode::class);

        // Sesi bertanggal sebelum berlaku_mulai: angkanya sama persis dengan sebelum penetapan.
        $lama = $gerbang->untukTanggal('2026-11-30', fn (): array => $this->agregat(23.0));
        $this->assertSame($sebelum, $lama);

        // Tanpa lingkup tanggal: bawaan juga (tidak pernah "penetapan terbaru").
        $this->assertSame($sebelum, $this->agregat(23.0));

        // Sesi baru: berubah.
        $baru = $gerbang->untukTanggal('2026-12-01', fn (): array => $this->agregat(23.0));
        $this->assertNotSame($sebelum['ketidakpastian_diperluas'], $baru['ketidakpastian_diperluas']);
    }

    public function test_teks_keterangan_komponen_menyebut_baris_tetap(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'baris_tetap_21c');
        [$kem, $alat, $std] = $this->bahan();

        $komponen = app(ParameterKeputusanMetode::class)->untukTanggal(
            '2026-12-05',
            fn (): ?array => $this->profil->komponenBudget($kem, $alat, $std, 1.33659, 0.0, 5, 23.0),
        );

        $suhu = array_column($komponen, null, 'sumber')['perbedaan_suhu'];
        $this->assertStringContainsString('baris tetap 21,0', $suhu['keterangan']);
        $this->assertStringContainsString('tidak dipakai', $suhu['keterangan']);
    }
}
