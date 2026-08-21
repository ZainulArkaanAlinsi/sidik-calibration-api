<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelKalibratorSuhu;
use App\Services\Calibration\TitsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden test TITS lawan kedua master:
 * `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi 01-CAL-625) dan
 * `… fungsi Source utk UUT.xlsm` (sesi 0159-CAL-626).
 *
 * Sel yang diadu:
 *
 *   besaran                       Measure                Source
 *   uici komponen budget          `PERHITUNGAN U95%` Z19:Z23   Z19:Z24
 *   Uc                            AC25                   AC26
 *   v_eff                         AC26                   AC27
 *   k                             AC27                   AC28
 *   U = k·Uc                      AC28                   AC29
 *   CMC                           AC29                   AC30
 *   U dilaporkan                  AC30                   AC31
 *   Standard / UUT / Correction   `SERTIFIKAT` E20:L28    E16:L23
 *
 * Yang paling penting diuji di sini bukan cuma "angkanya cocok", tapi
 * **penyimpangan master yang sengaja ditiru** — kalau salah satunya diam-diam
 * "dibenerin" nanti, tes ini yang jatuh duluan:
 *
 *  1. Pembagi AC Pick Up `√(√3)`, bukan `√3`.
 *  2. `v_eff` TIDAK dipotong ke bawah sebelum dicari `k`-nya.
 *  3. Drift kalibrator dibagi 2 di mode measure, tidak di mode source.
 *  4. `u` kalibrator dari MAX seluruh kolom (measure) versus dari titik index
 *     tertinggi (source).
 *  5. Komponen `Drift` bersel mati (0,38 °C, ci 2) yang cuma ada di mode source.
 *
 * ## Kenapa `k` & `U` bertoleransi lebih longgar dari `Uc` & `v_eff`
 *
 * Master mencari `k` lewat aproksimasi polinomial Excel
 * (`1.95996 + 2.37356/v + …`), repo ini lewat `StudentTDistribution`. Dua-duanya
 * mendekati kuantil t yang sama dan berselisih ~1,3·10⁻⁴ pada `v_eff` 6,5 —
 * jauh di bawah presisi cetak sertifikat (dua desimal), tapi jauh di atas batas
 * presisi double. Yang diuji ketat bagian yang memang harus identik: komponen
 * budget, `Uc`, `v_eff`, dan seluruh kolom hasil.
 */
class TitsBudgetTest extends TestCase
{
    /** Hitungan murni — nggak lewat kolom `decimal(20,8)` mana pun. */
    private const TOLERANSI = 1e-12;

    /** Selisih polinomial Excel vs kuantil t repo ini. Lihat docblock kelas. */
    private const TOLERANSI_K = 5e-4;

    /**
     * Sesi mode MEASURE — `INPUT DATA` B33:H50, Yokogawa + Type N, resolusi 0,1.
     *
     * @var list<array{0: float, 1: list<float>}>
     */
    private const TITIK_MEASURE = [
        [-20.0, [-20.05, -20.11, -20.09, -20.49, -20.60, -20.42]],
        [10.0, [9.85, 9.97, 9.89, 9.38, 9.45, 9.61]],
        [50.0, [50.16, 50.00, 50.02, 49.55, 49.56, 49.52]],
        [100.0, [100.30, 100.15, 100.20, 99.90, 99.75, 99.65]],
        [200.0, [199.70, 199.65, 199.75, 199.35, 199.40, 199.25]],
        [400.0, [399.85, 399.85, 399.80, 400.80, 399.80, 399.80]],
        [600.0, [600.70, 600.80, 600.60, 599.90, 600.10, 599.90]],
        [800.0, [800.80, 800.80, 800.90, 800.00, 800.00, 800.00]],
        [1000.0, [1000.10, 1000.00, 1000.10, 1000.10, 1000.10, 1000.10]],
    ];

    /**
     * Sesi mode SOURCE — `INPUT DATA` B33:H48, Yokogawa + Type S, resolusi 0,1.
     *
     * @var list<array{0: float, 1: list<float>}>
     */
    private const TITIK_SOURCE = [
        [0.0, [4.9, 4.9, 4.9, 4.7, 4.7, 4.7]],
        [100.0, [104.6, 104.6, 104.6, 104.0, 104.0, 104.0]],
        [300.0, [304.3, 304.3, 304.3, 304.6, 304.6, 304.6]],
        [500.0, [503.9, 503.9, 503.9, 504.0, 504.0, 504.0]],
        [700.0, [704.5, 704.5, 704.5, 704.6, 704.6, 704.6]],
        [900.0, [904.1, 904.1, 904.1, 904.2, 904.2, 904.2]],
        [1100.0, [1103.8, 1103.8, 1103.8, 1103.9, 1103.9, 1103.9]],
        [1200.0, [1203.7, 1203.7, 1203.7, 1203.7, 1203.7, 1203.7]],
    ];

    public function test_budget_mode_measure_cocok_sel_master(): void
    {
        $hasil = $this->hitung(TabelKalibratorSuhu::MODE_MEASURE, 'Type N', 0.83, self::TITIK_MEASURE);

        // `M23 = MAX(K23:L40)` — STDEV terbesar seluruh sesi, dari titik 800 °C.
        $this->assertEqualsWithDelta(0.45789372857317917, $hasil['standar_deviasi_maks'], self::TOLERANSI);

        // `Q23 = 3`: pembagi & derajat kebebasan Type A dari pembacaan PER ARAH.
        $this->assertSame(3, $hasil['jumlah_pengulangan']);

        $this->assertUici([
            'ketidakpastian_standar' => 0.18,                    // Z19 — MAX U95 Type N ÷ 2
            'drift_standar' => 0.0069282032302755096,            // Z20 — 0,024 ÷ 2 ÷ √3
            'resolusi_alat' => 0.02886751345948129,              // Z21 — 0,1 ÷ 2 ÷ √3
            'ac_pick_up' => 0.15196713713031854,                 // Z22 — 0,2 ÷ √(√3)
            'pengulangan_pembacaan' => 0.2643650674519664,       // Z23 — STDEV maks ÷ √3
        ], $hasil['budget']);

        $this->assertEqualsWithDelta(0.35533678811769703, $hasil['ketidakpastian_gabungan'], self::TOLERANSI); // AC25
        $this->assertEqualsWithDelta(6.506824549031214, $hasil['derajat_kebebasan_efektif'], self::TOLERANSI); // AC26
        $this->assertEqualsWithDelta(2.401577234865886, $hasil['faktor_cakupan_k'], self::TOLERANSI_K);        // AC27
        $this->assertEqualsWithDelta(0.853368741053824, $hasil['ketidakpastian_diperluas'], self::TOLERANSI_K); // AC28

        // AC30 = MAX(U, CMC 0,83) — di sesi ini U yang menang.
        $this->assertSame('hitung', $hasil['sumber_u95']);
        $this->assertEqualsWithDelta(0.853368741053824, $hasil['u95_sertifikat'], self::TOLERANSI_K);
    }

    public function test_budget_mode_source_cocok_sel_master(): void
    {
        $hasil = $this->hitung(TabelKalibratorSuhu::MODE_SOURCE, 'Type S', 1.2, self::TITIK_SOURCE);

        $this->assertEqualsWithDelta(0.3286335345030965, $hasil['standar_deviasi_maks'], self::TOLERANSI);

        $this->assertUici([
            // Z19 — U95 Type S di titik index TERTINGGI sesi (1200 °C) ÷ 2,
            // BUKAN MAX seluruh kolom seperti mode measure.
            'ketidakpastian_standar' => 0.28,
            // Z20 — 0,056 TANPA dibagi 2, beda dari mode measure.
            'drift_standar' => 0.03233161507461905,
            'resolusi_alat' => 0.02886751345948129,              // Z21
            // Z22 — komponen sel mati: 0,38 ÷ √3, ci 2.
            'drift_referensi_mati' => 0.4387862045841156,
            'ac_pick_up' => 0.15196713713031854,                 // Z23
            'pengulangan_pembacaan' => 0.18973665961010094,      // Z24
        ], $hasil['budget']);

        $this->assertEqualsWithDelta(0.5761128455151685, $hasil['ketidakpastian_gabungan'], self::TOLERANSI); // AC26
        $this->assertEqualsWithDelta(161.6556512675876, $hasil['derajat_kebebasan_efektif'], self::TOLERANSI); // AC27
        $this->assertEqualsWithDelta(1.9747512836610126, $hasil['faktor_cakupan_k'], self::TOLERANSI_K);       // AC28
        $this->assertEqualsWithDelta(1.1376795812146776, $hasil['ketidakpastian_diperluas'], self::TOLERANSI_K); // AC29

        // AC31 = MAX(U 1,1377; CMC 1,2) — di sesi ini CMC yang menang.
        $this->assertSame('cmc', $hasil['sumber_u95']);
        $this->assertEqualsWithDelta(1.2, $hasil['u95_sertifikat'], self::TOLERANSI);
    }

    /**
     * Kolom `Standard Reading` / `Unit Under Test` / `Correction` mode measure —
     * `SERTIFIKAT!E20:L28`.
     *
     * @param  array{0: float, 1: float, 2: float}  $harap
     */
    #[DataProvider('barisMeasure')]
    public function test_baris_sertifikat_mode_measure(int $index, array $harap): void
    {
        $hasil = $this->hitung(TabelKalibratorSuhu::MODE_MEASURE, 'Type N', 0.83, self::TITIK_MEASURE);
        $baris = $hasil['titik'][$index];

        [$standard, $uut, $koreksi] = $harap;

        $this->assertEqualsWithDelta($standard, $baris['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta($uut, $baris['rata_rata'], self::TOLERANSI);
        $this->assertEqualsWithDelta($koreksi, $baris['koreksi'], self::TOLERANSI);
    }

    /**
     * @return array<string, array{int, array{0: float, 1: float, 2: float}}>
     */
    public static function barisMeasure(): array
    {
        return [
            '-20 °C' => [0, [-19.91, -20.293333333333333, 0.38333333333333286]],
            '10 °C' => [1, [10.07, 9.691666666666668, 0.3783333333333321]],
            '50 °C' => [2, [50.05, 49.80166666666667, 0.24833333333332774]],
            '100 °C' => [3, [100.03, 99.99166666666666, 0.0383333333333411]],
            '200 °C' => [4, [200.03, 199.51666666666665, 0.5133333333333496]],
            '400 °C' => [5, [399.97, 399.98333333333335, -0.013333333333321207]],
            '600 °C' => [6, [599.91, 600.3333333333334, -0.42333333333340306]],
            '800 °C' => [7, [799.86, 800.4166666666666, -0.5566666666666151]],
            '1000 °C' => [8, [999.81, 1000.0833333333334, -0.2733333333334258]],
        ];
    }

    /**
     * Kolom sertifikat mode source — `SERTIFIKAT!E16:L23`. Perhatikan arahnya
     * berbalik: `Standard` = rata-rata bacaan kalibrator + koreksi, `UUT` =
     * setpoint yang di-set teknisi.
     *
     * @param  array{0: float, 1: float, 2: float}  $harap
     */
    #[DataProvider('barisSource')]
    public function test_baris_sertifikat_mode_source(int $index, array $harap): void
    {
        $hasil = $this->hitung(TabelKalibratorSuhu::MODE_SOURCE, 'Type S', 1.2, self::TITIK_SOURCE);
        $baris = $hasil['titik'][$index];

        [$standard, $uut, $koreksi] = $harap;

        $this->assertEqualsWithDelta($standard, $baris['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta($uut, $baris['rata_rata'], self::TOLERANSI);
        $this->assertEqualsWithDelta($koreksi, $baris['koreksi'], self::TOLERANSI);
    }

    /**
     * @return array<string, array{int, array{0: float, 1: float, 2: float}}>
     */
    public static function barisSource(): array
    {
        return [
            '0 °C' => [0, [4.9799999999999995, 0.0, 4.9799999999999995]],
            '100 °C' => [1, [104.39999999999999, 100.0, 4.3999999999999915]],
            '300 °C' => [2, [304.5, 300.0, 4.5]],
            '500 °C' => [3, [503.935, 500.0, 3.9350000000000023]],
            '700 °C' => [4, [704.4750000000001, 700.0, 4.475000000000136]],
            '900 °C' => [5, [904.025, 900.0, 4.024999999999977]],
            // Titik SERI: 1100 tepat di tengah 1000 & 1200 di tabel kalibrator.
            // Master mengambil 1200 (FC −0,20), bukan 1000 (FC −0,15) — lihat
            // `TabelKalibratorSuhu::terdekat()`.
            '1100 °C (seri)' => [6, [1103.6499999999996, 1100.0, 3.649999999999636]],
            '1200 °C' => [7, [1203.5, 1200.0, 3.5]],
        ];
    }

    /**
     * Arah dua mode benar-benar berbalik: data yang SAMA persis, dihitung dua
     * kali dengan mode berbeda, menghasilkan kolom yang bertukar sisi.
     *
     * Ini pagar untuk kesalahan yang paling mahal di modul ini — kalau arahnya
     * ketuker, seluruh kolom `Correction` terbalik tandanya dan tidak ada satu
     * pun angka yang kelihatan janggal.
     */
    public function test_dua_mode_menukar_sisi_standard_dan_uut(): void
    {
        $data = [[100.0, [104.6, 104.6, 104.6, 104.0, 104.0, 104.0]]];

        $measure = $this->hitung(TabelKalibratorSuhu::MODE_MEASURE, 'Type S', 1.2, $data)['titik'][0];
        $source = $this->hitung(TabelKalibratorSuhu::MODE_SOURCE, 'Type S', 1.2, $data)['titik'][0];

        // Mode measure: yang dibaca berulang itu UUT-nya.
        $this->assertEqualsWithDelta(104.3, $measure['rata_rata'], self::TOLERANSI);
        $this->assertEqualsWithDelta(100.0, $measure['titik_ukur'] - $measure['koreksi_standar'], self::TOLERANSI);

        // Mode source: yang dibaca berulang itu STANDAR-nya, dan setpoint yang
        // jadi UUT.
        $this->assertEqualsWithDelta(100.0, $source['rata_rata'], self::TOLERANSI);
        $this->assertEqualsWithDelta(104.3, $source['titik_ukur'] - $source['koreksi_standar'], self::TOLERANSI);

        // Tanda koreksinya berlawanan, dan itu memang yang diharapkan.
        $this->assertLessThan(0.0, $measure['koreksi']);
        $this->assertGreaterThan(0.0, $source['koreksi']);
    }

    /**
     * Tabel dua mode benar-benar beda isi — bukan salinan. Kalau suatu saat
     * berkas datanya diregenerasi dari SATU workbook saja, tes ini yang jatuh.
     */
    public function test_tabel_dua_mode_tidak_sama(): void
    {
        $tabel = new TabelKalibratorSuhu;

        // RTD @ −20 °C: −0,02 sebagai sumber sinyal, +0,16 sebagai pembaca.
        // Bukan semua kolom berbeda — Type S Yokogawa misalnya identik di kedua
        // tabel — jadi yang diadu di sini kolom yang memang bergerak.
        $measure = $tabel->koreksi(TabelKalibratorSuhu::MODE_MEASURE, 'yokogawa', 'RTD', -20.0);
        $source = $tabel->koreksi(TabelKalibratorSuhu::MODE_SOURCE, 'yokogawa', 'RTD', -20.0);

        $this->assertNotNull($measure);
        $this->assertNotNull($source);
        $this->assertEqualsWithDelta(-0.019999999999998866, $measure['nilai'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.16, $source['nilai'], self::TOLERANSI);
    }

    /**
     * U95 negatif di tabel `Type K` Yokogawa mode source (nilai koreksi yang
     * tersalin ke kolom U95) ditolak, bukan dipakai mentah.
     */
    public function test_u95_negatif_ditolak(): void
    {
        $tabel = new TabelKalibratorSuhu;

        $this->assertNull($tabel->u95(TabelKalibratorSuhu::MODE_SOURCE, 'yokogawa', 'Type K', 1000.0));
        $this->assertNotNull($tabel->u95(TabelKalibratorSuhu::MODE_MEASURE, 'yokogawa', 'Type K', 1000.0));
    }

    /**
     * @param  array<string, float>  $harap  sumber komponen → uici (`u · ci`)
     * @param  list<array<string, mixed>>  $budget
     */
    private function assertUici(array $harap, array $budget): void
    {
        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));

        $this->assertCount(count($harap), $dipakai, 'jumlah komponen budget beda dari master');

        foreach ($dipakai as $komponen) {
            $sumber = $komponen['sumber'];

            $this->assertArrayHasKey($sumber, $harap, "komponen `{$sumber}` nggak ada di master");
            $this->assertEqualsWithDelta(
                $harap[$sumber],
                $komponen['u'] * $komponen['ci'],
                self::TOLERANSI,
                "uici komponen `{$sumber}` meleset",
            );
        }
    }

    /**
     * @param  list<array{0: float, 1: list<float>}>  $titik
     * @return array<string, mixed>
     */
    private function hitung(string $mode, string $tipeSensor, float $cmc, array $titik): array
    {
        return (new TitsCalculator)->hitungSesi(
            array_map(
                static fn (array $t, int $i): array => [
                    'titik_ke' => $i + 1,
                    'titik_ukur' => $t[0],
                    'pembacaan' => $t[1],
                ],
                $titik,
                array_keys($titik),
            ),
            [
                'mode' => $mode,
                'merk_kalibrator' => 'yokogawa',
                'tipe_sensor' => $tipeSensor,
                'resolusi' => 0.1,
                'cmc' => $cmc,
            ],
        );
    }
}
