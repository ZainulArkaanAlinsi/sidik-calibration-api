<?php

namespace Tests\Unit;

use App\Services\Calibration\EnclosureCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Enclosure **Bath** — efek radiasi dan pembebanan = 0 (master 12 Nov 2026).
 *
 * Sumber sel master:
 *
 *  - Constant/Yokogawa: `PERHITUNGAN U95%!N31 = IF($U$21="bath",0,0.6)` dan
 *    `N32 = IF($U$21="bath",0,20/100*'PERHITUNGAN FC'!R42)`, diulang tiap set
 *    point (`N57/N58`, `N83/N84`, `N109/N110`, `N135/N136`, `N161/N162`).
 *    Konduksi panas `N33` tetap 0,1.
 *  - Recorder: `DATABASE!V7:W7` (Bath 0/0; Oven/Furnace/Inkubator/Refrigerator
 *    0,1/0,1), dibaca `PERHITUNGAN U95%!N31`/`N32` lewat `VLOOKUP(...,CMC_Enclosure)`.
 *
 * Yang diadu: grid yang SUDAH diadu ke master di `EnclosureBudgetTest`
 * (`tests/Fixtures/enclosure-golden.json`) dijalankan dua kali — tanpa dan
 * dengan bendera Bath. Sebelas/sepuluh komponen lain wajib IDENTIK (bukan
 * hampir sama), dua komponen itu wajib nol, dan agregat Bath wajib sama dengan
 * rumus agregat sel master (`AC34 = SUM`, `AC35 = SQRT`, `AC36 = AC35^4/AG34`,
 * `AC37` polinomial t-student, `AC38 = AC35*AC37`, `AC40 = MAX(AC38, CMC)`)
 * yang dihitung DI SINI dari baris-baris itu — bukan lewat `GumCalculator`.
 */
class EnclosureBathNolTest extends TestCase
{
    private const TOLERANSI = 1e-12;

    /** CMC Bath (`DATABASE!Q3:T9` master: No 3 "Bath" 1,2 °C; lampiran akreditasi). */
    private const CMC_BATH = 1.2;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /** @return array<string, array{string, int}> */
    public static function setpoints(): array
    {
        return [
            'yokogawa SP1 15 °C' => ['yokogawa', 0],
            'yokogawa SP2 35 °C' => ['yokogawa', 1],
            'yokogawa SP3 75 °C' => ['yokogawa', 2],
            'yokogawa SP4 100 °C' => ['yokogawa', 3],
            'recorder SP1 67 °C' => ['recorder', 0],
            'recorder SP2 67 °C' => ['recorder', 1],
            'recorder SP3 67 °C' => ['recorder', 2],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hitung(string $workbook, int $index, bool $bath, float $cmc): array
    {
        $fix = self::fixture()[$workbook];
        $sp = $fix['setpoints'][$index];

        return (new EnclosureCalculator)->hitungSetpoint(
            array_map(static fn (array $s): array => [
                'no' => $s['no'],
                'channel' => $s['channel'] ?? null,
                'pembacaan' => $s['pembacaan'],
            ], $sp['sensors']),
            $sp['indikator'],
            (float) $sp['setpoint'],
            [
                'merk' => $fix['merk'],
                'tipe_sensor' => $fix['tipe_sensor'],
                'cmc' => $cmc,
                'resolusi_alat' => 0.1,
                'radiasi_pembebanan_nol' => $bath,
            ],
            $index + 1,
        );
    }

    /**
     * Agregat persis rumus sel master, berdiri sendiri dari `GumCalculator`.
     *
     * @param  list<array<string, mixed>>  $budget
     * @return array{uc: float, veff: float, k: float, u: float}
     */
    private static function agregatMaster(array $budget): array
    {
        $ac = [];
        $ag = 0.0;

        foreach ($budget as $k) {
            if (! $k['disertakan']) {
                continue;
            }
            $kuadrat = ($k['u'] * $k['ci']) ** 2;
            $ac[] = $kuadrat;
            // AG: (uici)^4 / vi, dijumlahkan (`AG34`).
            $ag += $kuadrat ** 2 / $k['vi'];
        }

        $uc = sqrt(array_sum($ac));
        $veff = $uc ** 4 / $ag;
        // `AC37` — polinomial t-student master, v_eff TIDAK dipotong.
        $k = 1.95996 + 2.37356 / $veff + 2.818745 / $veff ** 2 + 2.546662 / $veff ** 3
            + 1.761829 / $veff ** 4 + 0.245458 / $veff ** 5 + 1.000764 / $veff ** 6;

        return ['uc' => $uc, 'veff' => $veff, 'k' => $k, 'u' => $uc * $k];
    }

    #[DataProvider('setpoints')]
    public function test_bath_mengosongkan_dua_komponen_dan_sisanya_identik(string $workbook, int $index): void
    {
        $lain = $this->hitung($workbook, $index, false, self::CMC_BATH);
        $bath = $this->hitung($workbook, $index, true, self::CMC_BATH);

        // Jumlah & urutan baris budget sama dengan master (baris tidak hilang).
        $this->assertSame(array_column($lain['budget'], 'sumber'), array_column($bath['budget'], 'sumber'));

        foreach ($bath['budget'] as $i => $k) {
            if (in_array($k['sumber'], ['efek_radiasi', 'efek_pembebanan'], true)) {
                $this->assertSame(0.0, $k['u'], $k['sumber'].' Bath wajib 0 (N31/N32 master)');
                $this->assertSame(1.0, $k['ci']);
                $this->assertTrue($k['disertakan'], 'barisnya tetap ada seperti sel master');
                $this->assertGreaterThan(0.0, $lain['budget'][$i]['u'], $k['sumber'].' jenis lain > 0');

                continue;
            }

            $this->assertSame($lain['budget'][$i], $k, 'komponen '.$k['sumber'].' tidak boleh berubah');
        }

        // Sebaran fisik tidak bergantung jenis enclosure.
        $this->assertSame($lain['keseragaman'], $bath['keseragaman']);
        $this->assertSame($lain['kestabilan'], $bath['kestabilan']);
        $this->assertSame($lain['sensor'], $bath['sensor']);
    }

    #[DataProvider('setpoints')]
    public function test_agregat_bath_sama_dengan_rumus_agregat_master(string $workbook, int $index): void
    {
        $lain = $this->hitung($workbook, $index, false, self::CMC_BATH);
        $bath = $this->hitung($workbook, $index, true, self::CMC_BATH);

        $master = self::agregatMaster($bath['budget']);

        $this->assertEqualsWithDelta($master['uc'], $bath['ketidakpastian_gabungan'], self::TOLERANSI, 'u_c (AC35)');
        $this->assertEqualsWithDelta($master['veff'], $bath['derajat_kebebasan_efektif'], 1e-6, 'v_eff (AC36)');
        // k master = polinomial; repo = t-student eksak (selisih ~1e-6, EnclosureBudgetTest).
        $this->assertEqualsWithDelta($master['k'], $bath['faktor_cakupan_k'], 5e-4, 'k (AC37)');
        $this->assertEqualsWithDelta($master['u'], $bath['ketidakpastian_diperluas'], 5e-4, 'U (AC38)');
        $this->assertEqualsWithDelta(
            max($master['u'], self::CMC_BATH),
            $bath['u95_sertifikat'],
            5e-4,
            'U dilaporkan (AC40 = MAX(U, CMC))',
        );

        // Arahnya: Bath tanpa dua komponen itu TIDAK BOLEH lebih besar.
        $this->assertLessThan($lain['ketidakpastian_gabungan'], $bath['ketidakpastian_gabungan']);
        $this->assertLessThanOrEqual($lain['ketidakpastian_diperluas'], $bath['ketidakpastian_diperluas']);

        // Selisih u_c² persis dua baris itu (`AC31 + AC32` master Yokogawa;
        // Recorder: `N31`/`N32` = 0,1/0,1 → 2·(0,1/√3)²).
        $hilang = 0.0;
        foreach ($lain['budget'] as $k) {
            if (in_array($k['sumber'], ['efek_radiasi', 'efek_pembebanan'], true)) {
                $hilang += ($k['u'] * $k['ci']) ** 2;
            }
        }
        $this->assertEqualsWithDelta(
            $lain['ketidakpastian_gabungan'] ** 2 - $hilang,
            $bath['ketidakpastian_gabungan'] ** 2,
            self::TOLERANSI,
        );
    }

    /** Nilai jenis lain tetap: Yokogawa 0,6 & 20% × R42, Recorder 0,1 & 0,1 (N31/N32, DATABASE!V/W). */
    public function test_jenis_lain_tidak_berubah_tanpa_bendera(): void
    {
        $yoko = $this->hitung('yokogawa', 0, false, 1.4);
        $rad = collect($yoko['budget'])->firstWhere('sumber', 'efek_radiasi');
        $pem = collect($yoko['budget'])->firstWhere('sumber', 'efek_pembebanan');
        $this->assertEqualsWithDelta(0.6 / sqrt(3.0), $rad['u'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.2 * $yoko['keseragaman'] / sqrt(3.0), $pem['u'], self::TOLERANSI);

        $rec = $this->hitung('recorder', 0, false, 1.5);
        $this->assertEqualsWithDelta(0.1 / sqrt(3.0), collect($rec['budget'])->firstWhere('sumber', 'efek_radiasi')['u'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.1 / sqrt(3.0), collect($rec['budget'])->firstWhere('sumber', 'efek_pembebanan')['u'], self::TOLERANSI);

        // Tanpa bendera tidak ada catatan Bath di jejak.
        $this->assertNotContains('bath_radiasi_pembebanan_nol', array_column($yoko['catatan_audit'], 'kode'));
        $this->assertNotContains('bath_radiasi_pembebanan_nol', array_column($rec['catatan_audit'], 'kode'));
    }

    /**
     * AGENTS.md §Olah data butir 4: catatan menyebut SUMBER sel master dan
     * selisih angkanya — terbaca di jejak sesi tanpa membuka kode.
     */
    #[DataProvider('setpoints')]
    public function test_catatan_jejak_menyebut_sel_master_dan_selisih(string $workbook, int $index): void
    {
        $lain = $this->hitung($workbook, $index, false, self::CMC_BATH);
        $bath = $this->hitung($workbook, $index, true, self::CMC_BATH);

        $catatan = collect($bath['catatan_audit'])->firstWhere('kode', 'bath_radiasi_pembebanan_nol');
        $this->assertNotNull($catatan, 'catatan Bath wajib ada');

        $this->assertStringContainsString('Bath', $catatan['pesan']);
        $this->assertStringContainsString('12 Nov 2026', $catatan['pesan']);

        if ($workbook === 'yokogawa') {
            $this->assertStringContainsString('PERHITUNGAN U95%!N31', $catatan['pesan']);
            $this->assertStringContainsString('N32', $catatan['pesan']);
        } else {
            $this->assertStringContainsString('DATABASE!V7:W7', $catatan['pesan']);
        }

        $r = $catatan['rincian'];
        $this->assertStringContainsString($workbook === 'yokogawa' ? 'N31,N32' : 'V7:W7', $r['sel_master']);
        $this->assertSame(0.0, $r['radiasi_bath']);
        $this->assertSame(0.0, $r['pembebanan_bath']);
        // Angka pembanding = hitungan jenis lain pada grid yang sama.
        $this->assertEqualsWithDelta($lain['ketidakpastian_diperluas'], $r['u_hitung_aturan_jenis_lain'], self::TOLERANSI);
        $this->assertEqualsWithDelta($bath['ketidakpastian_diperluas'], $r['u_hitung_sistem'], self::TOLERANSI);
        $this->assertLessThan($r['u_hitung_aturan_jenis_lain'], $r['u_hitung_sistem']);

        // Catatan terurut paling depan (terbaca dulu), sebelum catatan pembagi/peta kolom.
        $this->assertSame('bath_radiasi_pembebanan_nol', $bath['catatan_audit'][0]['kode']);
    }

    /** Lantai CMC tetap berlaku: U Bath di bawah 1,2 °C tercetak 1,2 (AC40 = MAX). */
    public function test_lantai_cmc_bath_tetap_menang_bila_u_hitung_lebih_kecil(): void
    {
        // Yokogawa SP1 (Type N, data master lama): U hitung jenis lain 1,2247 > 1,2; Bath jauh di bawahnya.
        $lain = $this->hitung('yokogawa', 0, false, self::CMC_BATH);
        $bath = $this->hitung('yokogawa', 0, true, self::CMC_BATH);

        $this->assertGreaterThan(self::CMC_BATH, $lain['ketidakpastian_diperluas']);
        $this->assertSame('hitung', $lain['sumber_u95']);

        $this->assertLessThan(self::CMC_BATH, $bath['ketidakpastian_diperluas']);
        $this->assertSame('cmc', $bath['sumber_u95']);
        $this->assertSame(self::CMC_BATH, $bath['u95_sertifikat']);
    }
}
