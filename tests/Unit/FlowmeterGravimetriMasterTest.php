<?php

namespace Tests\Unit;

use App\Services\Calibration\FlowmeterGravimetriCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Services\Calibration\TabelStandarFlowmeterGravimetri;
use Tests\TestCase;

/**
 * Adu sel demi sel ke KEDUA workbook master Flowmeter **Gravimetri (ISO 4185)**.
 *
 * Sumbernya `1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm`
 * dan `2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm` (password
 * sandi ada di admin lab). Angka harapan di bawah disalin dari SELNYA, bukan dari keluaran
 * kode ini — kalau keduanya lahir dari sumber yang sama, test-nya cuma mengukur
 * dirinya sendiri.
 *
 * ## Yang cocok PERSIS, dan yang sengaja tidak
 *
 * Seluruh kolom turunan `PERHITUNGAN FC` Totalizer cocok persis: rata-rata UUT,
 * massa bersih, koreksi tabel, densitas, `E`, `Mt`, hasil, deviasi, dan
 * simpangan baku. Itu rantai yang tidak kami sentuh sama sekali.
 *
 * Yang sengaja BERBEDA ada delapan, semuanya didokumentasikan di
 * [\App\Services\Calibration\FlowmeterGravimetriCalculator] dan diuji di sini
 * sebagai ARAH plus angka — bukan sekadar "berbeda".
 */
class FlowmeterGravimetriMasterTest extends TestCase
{
    private const TOL = 5e-6;

    /** `PERHITUNGAN U95%!I13` KEDUA master — `Ut-water` di sana SELALU nol. */
    private const U_TEMPERATURE_MASTER = 0.2780287754891569;

    private function kalk(): FlowmeterGravimetriCalculator
    {
        return new FlowmeterGravimetriCalculator;
    }

    private function cocok(float $harap, float $dapat, string $tag): void
    {
        $skala = max(abs($harap), 1e-9);

        $this->assertLessThanOrEqual(
            self::TOL,
            abs($harap - $dapat) / $skala,
            "{$tag}: harap {$harap}, dapat {$dapat}",
        );
    }

    /**
     * Sesi contoh Totalizer — sertifikat `003-CAL-126`, timbangan 1 (Dini Argeo).
     *
     * `INPUT DATA!D35:M48` dan `D53:P55`.
     *
     * @return array<string, mixed>
     */
    private function totalizer(): array
    {
        return [
            'konteks' => [
                'mode' => TabelStandarFlowmeter::MODE_TOTALIZER,
                'satuan' => 'L',
                'resolusi' => 0.01,
                'kode_timbangan' => 1,
            ],
            'titik' => [
                [
                    'titik_ke' => 1,
                    'uut' => [140.11, 140.12, 140.09],
                    'berat_isi' => [139.9, 139.8, 139.9],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'suhu_awal' => [25.5, 25.5, 25.5],
                    'suhu_akhir' => [25.4, 25.4, 25.4],
                ],
                [
                    'titik_ke' => 2,
                    'uut' => [500.68, 500.71, 500.70],
                    'berat_isi' => [499.3, 499.4, 499.4],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'suhu_awal' => [25.4, 25.4, 25.4],
                    'suhu_akhir' => [25.4, 25.4, 25.4],
                ],
                [
                    'titik_ke' => 3,
                    'uut' => [1001.24, 1001.25, 1001.30],
                    'berat_isi' => [998.6, 998.7, 998.7],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'suhu_awal' => [25.3, 25.3, 25.3],
                    'suhu_akhir' => [25.4, 25.4, 25.4],
                ],
                [
                    'titik_ke' => 4,
                    'uut' => [2499.53, 2499.52, 2499.51],
                    'berat_isi' => [2485.5, 2484.9, 2487.0],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'suhu_awal' => [26.0, 26.0, 26.0],
                    'suhu_akhir' => [26.0, 26.0, 26.0],
                ],
            ],
        ];
    }

    /**
     * Sesi contoh Flowrate — sertifikat yang SAMA, timbangan 3 (Mettler).
     *
     * Ketiga ulangan UUT identik persis di master (`INPUT DATA!D36:F38`), dan
     * itu justru yang membuat penjaga `max !== min` harus jatuh ke penimbangan,
     * bukan ke UUT.
     *
     * @return array<string, mixed>
     */
    private function flowrate(): array
    {
        return [
            'konteks' => [
                'mode' => TabelStandarFlowmeter::MODE_FLOWRATE,
                'satuan' => 'm3/h',
                'resolusi' => 0.0001,
                'kode_timbangan' => 3,
            ],
            'titik' => [
                [
                    'titik_ke' => 1,
                    'uut' => [
                        [0.1221, 0.1219, 0.1220],
                        [0.1221, 0.1219, 0.1220],
                        [0.1221, 0.1219, 0.1220],
                    ],
                    'berat_isi' => [2.0205, 2.0207, 2.0206],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'waktu_menit' => [1.001, 1.0, 1.0],
                    'suhu_awal' => [26.5, 26.5, 26.5],
                    'suhu_akhir' => [26.5, 26.6, 26.6],
                ],
                [
                    'titik_ke' => 2,
                    'uut' => [
                        [0.5967, 0.5971, 0.5965],
                        [0.5967, 0.5971, 0.5965],
                        [0.5967, 0.5971, 0.5965],
                    ],
                    'berat_isi' => [9.9287, 9.9295, 9.9289],
                    'berat_kosong' => [0.0, 0.0, 0.0],
                    'waktu_menit' => [1.0, 1.0, 1.0],
                    'suhu_awal' => [26.5, 26.5, 26.5],
                    'suhu_akhir' => [26.5, 26.5, 26.5],
                ],
            ],
        ];
    }

    /**
     * Densitas air itu TABEL PIKNOMETER + interpolasi linier, bukan Tanaka/Kell.
     *
     * `STANDAR KALIBRATOR!P66:P80` kedua workbook. Kalau ini bergeser, tiap
     * sertifikat gravimetri lama ikut bergeser di digit belakang tanpa satu pun
     * error.
     */
    public function test_densitas_air_dari_tabel_piknometer(): void
    {
        $tabel = new TabelStandarFlowmeterGravimetri;

        $this->cocok(0.9982171924657005, $tabel->densitasAir(20.0), 'rho 20 °C');
        $this->cocok(0.9957884401725965, $tabel->densitasAir(27.0), 'rho 27 °C');
        $this->cocok(0.9963158263848134, $tabel->densitasAir(25.48), 'rho 25,48 °C');
        $this->cocok(0.9963505228461434, $tabel->densitasAir(25.379999999999995), 'rho 25,38 °C');
        $this->cocok(0.9963852193074735, $tabel->densitasAir(25.28), 'rho 25,28 °C');
        $this->cocok(0.9961423440781632, $tabel->densitasAir(25.98), 'rho 25,98 °C');
        $this->cocok(0.9964893086914637, $tabel->densitasAir(24.98), 'rho 24,98 °C');

        // Di luar jangkauan piknometer pulang null, bukan titik terdekat —
        // mengekstrapolasi densitas air ke 5 °C melesetnya 0,15 %.
        $this->assertNull($tabel->densitasAir(5.0));
        $this->assertNull($tabel->densitasAir(80.0));
    }

    /**
     * Kolom turunan `PERHITUNGAN FC` Totalizer — titik 1, 2, dan 3 cocok PERSIS.
     *
     * Rantai ini tidak kami sentuh sama sekali, jadi yang tidak cocok di sini
     * berarti mesinnya yang salah, bukan masternya.
     */
    public function test_totalizer_kolom_turunan_cocok_master(): void
    {
        $m = $this->totalizer();
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);
        $titik = collect($hasil['titik'])->keyBy('titik_ke');

        $harap = [
            1 => [
                'uut_rata' => 140.10666666666668,
                'massa_bersih' => 139.86666666666667,
                'koreksi_standar' => 0.0,
                'std_terkoreksi' => 139.86666666666667,
                'densitas_standar' => 0.9963331746154784,
                'koreksi_apung' => 0.001054416384572484,
                'massa_terapung' => 140.01414437165553,
                'hasil' => 140.52944129426598,
                'deviasi' => 0.4227746275992956,
                'simpangan_baku_standar' => 0.06999999999999505,
            ],
            2 => [
                'uut_rata' => 500.69666666666666,
                'massa_bersih' => 499.3666666666666,
                'koreksi_standar' => 0.0,
                'std_terkoreksi' => 499.3666666666666,
                'densitas_standar' => 0.9963505228461434,
                'koreksi_apung' => 0.0010543954135459456,
                'massa_terapung' => 499.8931965896777,
                'hasil' => 501.7242276961913,
                'deviasi' => 1.0275610295246338,
                'simpangan_baku_standar' => 0.0435889894353997,
            ],
            3 => [
                'uut_rata' => 1001.2633333333333,
                'massa_bersih' => 998.6666666666666,
                'koreksi_standar' => 0.0,
                'std_terkoreksi' => 998.6666666666666,
                'densitas_standar' => 0.9963678710768085,
                'koreksi_apung' => 0.0010543744432496796,
                'massa_terapung' => 999.7196352773252,
                'hasil' => 1003.3639826190846,
                'deviasi' => 2.100649285751274,
                'simpangan_baku_standar' => 0.04509249752824256,
            ],
        ];

        foreach ($harap as $nomor => $kolom) {
            $this->assertTrue($titik->has($nomor), "Titik {$nomor} Totalizer tidak terhitung.");

            foreach ($kolom as $kunci => $nilai) {
                $this->cocok($nilai, (float) $titik[$nomor][$kunci], "Totalizer titik {$nomor} {$kunci}");
            }
        }
    }

    /**
     * Titik 4 Totalizer DIBLOKIR — dua alasan, dan dua-duanya nyata.
     *
     * Penimbangan 2.485,8 kg ada 24 % di luar titik tertinggi tabel koreksi
     * Dini Argeo (2.000 kg), dan volumenya 2.498 L ada di luar pita CMC
     * terakreditasi yang berhenti di 1.991 L. Master menerbitkan keduanya tanpa
     * satu pun sel yang memprotes.
     */
    public function test_totalizer_titik_4_diblokir_bukan_diterbitkan(): void
    {
        $m = $this->totalizer();
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $ditolak = collect($hasil['ditolak'])->keyBy('titik_ke');

        $this->assertTrue($ditolak->has(4), 'Titik 4 Totalizer seharusnya diblokir.');
        $this->assertStringContainsString('LUAR rentang pakai tabel koreksi', $ditolak[4]['alasan']);

        $this->assertSame([1, 2, 3], collect($hasil['titik'])->pluck('titik_ke')->all());
    }

    /**
     * Kesembilan komponen budget Totalizer titik 1 — `u`, `ci`, dan `vi`.
     *
     * Tujuh cocok persis dengan `PERHITUNGAN U95%!E21:I29`. Dua komponen suhu
     * sengaja lebih besar karena `U_temperature` kami menghitung `Ut-water`
     * yang di master selalu nol.
     */
    public function test_komponen_budget_totalizer_cocok_master(): void
    {
        $m = $this->totalizer();
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);
        $budget = collect($hasil['titik'][0]['budget'])->keyBy('sumber');

        $this->assertCount(9, $budget, 'Budget Totalizer gravimetri wajib 9 komponen.');

        $harap = [
            'standar_timbangan' => ['u' => 0.26, 'ci' => 1.0, 'vi' => 60.0],
            'resolusi_uut' => ['u' => 0.0028901734104046246, 'ci' => 1.0, 'vi' => 50.0],
            'kestabilan_aliran' => ['u' => 0.4624277456647465, 'ci' => 1.0, 'vi' => 50.0],
            'pengulangan_standar' => ['u' => 0.04041451884327095, 'ci' => 1.0, 'vi' => 2.0],
            'koreksi_bouyancy' => ['u' => 0.0004061544546077052, 'ci' => 1.0, 'vi' => 50.0],
            'densitas_air' => ['u' => 0.00025, 'ci' => -0.1690776833300622, 'vi' => 60.0],
            'drift_timbangan' => ['u' => 0.05780346820809249, 'ci' => 1.0, 'vi' => 50.0],
        ];

        foreach ($harap as $sumber => $kolom) {
            $this->assertTrue($budget->has($sumber), "Komponen `{$sumber}` tidak ada.");

            foreach ($kolom as $kunci => $nilai) {
                $this->cocok($nilai, (float) $budget[$sumber][$kunci], "budget {$sumber}.{$kunci}");
            }
        }

        // `ci` kedua komponen suhu memang cocok persis — yang berbeda `u`-nya.
        $this->cocok(0.02961979328168542, (float) $budget['densitas_beda_suhu']['ci'], 'ci densitas_beda_suhu');
        $this->cocok(0.02961979328168542, (float) $budget['drift_suhu']['ci'], 'ci drift_suhu');
        $this->cocok(0.017341040462427744, (float) $budget['drift_suhu']['u'], 'u drift_suhu');

        // ARAH: `u` komponen suhu lebih besar dari master karena `Ut-water`.
        $this->assertGreaterThan(
            0.08035513742461181,
            (float) $budget['densitas_beda_suhu']['u'],
            'u densitas_beda_suhu wajib LEBIH BESAR dari master (Ut-water master selalu nol).',
        );
    }

    /**
     * Komponen Koreksi Bouyancy hidup di SETIAP titik, bukan cuma titik 1.
     *
     * `E45`, `E65`, dan `E85` master bernilai `0` — rumusnya tidak ikut
     * tersalin dari `E25`. Komponen yang lenyap tanpa jejak tidak pernah
     * menghasilkan error.
     */
    public function test_koreksi_bouyancy_tidak_lenyap_di_titik_lain(): void
    {
        $m = $this->totalizer();
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        foreach ($hasil['titik'] as $t) {
            $komponen = collect($t['budget'])->firstWhere('sumber', 'koreksi_bouyancy');

            $this->assertGreaterThan(
                0.0,
                (float) $komponen['u'],
                "Koreksi bouyancy titik {$t['titik_ke']} tidak boleh nol — di master titik 2..4 dia nol.",
            );

            // 0,0005 % dari nilai terkoreksi, lalu dibagi 1,73.
            $this->cocok(
                5e-6 * (float) $t['hasil'] / 1.73,
                (float) $komponen['u'],
                "koreksi_bouyancy titik {$t['titik_ke']}",
            );
        }
    }

    /**
     * `U_temperature` kedua workbook LEBIH BESAR dari master.
     *
     * Master menulis 0,2780288 °C di kedua-duanya karena `Ut-water` menunjuk
     * sel kosong. Suhu air sesi contoh Totalizer bergerak 25,3..26,0 °C dan
     * Flowrate 26,5..26,6 °C — dua sesi, dua angka, dua-duanya di atas master.
     */
    public function test_u_temperature_lebih_besar_dari_master(): void
    {
        $tot = $this->totalizer();
        $flo = $this->flowrate();

        $uTot = $this->kalk()->hitungSesi($tot['titik'], $tot['konteks'])['u_temperature'];
        $uFlo = $this->kalk()->hitungSesi($flo['titik'], $flo['konteks'])['u_temperature'];

        $this->cocok(0.3437053001240063, $uTot, 'U_temperature Totalizer');
        $this->cocok(0.2795234039098218, $uFlo, 'U_temperature Flowrate');

        $this->assertGreaterThan(self::U_TEMPERATURE_MASTER, $uTot);
        $this->assertGreaterThan(self::U_TEMPERATURE_MASTER, $uFlo);
    }

    /**
     * Agregat Totalizer titik 1 — sedekat mungkin ke master, dan arahnya naik.
     *
     * `k` cocok HANYA kalau `veff` dipotong ke BAWAH sebelum `TINV` — perilaku
     * yang sudah dimiliki `GumCalculator::agregasiBudget()`, dan itu sebabnya
     * tidak ada mesin agregasi kedua di sini.
     */
    public function test_agregat_totalizer_cocok_master(): void
    {
        $m = $this->totalizer();
        $t = $this->kalk()->hitungSesi($m['titik'], $m['konteks'])['titik'][0];

        $this->cocok(0.5351928953582936, (float) $t['ketidakpastian_gabungan'], 'uc');
        $this->cocok(82.68245079269605, (float) $t['derajat_kebebasan_efektif'], 'veff');
        $this->cocok(1.9893185571365721, (float) $t['faktor_cakupan_k'], 'k');
        $this->cocok(1.0646691583839052, (float) $t['ketidakpastian_diperluas'], 'U');

        // Master: uc 0,5351900996781498, U 1,0646635968855143 — kami sedikit
        // lebih besar, dan itu seluruhnya sumbangan Ut-water.
        $this->assertGreaterThan(0.5351900996781498, (float) $t['ketidakpastian_gabungan']);
        $this->assertGreaterThan(1.0646635968855143, (float) $t['ketidakpastian_diperluas']);

        // `k` master 1,9893185571365706 — cocok sampai digit terakhir yang
        // dibedakan floating point.
        $this->cocok(1.9893185571365706, (float) $t['faktor_cakupan_k'], 'k lawan master');
    }

    /**
     * Seluruh sesi Flowrate DIBLOKIR — 2 dan 10 Lpm, pita mulai di 75 Lpm.
     *
     * Nama berkasnya berbunyi "100-980lpm", `INPUT DATA!E14` berbunyi
     * `0.1-0.6 m3/h`, dan yang benar-benar diukur 0,12 m3/h. Dua puluh sampai
     * tiga puluh tujuh kali di bawah batas bawah lingkup akreditasi, dan
     * sertifikatnya tetap membawa LK-285-IDN.
     */
    public function test_seluruh_sesi_flowrate_di_luar_lingkup_diblokir(): void
    {
        $m = $this->flowrate();
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik'], 'Tidak satu pun titik Flowrate boleh terbit.');
        $this->assertCount(2, $hasil['ditolak']);

        foreach ($hasil['ditolak'] as $d) {
            $this->assertStringContainsString('LUAR kedua pita CMC terakreditasi', $d['alasan']);
        }
    }

    /**
     * Tabel timbangan ke-3 BEDA alat di kedua workbook — keduanya tersimpan.
     *
     * Memilih salah satu sebagai "yang benar" menggeser angka yang sudah
     * tercetak di sertifikat pelanggan.
     */
    public function test_timbangan_ketiga_beda_antar_mode(): void
    {
        $tabel = new TabelStandarFlowmeterGravimetri;

        $tot = $tabel->timbangan(TabelStandarFlowmeter::MODE_TOTALIZER, 3);
        $flo = $tabel->timbangan(TabelStandarFlowmeter::MODE_FLOWRATE, 3);

        $this->assertSame('DJ Series', $tot['tipe']);
        $this->assertSame('DFWLB-3', $flo['tipe']);
        $this->assertSame('g', $tot['satuan_tabel_koreksi']);
        $this->assertSame('kg', $flo['satuan_tabel_koreksi']);
        $this->assertNotSame($tot['tertelusur'], $flo['tertelusur']);

        // Kode yang tidak ada pulang null — TIDAK jatuh ke timbangan pertama.
        $this->assertNull($tabel->timbangan(TabelStandarFlowmeter::MODE_FLOWRATE, 4));
        $this->assertNull($tabel->timbangan(TabelStandarFlowmeter::MODE_TOTALIZER, 9));
    }

    /**
     * Tabel bersatuan gram dicocokkan dalam KILOGRAM.
     *
     * Tanpa konversi, penimbangan 27 kg memungut koreksi titik `27` dari tabel
     * gram — 0,1 kg, atau 588 kali U95 timbangannya sendiri — dan mendarat di
     * sertifikat tanpa satu pun error.
     */
    public function test_tabel_gram_dicocokkan_dalam_kilogram(): void
    {
        $tabel = new TabelStandarFlowmeterGravimetri;

        // Mettler mode totalizer: titik 3..30 GRAM = 0,003..0,030 kg.
        $rentang = $tabel->rentangPakai(TabelStandarFlowmeter::MODE_TOTALIZER, 3);

        $this->assertLessThan(0.05, $rentang['maks'], 'Tabel gram wajib berjangkauan kilogram kecil.');
        $this->assertFalse($tabel->dalamJangkauan(TabelStandarFlowmeter::MODE_TOTALIZER, 3, 27.0));

        // Mettler mode flowrate: titik 3..30 KILOGRAM.
        $this->assertTrue($tabel->dalamJangkauan(TabelStandarFlowmeter::MODE_FLOWRATE, 3, 9.929));
        $this->cocok(
            9.0,
            $tabel->cocokTerdekat(TabelStandarFlowmeter::MODE_FLOWRATE, 3, 9.929)['titik_kg'],
            'titik tabel Mettler flowrate',
        );
    }
}
