<?php

namespace Tests\Unit;

use App\Services\Calibration\EnclosureCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden test Enclosure lawan kedua master:
 * `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm` (sesi 0123-CAL-524,
 * Incubator-02, Yokogawa + Type N, 4 set point 15/35/75/100 °C) dan
 * `Master Olah Data_Suhu_Enclosure_Recorder.xlsm` (sesi 0304-CAL-624, Oven,
 * Recorder GL840 + Type K, 3 set point @ 67 °C).
 *
 * Fixture `tests/Fixtures/enclosure-golden.json` berisi grid mentah 9 termokopel
 * × 5 pembacaan + Indikator tiap set point, plus sel master yang diadu:
 *
 *   besaran                    sel master
 *   sebaran terkoreksi/sensor  `PERHITUNGAN FC` Z##/AC## (AVG Terkoreksi)
 *   Uc                         `PERHITUNGAN U95%` AC## (Ketidakpastian Gabungan)
 *   v_eff                      AC## (Derajat Kebebasan)
 *   U dilaporkan               AC## (Bentangan Yang Dilaporkan, lantai CMC)
 *
 * ## Dua set point yang master-nya sendiri salah sel (tetap diuji)
 *
 *  - **Yokogawa SP3 (75 °C):** sel `O75` (komponen "Temperature Kalibrator")
 *    keisi rumus DRIFT (`VLOOKUP(...Tabel_Drift...)` → 0,035) alih-alih U95
 *    kalibrator (0,24). Uc master jadi 0,6234; kalkulator ini menghitung benar
 *    0,6346. Yang tercetak di sertifikat SAMA (lantai CMC 1,4 menang di
 *    dua-duanya), jadi kalkulator SETIA ke sertifikat yang terbit sambil tidak
 *    menyalin bug sel-nya. Uc & v_eff-nya TIDAK diadu ke master di sini.
 *  - **Recorder SP3 (67 °C):** sel `v_eff` (`AC76`) menghasilkan 1620 padahal
 *    komponennya identik dengan SP1/SP2 (`Uc` sama persis 0,5954). Selisih murni
 *    di rumus `v_eff` SP3. `v_eff`-nya TIDAK diadu; `Uc` & U dilaporkan tetap.
 *
 * Keduanya masuk `docs/pertanyaan-lab-enclosure.md`.
 *
 * ## Kenapa `k` bertoleransi longgar
 *
 * Master cari `k` lewat polinomial Excel (`1.95996 + 2.37356/v + …`), repo ini
 * lewat `StudentTDistribution`. Pada `v_eff` besar (ratusan–ribuan) selisihnya
 * ~10⁻⁶–10⁻⁵ — di bawah presisi cetak, tapi di atas presisi double. Yang diuji
 * ketat: sebaran, `Uc`, `v_eff`. Yang longgar: `k`, `U`.
 *
 * @see EnclosureCalculator
 * @see docs/pertanyaan-lab-enclosure.md
 */
class EnclosureBudgetTest extends TestCase
{
    private const TOLERANSI = 1e-9;

    private const TOLERANSI_K = 5e-4;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /**
     * @return array<string, array{string, int, bool, bool, bool}> [workbook, index, uji_uc, uji_veff, uji_dist]
     */
    public static function setpoints(): array
    {
        return [
            'yokogawa SP1 (15 °C)' => ['yokogawa', 0, true, true, true],
            'yokogawa SP2 (35 °C)' => ['yokogawa', 1, true, true, true],
            // SP3: sel O75 master salah rumus (budget) — sebaran-nya sendiri BENAR,
            // jadi Uc & v_eff tidak diadu tapi sebaran tetap.
            'yokogawa SP3 (75 °C, budget master salah)' => ['yokogawa', 2, false, false, true],
            'yokogawa SP4 (100 °C)' => ['yokogawa', 3, true, true, true],
            'recorder SP1 (67 °C)' => ['recorder', 0, true, true, true],
            'recorder SP2 (67 °C)' => ['recorder', 1, true, true, true],
            // SP3: seluruh blok SP3 master rusak — koreksi sensor jatuh 0 (harusnya
            // −0,08) DAN v_eff salah rumus. Cuma U dilaporkan (1,5) & Uc yang diadu.
            'recorder SP3 (67 °C, blok master salah)' => ['recorder', 2, true, false, false],
        ];
    }

    #[DataProvider('setpoints')]
    public function test_set_point_cocok_master(string $workbook, int $index, bool $ujiUc, bool $ujiVeff, bool $ujiDist): void
    {
        $fix = self::fixture()[$workbook];
        $sp = $fix['setpoints'][$index];
        $hasil = $this->hitung($fix, $sp);
        $e = $sp['expected'];

        // Sebaran suhu — AVG Terkoreksi tiap sensor (yang tercetak di sertifikat).
        if ($ujiDist) {
            foreach ($sp['dist_terkoreksi'] as $i => $harap) {
                $this->assertEqualsWithDelta(
                    $harap,
                    $hasil['sensor'][$i]['rata_rata_terkoreksi'],
                    self::TOLERANSI,
                    "AVG Terkoreksi sensor ke-{$i} meleset",
                );
            }
        }

        if ($ujiUc) {
            $this->assertEqualsWithDelta($e['Uc'], $hasil['ketidakpastian_gabungan'], self::TOLERANSI, 'Uc meleset');
        }

        if ($ujiVeff) {
            $this->assertEqualsWithDelta($e['veff'], $hasil['derajat_kebebasan_efektif'], self::TOLERANSI, 'v_eff meleset');
            $this->assertEqualsWithDelta($e['k'], $hasil['faktor_cakupan_k'], self::TOLERANSI_K, 'k meleset');
            $this->assertEqualsWithDelta($e['U'], $hasil['ketidakpastian_diperluas'], self::TOLERANSI_K, 'U meleset');
        }

        // Yang PALING penting: U95 yang DILAPORKAN sama dengan sertifikat.
        $this->assertEqualsWithDelta($e['reported'], $hasil['u95_sertifikat'], self::TOLERANSI, 'U95 dilaporkan meleset');
    }

    /**
     * Yokogawa SP3: kalkulator menghitung Uc BENAR (0,6346), bukan menyalin bug
     * sel `O75` master (0,6234). Yang tercetak tetap 1,4 (lantai CMC).
     */
    public function test_yokogawa_sp3_menghitung_benar_bukan_menyalin_bug_master(): void
    {
        $fix = self::fixture()['yokogawa'];
        $hasil = $this->hitung($fix, $fix['setpoints'][2]);

        $this->assertEqualsWithDelta(0.6346331673, $hasil['ketidakpastian_gabungan'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.4, $hasil['u95_sertifikat'], self::TOLERANSI);
        $this->assertSame('cmc', $hasil['sumber_u95']);
    }

    /**
     * Constant/Yokogawa punya 11 komponen budget (termasuk Konduksi Panas),
     * Recorder 10 (tanpa Konduksi Panas). Kalau salinan tabel suatu saat bikin
     * dua-duanya sama, tes ini yang jatuh.
     */
    public function test_jumlah_komponen_budget_beda_dua_template(): void
    {
        $fix = self::fixture();
        $yoko = $this->hitung($fix['yokogawa'], $fix['yokogawa']['setpoints'][0]);
        $rec = $this->hitung($fix['recorder'], $fix['recorder']['setpoints'][0]);

        $this->assertCount(11, $yoko['budget'], 'Constant/Yokogawa harus 11 komponen');
        $this->assertCount(10, $rec['budget'], 'Recorder harus 10 komponen');

        $sumberYoko = array_column($yoko['budget'], 'sumber');
        $this->assertContains('konduksi_panas', $sumberYoko);
        $this->assertNotContains('konduksi_panas', array_column($rec['budget'], 'sumber'));

        // Radiasi 0,6 di Yokogawa, 0,1 di Recorder — beda, bukan salinan.
        $radYoko = $this->komponen($yoko['budget'], 'efek_radiasi');
        $radRec = $this->komponen($rec['budget'], 'efek_radiasi');
        $this->assertEqualsWithDelta(0.6 / sqrt(3.0), $radYoko['u'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.1 / sqrt(3.0), $radRec['u'], self::TOLERANSI);
    }

    /**
     * Penyimpangan master yang ditiru — dijaga supaya nggak diam-diam "dibenerin":
     *  - drift kalibrator & sensor dibagi 1,73 literal, bukan √3;
     *  - Pengulangan Standar Recorder dibagi √(√3), bukan √3.
     */
    public function test_pembagi_master_ditiru(): void
    {
        $fix = self::fixture();
        $rec = $this->hitung($fix['recorder'], $fix['recorder']['setpoints'][0]);

        // Drift kalibrator Recorder Type K = 0,5 → 0,5 / 1,73.
        $drift = $this->komponen($rec['budget'], 'drift_kalibrator');
        $this->assertEqualsWithDelta(0.5 / 1.73, $drift['u'], self::TOLERANSI);
        $this->assertJauhDari(0.5 / sqrt(3.0), $drift['u'], self::TOLERANSI);

        // Pengulangan Standar Recorder ÷ √(√3) = 3^0,25 ≈ 1,3161.
        $peng = $this->komponen($rec['budget'], 'pengulangan_standar');
        $this->assertEqualsWithDelta(
            $rec['kestabilan'] / EnclosureCalculator::PEMBAGI_PENGULANGAN_RECORDER,
            $peng['u'],
            self::TOLERANSI,
        );

        // Constant/Yokogawa Pengulangan Standar pakai √3 yang benar.
        $yoko = $this->hitung($fix['yokogawa'], $fix['yokogawa']['setpoints'][0]);
        $pengY = $this->komponen($yoko['budget'], 'pengulangan_standar');
        $this->assertEqualsWithDelta($yoko['kestabilan'] / sqrt(3.0), $pengY['u'], self::TOLERANSI);
    }

    /**
     * Peta kolom pembacaan termokopel `[1,2,3,3,4]` (buang ke-5, gandakan ke-3).
     * Sensor dengan pembacaan ke-5 ≠ ke-3 harus rata-rata terkoreksinya ikut
     * versi master, bukan rata-rata bersih kelima pembacaan.
     */
    public function test_peta_kolom_pembacaan_ditiru(): void
    {
        $this->assertSame([0, 1, 2, 2, 3], EnclosureCalculator::PETA_KOLOM_PEMBACAAN);

        // Yokogawa SP4 sensor "5" (index 2) punya pembacaan 100.24/100.1/100.1/100.24/100.22
        // — ke-5 (100.22) ≠ ke-3 (100.1). Master pakai [100.24,100.1,100.1,100.1,100.24].
        $fix = self::fixture()['yokogawa'];
        $hasil = $this->hitung($fix, $fix['setpoints'][3]);
        // dist_terkoreksi[2] sudah diadu di test utama; di sini pastikan ≠ rata bersih.
        $sensor = $fix['setpoints'][3]['sensors'][2]['pembacaan'];
        $rataBersih = array_sum($sensor) / count($sensor);
        $rataMaster = ($sensor[0] + $sensor[1] + $sensor[2] + $sensor[2] + $sensor[3]) / 5;
        $this->assertJauhDari($rataBersih, $rataMaster, 1e-6, 'contoh ini harus punya ke-5 ≠ ke-3');
    }

    /** v_eff TIDAK dipotong ke bawah — Recorder v_eff 298,25 (pecahan), bukan 298. */
    public function test_v_eff_tidak_dipotong(): void
    {
        $this->assertFalse(EnclosureCalculator::FLOOR_V_EFF);

        $fix = self::fixture()['recorder'];
        $hasil = $this->hitung($fix, $fix['setpoints'][0]);
        $this->assertEqualsWithDelta(298.25471214223484, $hasil['derajat_kebebasan_efektif'], self::TOLERANSI);
        $this->assertGreaterThan(298.0, $hasil['derajat_kebebasan_efektif']);
    }

    /**
     * @param  array<string, mixed>  $fix
     * @param  array<string, mixed>  $sp
     * @return array<string, mixed>
     */
    private function hitung(array $fix, array $sp): array
    {
        $sensors = array_map(
            static fn (array $s): array => [
                'no' => $s['no'],
                'channel' => $s['channel'] ?? null,
                'pembacaan' => $s['pembacaan'],
            ],
            $sp['sensors'],
        );

        return (new EnclosureCalculator)->hitungSetpoint($sensors, $sp['indikator'], (float) $sp['setpoint'], [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $budget
     * @return array<string, mixed>
     */
    private function komponen(array $budget, string $sumber): array
    {
        foreach ($budget as $k) {
            if ($k['sumber'] === $sumber) {
                return $k;
            }
        }

        $this->fail("komponen `{$sumber}` nggak ada di budget");
    }

    private function assertJauhDari(float $tak, float $aktual, float $delta, string $pesan = ''): void
    {
        $this->assertGreaterThan($delta, abs($aktual - $tak), $pesan);
    }
}
