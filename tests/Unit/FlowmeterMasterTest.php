<?php

namespace Tests\Unit;

use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Adu sel demi sel ke KEDUA workbook master Flowmeter Ultrasonic.
 *
 * Sumbernya `1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L)
 * 2026.xlsm` dan `Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm`
 * (password `spirit285`). Angka harapan di bawah disalin dari SELNYA, bukan
 * dari keluaran kode ini — kalau keduanya lahir dari sumber yang sama, test-nya
 * cuma mengukur dirinya sendiri.
 *
 * ## Yang diadu
 *
 * Tiap kolom turunan (`PERHITUNGAN FC`), tiap `u`/`ci`/`vi` kesembilan (atau
 * kedelapan) komponen, lalu `uc`, `veff`, `k`, dan `U` (`PERHITUNGAN U95%`).
 * Toleransi 5·10⁻⁶ relatif.
 *
 * `k` cocok HANYA kalau `veff` dipotong ke BAWAH sebelum `TINV` — perilaku yang
 * sudah dimiliki `GumCalculator::agregasiBudget()`, dan itu sebabnya tidak ada
 * mesin agregasi kedua di sini.
 *
 * ## Blok titik 3 & 4 TIDAK dipakai
 *
 * Keduanya tidak terisi di sesi contoh, jadi kerusakannya tidak pernah
 * terlihat: `vi` FU 200 alih-alih 60, `vi` suhu 50 alih-alih 2, `ci`
 * cross-sectional memakai rumus yang sama sekali lain, `u` velocity menunjuk
 * sel kosong, dan `K82` menghasilkan `#DIV/0!` sementara tetangganya `L82`
 * menunjuk sel yang benar. Acuannya titik 1 dan 2 saja.
 *
 * ## Tiga hal yang SENGAJA tidak cocok, dan diuji terpisah
 *
 * Ketiganya perbaikan, bukan kegagalan — lihat
 * [\App\Services\Calibration\FlowmeterCalculator]:
 *
 *  1. `U_temperature` — master `Ut-water` menunjuk sel kosong, jadi selalu nol.
 *  2. Totalizer titik 2 — rentang densitas master melenceng satu kolom ke
 *     titik 3.
 *  3. Flowrate titik 2 U95 sertifikat — lantai CMC yang master lupa pasang
 *     (dijaga `FlowmeterLantaiCmcTest`).
 */
class FlowmeterMasterTest extends TestCase
{
    private const TOL = 5e-6;

    private const PIPA = [
        'diameter_pipa_mm' => [50.81, 50.82, 50.81],
        'ketebalan_pipa_mm' => [2.32, 2.31, 2.32],
    ];

    /** `PERHITUNGAN U95%!I28` kedua master — `Ut-water` di sana SELALU nol. */
    private const U_TEMPERATURE_MASTER = 0.2780287754891569;

    /** `PERHITUNGAN U95%!L35`/`L34` dan `L41`/`L40`. */
    private const A_MASTER = 1674.0850340000002;

    private const UA_MASTER = 0.883253086081929;

    /** @return array<string, mixed> */
    private function totalizer(): array
    {
        return (new FlowmeterCalculator)->hitungSesi([
            [
                'titik_ke' => 1,
                'uut' => [[1002.65], [1004.56], [1006.52]],
                'std' => [1010.885, 1011.52, 1012.215],
                'suhu_awal' => [25.5, 25.5, 25.5],
                'suhu_akhir' => [25.4, 25.4, 25.4],
                'densitas_uut' => [],
            ],
            [
                'titik_ke' => 2,
                'uut' => [[1901.37], [1902.66], [1904.27]],
                'std' => [1901.654, 1903.158, 1904.998],
                'suhu_awal' => [25.4, 25.4, 25.4],
                'suhu_akhir' => [25.4, 25.4, 25.4],
                'densitas_uut' => [],
            ],
        ], [
            'mode' => TabelStandarFlowmeter::MODE_TOTALIZER,
            'satuan' => 'L',
            'resolusi' => 0.01,
        ] + self::PIPA);
    }

    /** @return array<string, mixed> */
    private function flowrate(): array
    {
        return (new FlowmeterCalculator)->hitungSesi([
            [
                'titik_ke' => 1,
                'uut' => [
                    [101.255, 101.276, 101.289],
                    [102.654, 102.625, 102.678],
                    [101.986, 101.910, 101.945],
                ],
                'std' => [101.998, 101.897, 101.123],
                'suhu_awal' => [24.5, 24.5, 24.5],
                'suhu_akhir' => [24.5, 24.6, 24.6],
                'densitas_uut' => [],
            ],
            [
                'titik_ke' => 2,
                'uut' => [
                    [309.785, 309.776, 309.779],
                    [311.376, 311.374, 311.398],
                    [310.772, 310.778, 310.745],
                ],
                'std' => [308.711, 310.255, 310.251],
                'suhu_awal' => [24.6, 24.6, 24.6],
                'suhu_akhir' => [24.6, 24.6, 24.6],
                'densitas_uut' => [],
            ],
        ], [
            'mode' => TabelStandarFlowmeter::MODE_FLOWRATE,
            'satuan' => 'LPM',
            'resolusi' => 0.001,
        ] + self::PIPA);
    }

    private function cocok(float $harap, float $dapat, string $tag): void
    {
        $this->assertEqualsWithDelta($harap, $dapat, max(abs($harap), 1.0) * self::TOL, $tag);
    }

    /** Geometri pipa — tingkat sesi, sama di kedua workbook. */
    public function test_geometri_pipa_cocok_master(): void
    {
        foreach (['totalizer' => $this->totalizer(), 'flowrate' => $this->flowrate()] as $mode => $hasil) {
            $this->cocok(self::A_MASTER, $hasil['geometri']['a'], "A {$mode}");
            $this->cocok(self::UA_MASTER, $hasil['geometri']['u_a'], "u_A {$mode}");
        }
    }

    /**
     * Kolom turunan `PERHITUNGAN FC` Totalizer, titik 1 & 2.
     *
     * `std_terkoreksi` titik 2 SENGAJA tidak diadu ke master di sini — lihat
     * [test_totalizer_titik_2_densitas_tidak_tercemar].
     */
    public function test_totalizer_kolom_turunan_cocok_master(): void
    {
        $t = collect($this->totalizer()['titik'])->keyBy('titik_ke');

        $this->cocok(1004.5766666666667, $t[1]['uut_rata'], 'D26 UUT avg');
        $this->cocok(1011.54, $t[1]['std_rata'], 'D33 STD avg');
        $this->cocok(1.2700032808356363, $t[1]['simpangan_baku_standar'], 'D42 STDEV berpasangan');
        $this->cocok(1013.852, $t[1]['baris_tabel']['standard'], 'D34 Index STD');
        $this->cocok(-13.052000000000021, $t[1]['koreksi_standar'], 'D35 Koreksi standar');
        $this->cocok(998.4879999999999, $t[1]['std_terkoreksi_simpel'], 'D63 Standard Corrected');
        $this->cocok(0.9969296529385006, $t[1]['densitas_standar'], 'D65 rho STD');
        $this->cocok(998.4879999999999, $t[1]['std_terkoreksi'], 'D66 Totalizer Std Terkoreksi');
        $this->cocok(-6.088666666666768, $t[1]['deviasi'], 'D67 Deviation');

        $this->cocok(1902.7666666666664, $t[2]['uut_rata'], 'H26 UUT avg');
        $this->cocok(1903.2699999999998, $t[2]['std_rata'], 'H33 STD avg');
        $this->cocok(0.22204804284957327, $t[2]['simpangan_baku_standar'], 'H42 STDEV berpasangan');
        $this->cocok(1919.692, $t[2]['baris_tabel']['standard'], 'H34 Index STD');
        $this->cocok(-19.394000000000005, $t[2]['koreksi_standar'], 'H35 Koreksi standar');
        $this->cocok(1883.8759999999997, $t[2]['std_terkoreksi_simpel'], 'H63 Standard Corrected');
        $this->cocok(0.9969426938848222, $t[2]['densitas_standar'], 'H65 rho STD');

        // Totalizer TIDAK punya simpangan baku UUT — komponennya baru lahir di
        // revisi 20 Mei 2026 dan cuma masuk workbook Flowrate.
        $this->assertNull($t[1]['simpangan_baku_uut']);
        $this->assertNull($t[2]['simpangan_baku_uut']);
    }

    /** Kolom turunan `PERHITUNGAN FC` Flowrate, titik 1 & 2. */
    public function test_flowrate_kolom_turunan_cocok_master(): void
    {
        $t = collect($this->flowrate()['titik'])->keyBy('titik_ke');

        $this->cocok(101.95755555555554, $t[1]['uut_rata'], 'D27 UUT avg (9 nilai)');
        $this->cocok(0.038039453203224466, $t[1]['simpangan_baku_uut'], 'Q28 MAX STDEV UUT');
        $this->cocok(101.67266666666667, $t[1]['std_rata'], 'D34 STD avg');
        $this->cocok(0.8749746239706611, $t[1]['simpangan_baku_standar'], 'D43 STDEV Flowrate');
        $this->cocok(100.185, $t[1]['baris_tabel']['standard'], 'D35 Index Flowrate');
        $this->cocok(-0.9939999999999998, $t[1]['koreksi_standar'], 'D36 Koreksi standar');
        $this->cocok(100.67866666666667, $t[1]['std_terkoreksi_simpel'], 'D64 Standard Corrected');
        $this->cocok(0.997164747644616, $t[1]['densitas_standar'], 'D66 rho STD');
        $this->cocok(100.67866666666667, $t[1]['std_terkoreksi'], 'D67 Flowrate Std Terkoreksi');
        $this->cocok(-1.278888888888872, $t[1]['deviasi'], 'D68 Deviation');

        $this->cocok(310.6425555555555, $t[2]['uut_rata'], 'I27 UUT avg (9 nilai)');
        $this->cocok(0.017578395831250038, $t[2]['simpangan_baku_uut'], 'R28 MAX STDEV UUT');
        $this->cocok(309.739, $t[2]['std_rata'], 'I34 STD avg');
        $this->cocok(0.33863784873017166, $t[2]['simpangan_baku_standar'], 'I43 STDEV Flowrate');
        // Titik tabel TERDEKAT, bukan interpolasi — jaraknya 23,8 % dari bacaan.
        $this->cocok(236.147, $t[2]['baris_tabel']['standard'], 'I35 Index Flowrate');
        $this->cocok(-2.9110000000000014, $t[2]['koreksi_standar'], 'I36 Koreksi standar');
        $this->cocok(306.828, $t[2]['std_terkoreksi_simpel'], 'I64 Standard Corrected');
        $this->cocok(0.9971479311702164, $t[2]['densitas_standar'], 'I66 rho STD');
        $this->cocok(306.828, $t[2]['std_terkoreksi'], 'I67 Flowrate Std Terkoreksi');
        $this->cocok(-3.8145555555555006, $t[2]['deviasi'], 'I68 Deviation');
    }

    /**
     * Kedelapan komponen budget Totalizer, `PERHITUNGAN U95%` baris 48–55 dan
     * 67–74: `u` mentah, `ci`, dan `vi`.
     *
     * `u` komponen SUHU sengaja dilewati (`null`) — dia satu-satunya yang
     * bergeser, dan arahnya diuji [test_u_temperature_lebih_besar_dari_master].
     */
    public function test_komponen_budget_totalizer_cocok_master(): void
    {
        $master = [
            1 => [
                ['standar_ufm', 13.0104, 1.0, 60.0],
                ['resolusi_uut', 0.005, 1.0, 50.0],
                ['suhu_fluida', null, 0.21097603039232857, 2.0],
                ['pengulangan_standar', 1.2700032808356363, 1.0, 2.0],
                ['cross_sectional_area', 0.883253086081929, 0.5964380421072445, 50.0],
                ['velocity_profile', 1.0983368, 1.0, 50.0],
                ['geometry_factor', 2.9954639999999997, 1.0, 50.0],
                ['drift_ufm', 0.130104, 1.0, 50.0],
            ],
            2 => [
                ['standar_ufm', 24.703874000000003, 1.0, 60.0],
                ['resolusi_uut', 0.005, 1.0, 50.0],
                // `ci` suhu titik 2 master lahir dari `H66` yang TERCEMAR kolom
                // titik 3; punya kita lahir dari `H66` yang bersih. Selisihnya
                // 8,8e-6 relatif — di luar toleransi 5e-6, jadi yang diadu
                // punya kita sendiri. Lihat
                // [test_totalizer_titik_2_densitas_tidak_tercemar].
                ['suhu_fluida', null, 0.39804412504432426, 2.0],
                ['pengulangan_standar', 0.22204804284957327, 1.0, 2.0],
                ['cross_sectional_area', 0.883253086081929, 1.1253167920023348, 50.0],
                ['velocity_profile', 2.0722636, 1.0, 50.0],
                ['geometry_factor', 5.651628, 1.0, 50.0],
                ['drift_ufm', 0.24703874000000003, 1.0, 50.0],
            ],
        ];

        $this->aduBudget(
            collect($this->totalizer()['titik'])->keyBy('titik_ke'),
            $master,
            'Totalizer',
            2.0,
        );
    }

    /** Kesembilan komponen budget Flowrate, baris 47–55 dan 67–75. */
    public function test_komponen_budget_flowrate_cocok_master(): void
    {
        $master = [
            1 => [
                ['standar_ufm', 1.487865, 1.0, 60.0],
                ['resolusi_uut', 0.0005, 1.0, 50.0],
                ['pengulangan_uut', 0.038039453203224466, 1.0, 2.0],
                ['suhu_fluida', null, 0.021262920565931542, 50.0],
                ['pengulangan_standar', 0.8749746239706611, 1.0, 2.0],
                ['cross_sectional_area', 0.883253086081929, 0.060139517779517204, 50.0],
                ['velocity_profile', 0.11074653333333334, 1.0, 50.0],
                ['geometry_factor', 0.302036, 1.0, 50.0],
                ['drift_ufm', 0.01487865, 1.0, 50.0],
            ],
            2 => [
                ['standar_ufm', 3.032068, 1.0, 60.0],
                ['resolusi_uut', 0.0005, 1.0, 50.0],
                ['pengulangan_uut', 0.017578395831250038, 1.0, 2.0],
                ['suhu_fluida', null, 0.06480299809507929, 50.0],
                ['pengulangan_standar', 0.33863784873017166, 1.0, 2.0],
                ['cross_sectional_area', 0.883253086081929, 0.18328101247454312, 50.0],
                ['velocity_profile', 0.3375108, 1.0, 50.0],
                ['geometry_factor', 0.920484, 1.0, 50.0],
                ['drift_ufm', 0.030320680000000003, 1.0, 50.0],
            ],
        ];

        $this->aduBudget(
            collect($this->flowrate()['titik'])->keyBy('titik_ke'),
            $master,
            'Flowrate',
            1.73,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $titik
     * @param  array<int, list<array{0: string, 1: float|null, 2: float, 3: float}>>  $master
     */
    private function aduBudget(Collection $titik, array $master, string $nama, float $pembagiCrossSection): void
    {
        foreach ($master as $titikKe => $komponen) {
            $budget = $titik[$titikKe]['budget'];

            $this->assertCount(
                count($komponen),
                $budget,
                "{$nama} titik {$titikKe}: jumlah komponen budget berubah.",
            );

            foreach ($komponen as $i => [$sumber, $u, $ci, $vi]) {
                $b = $budget[$i];

                $this->assertSame($sumber, $b['sumber'], "{$nama} titik {$titikKe} komponen ke-".($i + 1));

                if ($u !== null) {
                    $this->cocok($u, $b['u_mentah'], "{$nama} t{$titikKe} u[{$sumber}]");
                }

                $this->cocok($ci, $b['ci'], "{$nama} t{$titikKe} ci[{$sumber}]");
                $this->cocok($vi, $b['vi'], "{$nama} t{$titikKe} vi[{$sumber}]");
            }

            // Pembagi cross-sectional BEDA antar-varian — 2 di Totalizer, 1,73
            // di Flowrate, untuk komponen yang sama. Ditiru; pertanyaan lab §6.
            $cross = collect($budget)->firstWhere('sumber', 'cross_sectional_area');
            $this->cocok($pembagiCrossSection, $cross['pembagi'], "{$nama} pembagi cross-sectional");
        }
    }

    /**
     * Agregat `uc`, `veff`, `k`, dan `U` keempat blok titik.
     *
     * `U` yang diadu di sini `ketidakpastian_diperluas` (SEBELUM lantai CMC) —
     * lantainya diuji terpisah di `FlowmeterLantaiCmcTest`, dan mencampur
     * keduanya membuat kegagalan lantai terbaca seperti kegagalan budget.
     */
    public function test_agregat_cocok_master(): void
    {
        $master = [
            'totalizer' => [
                1 => [6.806780970887366, 71.14334448412902, 1.9939433678456266, 13.57233577327868],
                2 => [12.843841636500287, 69.72698574434142, 1.9949454151072357, 25.622762985099662],
            ],
            'flowrate' => [
                1 => [0.9190841375227726, 18.934057708259, 2.100922040241038, 1.9309241213575181],
                2 => [1.6330931919699339, 78.67772709516183, 1.990847068811692, 3.2512387943296726],
            ],
        ];

        $hasil = ['totalizer' => $this->totalizer(), 'flowrate' => $this->flowrate()];

        foreach ($master as $mode => $titik) {
            $t = collect($hasil[$mode]['titik'])->keyBy('titik_ke');

            foreach ($titik as $titikKe => [$uc, $veff, $k, $u]) {
                $this->cocok($uc, $t[$titikKe]['ketidakpastian_gabungan'], "{$mode} t{$titikKe} uc");
                $this->cocok($veff, $t[$titikKe]['derajat_kebebasan_efektif'], "{$mode} t{$titikKe} veff");
                // `k` cocok HANYA kalau veff dipotong ke bawah sebelum TINV.
                $this->cocok($k, $t[$titikKe]['faktor_cakupan_k'], "{$mode} t{$titikKe} k");
                $this->cocok($u, $t[$titikKe]['ketidakpastian_diperluas'], "{$mode} t{$titikKe} U");
            }
        }
    }

    /**
     * Penyimpangan 1 — `Ut-water` master menunjuk sel KOSONG.
     *
     * `PERHITUNGAN U95%!I24` Totalizer berbunyi `='PERHITUNGAN FC'!Q52-…!Q54`;
     * kolom `Q` ada satu kolom di luar blok suhu (yang berhenti di `P`).
     * Flowrate sama: `P53-P55`. Hasilnya suku itu SELALU nol, padahal labelnya
     * sendiri menulis `(Tmax−Tmin)Water`.
     *
     * Arahnya yang ditegakkan: hasil kita harus LEBIH BESAR.
     */
    public function test_u_temperature_lebih_besar_dari_master(): void
    {
        foreach (['totalizer' => $this->totalizer(), 'flowrate' => $this->flowrate()] as $mode => $hasil) {
            $this->assertGreaterThan(
                self::U_TEMPERATURE_MASTER,
                $hasil['u_temperature'],
                "U_temperature {$mode} tidak lebih besar dari master — suku Ut-water hilang lagi.",
            );

            // Kedua sesi contoh punya sebaran suhu air 0,1 °C, jadi
            // Ut_water = 0,1/(2√3) dan U_temperature naik ke 0,2795234 °C.
            $this->cocok(0.2795234039098218, $hasil['u_temperature'], "U_temperature {$mode}");
        }
    }

    /**
     * Penyimpangan 2 — rentang densitas Totalizer melenceng satu kolom.
     *
     * `PERHITUNGAN FC!H64` (densitas UUT titik 2) membaca `H44:K46`, dan kolom
     * `K` itu **titik 3**. Akibatnya rasio densitasnya bukan 1 dan standar
     * terkoreksinya bergeser, padahal densitas UUT tidak pernah diketik.
     *
     * Di sini tiap titik membaca kolom suhunya sendiri, jadi rasionya persis 1.
     */
    public function test_totalizer_titik_2_densitas_tidak_tercemar(): void
    {
        $t2 = collect($this->totalizer()['titik'])->keyBy('titik_ke')[2];

        // Master: 1883,8594625886044 dan −18,907204078062023.
        $this->cocok(1883.8759999999997, $t2['std_terkoreksi'], 'H66 tanpa cemaran kolom titik 3');
        $this->cocok(-18.890666666666675, $t2['deviasi'], 'H67 tanpa cemaran kolom titik 3');

        // Rasio densitasnya persis 1 karena densitas UUT tidak diketik dan
        // densitas standarnya lahir dari kolom titik ini sendiri.
        $this->assertSame($t2['densitas_standar'], $t2['densitas_uut']);
        $this->assertSame($t2['std_terkoreksi_simpel'], $t2['std_terkoreksi']);

        $this->assertNotEquals(
            1883.8594625886044,
            $t2['std_terkoreksi'],
            'Standar terkoreksi titik 2 masih memungut kolom suhu titik 3.',
        );
    }

    /**
     * Tabel standar: baris KOSONG tidak ikut, dan pencocokannya TERDEKAT.
     *
     * `std_totalizer` menyapu 12 baris di master (4 terisi), `std_flowrate` 9
     * baris (3 terisi). Sel kosong dibaca `0` oleh `MIN(ABS(...))`, jadi bacaan
     * di bawah ~50 memungut baris nol dan `VLOOKUP(0)` memulangkan `#N/A` ke
     * sertifikat pelanggan.
     */
    public function test_tabel_standar_tanpa_baris_kosong(): void
    {
        $tabel = new TabelStandarFlowmeter;

        $this->assertCount(4, $tabel->baris(TabelStandarFlowmeter::MODE_TOTALIZER));
        $this->assertCount(3, $tabel->baris(TabelStandarFlowmeter::MODE_FLOWRATE));

        // Bacaan 5 L: di master memungut baris kosong (nol) dan menerbitkan
        // `#N/A`. Di sini dia di luar jangkauan — pemanggil yang mengangkatnya
        // jadi titik terblokir.
        $this->assertFalse($tabel->dalamJangkauan(TabelStandarFlowmeter::MODE_TOTALIZER, 5.0));
        $this->assertTrue($tabel->dalamJangkauan(TabelStandarFlowmeter::MODE_TOTALIZER, 1013.852));

        // Pencocokan TERDEKAT, bukan interpolasi: 309,739 Lpm memungut 236,147.
        $this->assertSame(
            236.147,
            $tabel->cocokTerdekat(TabelStandarFlowmeter::MODE_FLOWRATE, 309.739)['standard'],
        );

        // Dan jaraknya 23,8 % — di atas ambang peringatan 10 %.
        $this->assertGreaterThan(
            TabelStandarFlowmeter::AMBANG_JARAK_TABEL,
            $tabel->jarakRelatif(TabelStandarFlowmeter::MODE_FLOWRATE, 309.739),
        );
    }
}
