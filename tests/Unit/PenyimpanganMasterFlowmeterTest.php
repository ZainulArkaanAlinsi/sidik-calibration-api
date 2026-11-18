<?php

namespace Tests\Unit;

use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\Profiles\FlowmeterProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Support\PenyimpanganMaster;
use Tests\TestCase;

/**
 * PK-9 / PK5-7: tiga perbaikan Flowmeter terhadap master yang belum terbaca di jejak sesi —
 * lantai CMC dipasang, `Ut-water` dihitung, dan (Totalizer) rentang densitas titik 2-3.
 *
 * Masukannya dua sesi contoh master (`FlowmeterMasterTest`). Angka pembandingnya dikunci ke dokumen:
 * `U_temperature` master 0,2780288 °C lawan sistem 0,2795234 °C; Flowrate titik 2 U95 1,047 %OR (master)
 * lawan 1,2 %OR (lantai CMC); Totalizer titik 2 deviasi -18,907204 L lawan -18,890667 L.
 * Angka hasil hitung tidak disentuh: kalkulator cuma MENAMBAH dua method pembanding, dan
 * `FlowmeterMasterTest`/`FlowmeterLantaiCmcTest` tetap penjaga angkanya.
 */
class PenyimpanganMasterFlowmeterTest extends TestCase
{
    private const PIPA = ['diameter_pipa_mm' => [50.81, 50.82, 50.81], 'ketebalan_pipa_mm' => [2.32, 2.31, 2.32]];

    /** @return list<array<string, mixed>> */
    private function titikTotalizer(): array
    {
        return [
            ['titik_ke' => 1, 'uut' => [[1002.65], [1004.56], [1006.52]], 'std' => [1010.885, 1011.52, 1012.215],
                'suhu_awal' => [25.5, 25.5, 25.5], 'suhu_akhir' => [25.4, 25.4, 25.4], 'densitas_uut' => []],
            ['titik_ke' => 2, 'uut' => [[1901.37], [1902.66], [1904.27]], 'std' => [1901.654, 1903.158, 1904.998],
                'suhu_awal' => [25.4, 25.4, 25.4], 'suhu_akhir' => [25.4, 25.4, 25.4], 'densitas_uut' => []],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function titikFlowrate(): array
    {
        return [
            ['titik_ke' => 1, 'uut' => [[101.255, 101.276, 101.289], [102.654, 102.625, 102.678], [101.986, 101.910, 101.945]],
                'std' => [101.998, 101.897, 101.123], 'suhu_awal' => [24.5, 24.5, 24.5], 'suhu_akhir' => [24.5, 24.6, 24.6], 'densitas_uut' => []],
            ['titik_ke' => 2, 'uut' => [[309.785, 309.776, 309.779], [311.376, 311.374, 311.398], [310.772, 310.778, 310.745]],
                'std' => [308.711, 310.255, 310.251], 'suhu_awal' => [24.6, 24.6, 24.6], 'suhu_akhir' => [24.6, 24.6, 24.6], 'densitas_uut' => []],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: float, 2: FlowmeterProfile}
     */
    private function sesi(string $mode): array
    {
        $totalizer = $mode === TabelStandarFlowmeter::MODE_TOTALIZER;
        $titik = $totalizer ? $this->titikTotalizer() : $this->titikFlowrate();
        $kalk = new FlowmeterCalculator;
        $hasil = $kalk->hitungSesi($titik, [
            'mode' => $mode,
            'satuan' => $totalizer ? 'L' : 'LPM',
            'resolusi' => $totalizer ? 0.01 : 0.001,
        ] + self::PIPA);

        return [$hasil, $kalk->utWaterSesi($titik), $totalizer ? new FlowmeterTotalizerProfile : new FlowmeterFlowrateProfile];
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return array<string, array<string, mixed>> butir => baris
     */
    private function catatan(FlowmeterProfile $profil, array $hasil, int $titikKe, float $utWater): array
    {
        $h = collect($hasil['titik'])->firstWhere('titik_ke', $titikKe);
        $baris = PenyimpanganMaster::dari($profil->catatanPenyimpanganMaster($hasil, $h, $utWater));

        return array_column($baris, null, 'sumber');
    }

    public function test_ut_water_sesi_sama_dengan_komponen_u_temperature(): void
    {
        foreach ([TabelStandarFlowmeter::MODE_TOTALIZER, TabelStandarFlowmeter::MODE_FLOWRATE] as $mode) {
            [$hasil, $utWater] = $this->sesi($mode);

            // Sebaran suhu air kedua sesi contoh 0,1 °C -> Ut_water = 0,1 / (2 akar 3) = 0,0288675.
            $this->assertEqualsWithDelta(0.1 / (2 * sqrt(3.0)), $utWater, 1e-9, $mode);
            // U_temperature master (Ut-water = 0) = akar(U_temperature_sistem^2 - Ut_water^2).
            $this->assertEqualsWithDelta(0.2780287754891569, sqrt($hasil['u_temperature'] ** 2 - $utWater ** 2), 1e-9, $mode);
        }
    }

    public function test_ut_water_dicatat_dengan_u_temperature_master_dan_sistem_dari_dokumen(): void
    {
        foreach ([TabelStandarFlowmeter::MODE_TOTALIZER, TabelStandarFlowmeter::MODE_FLOWRATE] as $mode) {
            [$hasil, $utWater, $profil] = $this->sesi($mode);
            $c = $this->catatan($profil, $hasil, 1, $utWater)['penyimpangan_master.flowmeter.ut_water_sel_kosong'] ?? null;

            $this->assertNotNull($c, "{$mode}: catatan Ut-water wajib ada");
            $a = $c['penyimpangan']['angka'];
            $this->assertEqualsWithDelta(0.2780288, $a['u_temperature_master'], 5e-7);
            $this->assertEqualsWithDelta(0.2795234, $a['u_temperature_sistem'], 5e-7);
            $this->assertGreaterThanOrEqual($a['u95_hitung_master'], $a['u95_hitung_sistem'], 'Ut-water membesarkan U.');
            $this->assertStringContainsString('I24', $c['keterangan']);
            $this->assertStringContainsString('PK5-7', $c['keterangan']);
        }
    }

    public function test_ut_water_tidak_dicatat_bila_suhu_air_seragam(): void
    {
        $titik = $this->titikTotalizer();
        $titik[0]['suhu_awal'] = $titik[0]['suhu_akhir'] = $titik[1]['suhu_awal'] = $titik[1]['suhu_akhir'] = [25.4, 25.4, 25.4];
        $kalk = new FlowmeterCalculator;
        $hasil = $kalk->hitungSesi($titik, ['mode' => TabelStandarFlowmeter::MODE_TOTALIZER, 'satuan' => 'L', 'resolusi' => 0.01] + self::PIPA);

        $this->assertSame(0.0, $kalk->utWaterSesi($titik));
        $this->assertArrayNotHasKey(
            'penyimpangan_master.flowmeter.ut_water_sel_kosong',
            $this->catatan(new FlowmeterTotalizerProfile, $hasil, 1, 0.0),
        );
    }

    public function test_lantai_cmc_dicatat_hanya_di_titik_yang_lantainya_menang(): void
    {
        [$hasil, $utWater, $profil] = $this->sesi(TabelStandarFlowmeter::MODE_FLOWRATE);

        // Flowrate titik 2: U95 master 3,2512388 Lpm (1,047 %OR) di bawah pita 1,2 % -> lantai 3,7277107 Lpm.
        $t2 = $this->catatan($profil, $hasil, 2, $utWater)['penyimpangan_master.flowmeter.lantai_cmc_dipasang'] ?? null;
        $this->assertNotNull($t2);
        $this->assertEqualsWithDelta(3.7277107, $t2['penyimpangan']['angka']['u95_sistem'], 5e-6);
        $this->assertEqualsWithDelta(3.2512388, $t2['penyimpangan']['angka']['u95_master'], 5e-3);
        $this->assertStringContainsString('1,0466', $t2['penyimpangan']['master']);
        $this->assertStringContainsString('1,2 %OR', $t2['penyimpangan']['sistem']);
        $this->assertSame(PenyimpanganMaster::ARAH_KONSERVATIF, $t2['penyimpangan']['arah']);
        $this->assertStringContainsString('MAX(J60:K61)', $t2['keterangan']);

        // Titik lain sudah di atas lantainya: tidak ada catatan lantai.
        foreach ([1] as $ke) {
            $this->assertArrayNotHasKey(
                'penyimpangan_master.flowmeter.lantai_cmc_dipasang',
                $this->catatan($profil, $hasil, $ke, $utWater),
                "Flowrate titik {$ke}",
            );
        }

        [$hasilT, $utT, $profilT] = $this->sesi(TabelStandarFlowmeter::MODE_TOTALIZER);

        foreach ([1, 2] as $ke) {
            $this->assertArrayNotHasKey('penyimpangan_master.flowmeter.lantai_cmc_dipasang', $this->catatan($profilT, $hasilT, $ke, $utT));
        }
    }

    public function test_densitas_totalizer_hanya_titik_2_dan_3_dan_tidak_di_flowrate(): void
    {
        [$hasil, $utWater, $profil] = $this->sesi(TabelStandarFlowmeter::MODE_TOTALIZER);
        $kunci = 'penyimpangan_master.flowmeter.totalizer_rentang_densitas_melenceng';

        $this->assertArrayNotHasKey($kunci, $this->catatan($profil, $hasil, 1, $utWater));

        $c = $this->catatan($profil, $hasil, 2, $utWater)[$kunci] ?? null;
        $this->assertNotNull($c);
        // Dokumen: master -18,907204 L, sistem -18,890667 L (Standard Indication 1883,86 -> 1883,88).
        $this->assertEqualsWithDelta(-18.907204, $c['penyimpangan']['angka']['deviasi_master_sesi_contoh'], 1e-6);
        $this->assertEqualsWithDelta(-18.890667, $c['penyimpangan']['angka']['deviasi_sistem_sesi_contoh'], 1e-6);
        $t2 = collect($hasil['titik'])->firstWhere('titik_ke', 2);
        $this->assertEqualsWithDelta(-18.890667, $t2['deviasi'], 1e-6, 'Angka contoh di catatan = hitungan sistem sesi ini.');
        $this->assertStringContainsString('H44:K46', $c['keterangan']);

        [$hasilF, $utF, $profilF] = $this->sesi(TabelStandarFlowmeter::MODE_FLOWRATE);

        foreach ([1, 2] as $ke) {
            $this->assertArrayNotHasKey($kunci, $this->catatan($profilF, $hasilF, $ke, $utF), 'Workbook Flowrate tidak punya cacat ini.');
        }
    }
}
