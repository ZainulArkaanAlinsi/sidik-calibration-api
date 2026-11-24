<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Services\Calibration\JangkaSorongCalculator;
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use App\Services\Calibration\TabelStandarJangkaSorong;
use App\Support\JangkaSorongMentah;
use App\Support\TataLetakSertifikat;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Jangka Sorong: aplikasi lawan workbook master `Master Olah Data Caliper
 * 2026 (std caliper checker+gb).xlsm`, tahap demi tahap.
 *
 * Bukan duplikat `tests/Unit/JangkaSorongMasterTest.php`: tes itu mengadu u/ci/vi
 * tiap komponen dan uc/U dengan toleransi 5e-6 dari fixture buatan generator sesi
 * lama. Di sini tiap tahap diadu dengan alamat sel di pesan galat dan toleransi
 * bertulis (lihat [MenegakkanParitas]): konstanta dan tabel standar, tiap titik
 * Outside/Inside/Depth (total nominal, rata-rata, standar terkoreksi, STDEV,
 * koreksi), tiap komponen ketiga budget (ui, ci, vi), uc, veff, k, U, lantai CMC,
 * kesejajaran, kerataan, dan ANGKA CETAK. Titik panggil terkecil tanpa HTTP:
 * `JangkaSorongCalculator::hitungSesi()` (fungsi yang sama dengan preview, simpan,
 * dan `HitungUlangSesi` lewat `JangkaSorongProfile::hitungPerGrup()`).
 *
 * ## Acuan angka dan jebakan `NOW()`
 *
 * Komponen drift Excel (`PERHITUNGAN U95%!K10`, disalin ke K30) memakai
 * `DATABASE!X11 =NOW()`. Acuan sah hanya nilai-cache pada X11 yang tersimpan
 * (`drift.x11_cache`): aplikasi diberi `tanggal_kalibrasi` = X11 itu (dipotong ke
 * detik karena `getTimestamp()`), jadi umur drift identik. Selisih pemotongan
 * 0,06 detik = 4,7e-9 relatif pada umur (146,47 hari = 1,27e7 detik); itu satu-satunya
 * alasan batas 1e-8 pada rasio drift di bawah.
 *
 * ## SELISIH yang SUDAH diketahui (bukan temuan baru; dikunci, tidak diratakan)
 *
 *  - Drift Outside/Inside: aplikasi membagi umur dengan 365 hari, master dengan 12
 *    (`JangkaSorongCalculator::PEMBAGI_UMUR_HARI`, paket keputusan 16 Sep 2026).
 *    uc/veff/k/U ketiganya diadu ke rumus sel DENGAN drift aplikasi (hitung ulang
 *    Python pada fixture), supaya terbukti drift satu-satunya penyebab.
 *  - Lantai CMC 0,015 mm dipasang aplikasi, master tidak (`AA20/AA39/AA59` kosong).
 *    Pada sesi sampel U > CMC, jadi lantai tidak mengikat.
 *  - Bacaan Depth: master membaca berkas LUAR (`[3]INPUT DATA`); aplikasi membaca
 *    lembar sesinya sendiri. Diadu dua kali: dengan bacaan INPUT DATA (apa yang
 *    diketik teknisi) dan dengan nilai-cache bacaan Excel (apa yang dihitung Excel).
 *  - Repeatability Depth: master menunjuk sel kosong (`K43 = PERHITUNGAN!AD121`) -> 0;
 *    aplikasi memakai STDEV terbesar titik Depth.
 *
 * ## SELISIH BARU yang ditemukan paritas ini (konflik P-10.. di docs/paritas-jangka-sorong-conductivity.md)
 *
 *  - Blok Inside dan Depth di sheet `SERTIFIKAT` master merujuk kolom yang SALAH
 *    (`AC`, `N`, `AE` = delta-suhu, satu bacaan mentah, lw; bukan `AH`, `U`, `AJ` seperti
 *    Outside). Nilai-cache tercetak: Standar Reading 0, UUT = satu bacaan X3', Koreksi 0.
 *  - Cabang `inch` sel `SERTIFIKAT!L28` merujuk `PERHITUNGAN!V46` (bukan `AJ46`).
 */
#[Group('paritas')]
class ParitasJangkaSorongTest extends TestCase
{
    use MenegakkanParitas;

    private const OFFSET = ['outside' => 0, 'inside' => 100, 'depth' => 200];

    /**
     * Rasio drift aplikasi/Excel = (12/365) * (umur terpotong / umur persis). Pemotongan
     * 0,06 detik pada umur 1,2655e7 detik = 4,74e-9 relatif; batas 1e-8 = dua kali lipat.
     */
    private const TOL_RASIO_DRIFT = 1e-8;

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('jangka-sorong')['workbook'];
    }

    /**
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, mixed>}
     */
    private function masukan(bool $depthDariCacheExcel = false, array $ganti = []): array
    {
        $w = self::w();
        $m = $w['masukan'];
        $titik = [];

        foreach (self::OFFSET as $grup => $awal) {
            foreach ($m[$grup] as $i => $t) {
                $pembacaan = ($grup === 'depth' && $depthDariCacheExcel)
                    ? $w['excel']['depth'][$i]['pembacaan_cache_excel_mm']['nilai']
                    : $t['pembacaan_mm']['nilai'];

                $titik[$grup][] = [
                    'titik_ke' => $awal + $t['titik_ke'],
                    'nominal' => $t['nominal_mm']['nilai'],
                    'pembacaan' => $pembacaan,
                ];
            }
        }

        return [$titik, array_replace([
            'kapasitas_mm' => self::nilai($w['excel']['kapasitas_mm']),
            'resolusi_mm' => self::nilai($w['excel']['resolusi_mm']),
            'tanggal_kalibrasi' => new DateTimeImmutable($w['dengan_drift_aplikasi']['tanggal_sesi']),
            'pra_evaluasi_outside' => $m['pra_evaluasi_outside']['nilai'],
            'pra_evaluasi_inside' => $m['pra_evaluasi_inside']['nilai'],
            'suhu_ruang_rata_c' => JangkaSorongMentah::rataSuhuRuang(self::nilai($m['suhu_awal_c']), self::nilai($m['suhu_akhir_c'])),
            'kesejajaran' => array_map(static fn (array $k): array => [
                'posisi' => $k['posisi'],
                'nominal' => $k['nominal']['nilai'],
                'pembacaan' => $k['pembacaan']['nilai'],
            ], $m['kesejajaran']),
        ], $ganti)];
    }

    /** @return array<string, mixed> */
    private function hitung(bool $depthDariCacheExcel = false, array $ganti = []): array
    {
        [$titik, $konteks] = $this->masukan($depthDariCacheExcel, $ganti);

        return (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);
    }

    /** @return array<string, array<string, mixed>> komponen app per `sumber` */
    private function budget(array $hasil, string $grup): array
    {
        return collect($hasil['grup'][$grup]['budget'])->keyBy('sumber')->all();
    }

    // ------------------------------------------------------------------ konstanta dan tabel standar

    /**
     * Tabel standar dan konstanta aplikasi (data produksi: `tabel-standar-jangka-sorong.json`
     * + `tabel-standar-height-gauge.json`) = sel workbook.
     */
    public function test_konstanta_dan_tabel_standar_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();
        $k = $w['konstanta'];
        $tabel = new TabelStandarJangkaSorong;
        $app = $tabel->konstanta();

        $this->assertSamaExcel($k['alpha_standar_per_c'], (float) $app['alpha_per_c'], 'alpha standar (Caliper Checker)');
        $this->assertSamaExcel($k['alpha_uut_per_c'], (float) $app['alpha_per_c'], 'alpha UUT');
        $this->assertSamaExcel($k['u_alpha_caliper_checker_per_c'], (float) $app['u_alpha_caliper_checker_per_c'], 'U alpha Caliper Checker');
        $this->assertSamaExcel($k['u_alpha_balok_ukur_per_c'], (float) $app['u_alpha_balok_ukur_per_c'], 'U alpha balok ukur (Depth)');
        $this->assertSamaExcel($k['pembagi_muai'], (float) $app['pembagi_muai'], 'pembagi komponen muai (akar 6)');
        $this->assertSamaExcel($k['drift_a_um'], (float) $app['drift_a_um'], 'drift suku tetap');
        $this->assertSamaExcel($k['drift_b_um_per_mm'], (float) $app['drift_b_um_per_mm'], 'drift suku per panjang');
        $this->assertSamaExcel($k['wringing_um_per_keping'], (float) $app['wringing_um_per_keping'], 'wringing per keping');
        $this->assertSamaExcel($k['geometri_mm'], (float) $app['geometri_mm'], 'kesalahan geometri');
        $this->assertSamaExcel($k['efek_mekanik_mm'], (float) $app['efek_mekanik_mm'], 'efek mekanik');
        $this->assertSamaExcel($k['meja_granit_mm'], (float) $app['meja_granit_mm'], 'meja granit (Depth)');
        $this->assertSamaExcel($k['vi_type_b'], (float) $app['vi_type_b'], 'vi Type B');
        $this->assertSamaExcel($k['vi_resolusi'], (float) $app['vi_resolusi'], 'vi resolusi');
        $this->assertSamaExcel($k['pengulangan_vi'], (float) $app['pengulangan_pembagi_n'] - 1, 'vi pengulangan = n - 1 (n = 10)');
        $this->assertSamaExcel($k['depth_ci_muai'], (float) $app['depth_ci_muai'], 'Depth ci muai (diketik)');
        // `ci` suhu Depth = CTE x panjang balok 100 mm (tautan luar): aplikasi menyimpan keduanya.
        $this->assertSamaExcel($k['depth_ci_suhu'], (float) $app['depth_ci_suhu_cte_per_c'] * (float) $app['depth_ci_suhu_panjang_mm'], 'Depth ci suhu');
        $this->assertSamaExcel($k['faktor_ke_mm']['mm'], JangkaSorongMentah::FAKTOR_KE_MM['mm'], 'faktor satuan mm');
        $this->assertSamaExcel($k['faktor_ke_mm']['inch'], JangkaSorongMentah::FAKTOR_KE_MM['inch'], 'faktor satuan inch');

        // CMC pita 0-300 mm (15 um -> 0,015 mm) dan batas kapasitas.
        $pita = $tabel->pitaCmc(300.0);
        $this->assertNotNull($pita, 'pita CMC kapasitas 300 mm');
        $this->assertSamaExcel($k['cmc_um'], (float) $pita['u95_mm'] * 1000.0, 'CMC 0-300 mm (um)');

        // Caliper Checker: tabel Outside dan Inside, U95 4,1 um di semua baris.
        foreach ($k['tabel_caliper_checker_outside'] as $b) {
            $nominal = self::nilai($b['nominal_mm']);
            $this->assertSamaExcel($b['nilai_terkoreksi_mm'], (float) $tabel->nilaiOutside($nominal), "Caliper Checker Outside {$nominal} mm");
            $this->assertSamaExcel($k['u95_caliper_checker_um'], self::nilai($b['u95_um']), "U95 Caliper Checker baris {$b['u95_um']['sel']} (semua baris sama)");
        }
        foreach ($k['tabel_caliper_checker_inside'] as $b) {
            $nominal = self::nilai($b['nominal_mm']);
            $this->assertSamaExcel($b['nilai_terkoreksi_mm'], (float) $tabel->nilaiInside($nominal), "Caliper Checker Inside {$nominal} mm");
            $this->assertSamaExcel($k['u95_caliper_checker_um'], self::nilai($b['u95_um']), "U95 Caliper Checker baris {$b['u95_um']['sel']} (semua baris sama)");
        }
        $this->assertSamaExcel($k['u95_caliper_checker_um'], $tabel->u95CaliperCheckerMm() * 1000.0, 'U95 Caliper Checker (um)');
        $this->assertSame(
            array_map(static fn (array $b): float => (float) $b['nominal_mm']['nilai'], $k['tabel_caliper_checker_outside']),
            $tabel->nominalOutside(),
            'daftar nominal Outside terdaftar',
        );
        $this->assertSame(
            array_map(static fn (array $b): float => (float) $b['nominal_mm']['nilai'], $k['tabel_caliper_checker_inside']),
            $tabel->nominalInside(),
            'daftar nominal Inside terdaftar',
        );

        // Balok ukur: tiap keping yang dikenal aplikasi harus ada di Standar_GB dengan nilai dan U95 yang sama.
        $dataApp = json_decode((string) file_get_contents(database_path('data/tabel-standar-jangka-sorong.json')), true, flags: JSON_THROW_ON_ERROR);

        // Kunci tabel fixture dari Python (`1.0`) dinormalkan ke 6 desimal supaya cocok dengan nominal PHP (`1`).
        $gb = [];
        foreach ($k['tabel_balok_ukur'] as $kunciPy => $baris) {
            $gb[number_format((float) $kunciPy, 6, '.', '')] = $baris;
        }

        foreach ($dataApp['balok_ukur']['keping'] as $keping) {
            $kunci = number_format((float) $keping['nominal_mm'], 6, '.', '');
            $this->assertArrayHasKey($kunci, $gb, "keping {$kunci} mm ada di Standar_GB");
            $sel = $gb[$kunci];
            $this->assertSamaExcel(['sel' => $sel['sel'].' (R)', 'nilai' => $sel['nilai_terkoreksi_mm']], (float) $tabel->nilaiKeping((float) $keping['nominal_mm']), "balok ukur {$kunci} mm: nilai terkoreksi");
            $this->assertSamaExcel(['sel' => $sel['sel'].' (S)', 'nilai' => $sel['u95_mm']], (float) $tabel->u95Keping((float) $keping['nominal_mm']), "balok ukur {$kunci} mm: U95");
        }

        // Tanggal kalibrasi standar (titik nol umur drift) dan tumpukan Depth.
        $this->assertSame(substr($k['tanggal_kalibrasi_caliper_checker']['nilai'], 0, 10), $tabel->standarCaliperChecker()['tanggal_kalibrasi'], 'tanggal kalibrasi Caliper Checker vs DATABASE!W14');
        $this->assertSame(substr($k['tanggal_kalibrasi_balok_ukur']['nilai'], 0, 10), $tabel->standarBalokUkur()['tanggal_kalibrasi'], 'tanggal kalibrasi balok ukur vs DATABASE!W13');
        $this->assertSame(
            array_map(static fn (array $t): array => array_map('floatval', $t['nominal_mm']['nilai']), $w['masukan']['depth']),
            $tabel->tumpukanDepth(),
            'tumpukan Depth aplikasi vs nominal INPUT DATA!C111:C123',
        );
    }

    /**
     * SELISIH sengaja (paket keputusan 16 Sep 2026): umur drift dibagi 365 HARI di
     * aplikasi, 12 di master. Konstanta JSON masih mencatat 12 (rumus master).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_pembagi_umur_drift_master_12_aplikasi_365_dikunci(): void
    {
        $k = self::w()['konstanta'];

        $this->assertSame(12.0, self::nilai($k['drift_pembagi_umur_rumus']), 'pembagi umur drift di rumus master PERHITUNGAN U95%!K10');
        $this->assertSame(12, (int) (new TabelStandarJangkaSorong)->konstanta()['drift_pembagi_umur'], 'JSON aplikasi mencatat pembagi master');
        $this->assertSame(365.0, JangkaSorongCalculator::PEMBAGI_UMUR_HARI, 'pembagi umur drift yang DIPAKAI aplikasi');
    }

    // ------------------------------------------------------------------ evaluasi (repeatability)

    public function test_stdev_evaluation_outside_dan_inside_cocok_excel(): void
    {
        $w = self::w();
        $m = $w['masukan'];
        $kalk = new JangkaSorongCalculator;

        $this->assertSamaExcel($w['excel']['stdev_evaluasi']['outside'], $kalk->simpanganBaku($m['pra_evaluasi_outside']['nilai']), 'STDEV Evaluation Outside');
        $this->assertSamaExcel($w['excel']['stdev_evaluasi']['inside'], $kalk->simpanganBaku($m['pra_evaluasi_inside']['nilai']), 'STDEV Evaluation Inside');
        $this->assertCount(10, $m['pra_evaluasi_outside']['nilai']);
        $this->assertCount(10, $m['pra_evaluasi_inside']['nilai']);
    }

    // ------------------------------------------------------------------ tahap titik

    /** Outside dan Inside: total nominal, rata-rata, standar terkoreksi, STDEV, koreksi cocok Excel di 10 + 10 titik. */
    public function test_titik_outside_dan_inside_cocok_excel(): void
    {
        $hasil = $this->hitung();
        $w = self::w();

        foreach (['outside', 'inside'] as $grup) {
            $app = collect($hasil['grup'][$grup]['titik'])->keyBy('titik_ke');
            $this->assertCount(count($w['excel'][$grup]), $app, "{$grup}: jumlah titik");

            foreach ($w['excel'][$grup] as $x) {
                $h = $app[self::OFFSET[$grup] + $x['titik_ke']];
                $ke = "{$grup} titik {$x['titik_ke']}";

                $this->assertSamaExcel($x['total_nominal_mm'], $h['total_nominal'], "{$ke}: total nominal");
                $this->assertSamaExcel($x['rata_rata_mm'], $h['rata_rata'], "{$ke}: rata-rata pembacaan");
                $this->assertSamaExcel($x['standar_terkoreksi_mm'], $h['standar_terkoreksi'], "{$ke}: standar terkoreksi");
                $this->assertSamaExcel($x['stdev_mm'], $h['simpangan_baku'], "{$ke}: STDEV".($grup === 'inside' ? ' (lima bacaan pertama)' : ''));
                $this->assertSamaExcel($x['koreksi_mm'], $h['koreksi'], "{$ke}: koreksi (standar - rata-rata)");
            }
        }
    }

    /**
     * Depth dengan bacaan yang SAMA dengan Excel (nilai-cache `PERHITUNGAN!I106:S118`,
     * dibaca master dari berkas luar): kelima titik cocok di semua tahap.
     */
    public function test_titik_depth_cocok_excel_bila_bacaan_sama(): void
    {
        $hasil = $this->hitung(depthDariCacheExcel: true);
        $app = collect($hasil['grup']['depth']['titik'])->keyBy('titik_ke');

        foreach (self::w()['excel']['depth'] as $x) {
            $h = $app[self::OFFSET['depth'] + $x['titik_ke']];
            $ke = "depth titik {$x['titik_ke']}";

            $this->assertSamaExcel($x['total_nominal_mm'], $h['total_nominal'], "{$ke}: total nominal (tumpukan balok ukur)");
            $this->assertSamaExcel($x['rata_rata_mm'], $h['rata_rata'], "{$ke}: rata-rata (bacaan cache Excel)");
            $this->assertSamaExcel($x['standar_terkoreksi_mm'], $h['standar_terkoreksi'], "{$ke}: standar terkoreksi");
            $this->assertSamaExcel($x['stdev_mm'], $h['simpangan_baku'], "{$ke}: STDEV (lima bacaan pertama)");
            $this->assertSamaExcel($x['koreksi_mm'], $h['koreksi'], "{$ke}: koreksi");
        }
    }

    /**
     * SELISIH P-10 (bacaan Depth): `PERHITUNGAN!I106:S118` master membaca berkas LUAR
     * (`'[3]INPUT DATA'`), bukan `INPUT DATA` workbook ini. Dengan bacaan yang diketik di
     * INPUT DATA, total nominal dan standar terkoreksi tetap SAMA; rata-rata dan koreksi
     * BEDA di titik 2, 3, 4, 5 (kedua sumber sama di titik 1). Aplikasi memakai lembar
     * sesinya sendiri.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_depth_bacaan_input_data_beda_dari_bacaan_excel_dikunci(): void
    {
        $w = self::w();
        $hasil = $this->hitung();
        $app = collect($hasil['grup']['depth']['titik'])->keyBy('titik_ke');
        $beda = [];

        foreach ($w['excel']['depth'] as $i => $x) {
            $h = $app[self::OFFSET['depth'] + $x['titik_ke']];
            $lokal = $w['masukan']['depth'][$i]['pembacaan_mm']['nilai'];
            $ke = "depth titik {$x['titik_ke']}";

            $this->assertSamaExcel($x['total_nominal_mm'], $h['total_nominal'], "{$ke}: total nominal");
            $this->assertSamaExcel($x['standar_terkoreksi_mm'], $h['standar_terkoreksi'], "{$ke}: standar terkoreksi");
            $this->assertSamaHitungUlang(array_sum($lokal) / count($lokal), $h['rata_rata'], "{$ke}: rata-rata aplikasi = rata-rata INPUT DATA");
            $this->assertSamaHitungUlang($h['total_nominal'] - array_sum($lokal) / count($lokal), $h['koreksi'], "{$ke}: koreksi aplikasi dari bacaan INPUT DATA");

            if (abs(self::nilai($x['rata_rata_mm']) - $h['rata_rata']) > self::TOL_MUTLAK) {
                $beda[] = $x['titik_ke'];
            }
        }

        $this->assertSame([2, 3, 4, 5], $beda, 'titik Depth yang rata-ratanya beda antara bacaan INPUT DATA dan cache Excel (berkas luar)');
    }

    // ------------------------------------------------------------------ tahap budget

    /**
     * Sepuluh komponen Outside dan Inside: ui, ci, vi cocok Excel kecuali `drift_standar`
     * (lihat [test_drift_standar_umur_dibagi_365_dikunci]).
     */
    public function test_komponen_budget_outside_dan_inside_cocok_excel_kecuali_drift(): void
    {
        $hasil = $this->hitung();
        $w = self::w();

        foreach (['outside', 'inside'] as $grup) {
            $app = $this->budget($hasil, $grup);
            $this->assertCount(10, $app, "{$grup}: jumlah komponen");

            foreach ($w['excel']['komponen'][$grup] as $kunci => $x) {
                $ke = "{$grup} {$kunci} (baris {$x['baris']})";
                $this->assertArrayHasKey($kunci, $app, "{$ke}: komponen ada di aplikasi");

                $this->assertSamaExcel($x['vi'], (float) $app[$kunci]['vi'], "{$ke}: vi");
                $this->assertSamaExcel($x['ci'], (float) $app[$kunci]['ci'], "{$ke}: ci");

                if ($kunci === 'drift_standar') {
                    continue;
                }

                $this->assertSamaExcel($x['ui'], (float) $app[$kunci]['u'], "{$ke}: ui = u/pembagi");
            }

            // Urutan baris aplikasi = urutan baris sheet (baris Inside menukar selisih_suhu ke posisi 5).
            $this->assertSame(
                array_keys($w['excel']['komponen'][$grup]),
                array_keys($app),
                "{$grup}: urutan komponen aplikasi = urutan baris sheet",
            );
        }
    }

    /**
     * SELISIH sengaja (P-11): umur drift dibagi 365 hari (aplikasi) bukan 12 (master),
     * dan umur dari tanggal sesi bukan `NOW()`. ci dan vi SAMA; ui aplikasi = rumus sel
     * dengan drift aplikasi (hitung ulang Python), rasio terhadap Excel = 12/365.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_drift_standar_umur_dibagi_365_dikunci(): void
    {
        $hasil = $this->hitung();
        $w = self::w();
        $skenario = $w['dengan_drift_aplikasi'];

        foreach (['outside', 'inside'] as $grup) {
            $app = $this->budget($hasil, $grup)['drift_standar'];
            $x = $w['excel']['komponen'][$grup]['drift_standar'];
            $ke = "{$grup} drift_standar ({$x['ui']['sel']})";

            $this->assertSamaHitungUlang($skenario[$grup]['ui_drift'], (float) $app['u'], "{$ke}: ui aplikasi = rumus sel dengan umur/365");
            $rasio = (float) $app['u'] / self::nilai($x['ui']);
            $this->assertEqualsWithDelta(12.0 / 365.0, $rasio, 12.0 / 365.0 * self::TOL_RASIO_DRIFT, "{$ke}: ui aplikasi / ui Excel = 12/365 (rasio {$rasio})");
            $this->assertLessThan(self::nilai($x['ui']), (float) $app['u'], "{$ke}: arah — aplikasi lebih KECIL dari Excel");
        }
    }

    /**
     * Budget Depth: sebelas komponen SAMA di ui, ci, vi — termasuk repeatability 0 (master
     * menunjuk sel kosong; aplikasi 0 karena bacaan INPUT DATA identik per titik).
     */
    public function test_komponen_budget_depth_cocok_excel_dengan_bacaan_input_data(): void
    {
        $hasil = $this->hitung();
        $app = $this->budget($hasil, 'depth');
        $w = self::w();

        $this->assertCount(11, $app, 'depth: jumlah komponen');

        foreach ($w['excel']['komponen']['depth'] as $kunci => $x) {
            $ke = "depth {$kunci} (baris {$x['baris']})";
            $this->assertArrayHasKey($kunci, $app, "{$ke}: komponen ada di aplikasi");
            $this->assertSamaExcel($x['vi'], (float) $app[$kunci]['vi'], "{$ke}: vi");
            $this->assertSamaExcel($x['ci'], (float) $app[$kunci]['ci'], "{$ke}: ci");
            $this->assertSamaExcel($x['ui'], (float) $app[$kunci]['u'], "{$ke}: ui = u/pembagi");
        }

        $this->assertSame(array_keys($w['excel']['komponen']['depth']), array_keys($app), 'depth: urutan komponen');
    }

    /**
     * SELISIH sengaja (P-12): repeatability Depth. Master `K43 = PERHITUNGAN!AD121` (sel
     * KOSONG) -> 0 selalu. Aplikasi memakai STDEV terbesar titik Depth. Diumpani bacaan
     * cache Excel sendiri (STDEV terbesar 0,005477 mm, `PERHITUNGAN!AI121`), Excel tetap
     * 0 sementara aplikasi ikut naik, dan U Depth NAIK (arah aman).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_repeatability_depth_master_nol_aplikasi_memakai_stdev_terbesar_dikunci(): void
    {
        $w = self::w();
        $x = $w['excel']['komponen']['depth']['pengulangan'];
        $this->assertSame(0.0, self::nilai($x['ui']), 'Excel: ui repeatability Depth = 0 (K43 menunjuk sel kosong)');

        $dasar = $this->hitung();
        $dariCache = $this->hitung(depthDariCacheExcel: true);

        $sdMaks = max(array_map(static fn (array $t): float => self::nilai($t['stdev_mm']), $w['excel']['depth']));
        $this->assertGreaterThan(0.0, $sdMaks, 'cache Excel punya sebaran Depth (AI106:AI120)');

        $app = $this->budget($dariCache, 'depth')['pengulangan'];
        $this->assertSamaHitungUlang($sdMaks / sqrt(10.0), (float) $app['u'], 'ui aplikasi = STDEV terbesar titik Depth / akar 10 (pembagi n = 10 dipatok)');
        $this->assertGreaterThan(self::nilai($x['ui']), (float) $app['u'], 'aplikasi > Excel (0)');
        $this->assertGreaterThan(
            $dasar['grup']['depth']['ketidakpastian_diperluas'],
            $dariCache['grup']['depth']['ketidakpastian_diperluas'],
            'U Depth naik begitu ada sebaran',
        );
    }

    // ------------------------------------------------------------------ tahap agregat

    /**
     * uc, v_eff, k, U (sebelum lantai) dan U sertifikat Outside dan Inside: diadu ke rumus
     * sel DENGAN drift aplikasi (satu-satunya penyebab beda terhadap cache Excel).
     */
    public function test_uc_veff_k_u_outside_dan_inside_cocok_rumus_sel_dengan_drift_aplikasi(): void
    {
        $hasil = $this->hitung();
        $skenario = self::w()['dengan_drift_aplikasi'];

        foreach (['outside', 'inside'] as $grup) {
            $g = $hasil['grup'][$grup];
            $s = $skenario[$grup];
            $ke = "{$grup}";

            $this->assertSamaHitungUlang($s['uc'], $g['ketidakpastian_gabungan'], "{$ke}: uc (rumus sel dengan drift aplikasi)");
            $this->assertSamaHitungUlang($s['veff'], (float) $g['derajat_kebebasan_efektif'], "{$ke}: v_eff");
            $this->assertSamaHitungUlang($s['k'], $g['faktor_cakupan_k'], "{$ke}: k (TINV, df dipotong)", 0.0, self::TOL_K);
            $this->assertSamaHitungUlang($s['u_hitung'], $g['ketidakpastian_gabungan'] * $g['faktor_cakupan_k'], "{$ke}: U = k*uc", self::TOL_K_REL);
            $this->assertSamaHitungUlang($s['u_sertifikat'], $g['u95_sertifikat'], "{$ke}: U sertifikat (kapasitas 600 mm: tanpa lantai CMC, sama dengan AA21/AA40)", self::TOL_K_REL);
            $this->assertTrue($g['boleh_terbit'], "{$ke}: boleh terbit — ".implode(' ', $g['alasan_tahan']));
        }
    }

    /**
     * Arah selisih drift (P-11) pada tingkat agregat: U aplikasi < U Excel (cache pada X11
     * tersimpan), selisih relatif ~0,24 %, dan angka CETAK `0.033` tetap sama.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_u_outside_inside_aplikasi_lebih_kecil_dari_excel_karena_drift_dikunci(): void
    {
        $hasil = $this->hitung();
        $w = self::w();

        foreach (['outside', 'inside'] as $grup) {
            $a = $w['excel']['agregat'][$grup];
            $g = $hasil['grup'][$grup];
            $selisih = (self::nilai($a['u_hitung']) - $g['ketidakpastian_diperluas']) / self::nilai($a['u_hitung']);

            $this->assertGreaterThan(0.0, $selisih, "{$grup}: U aplikasi lebih kecil dari {$a['u_hitung']['sel']}");
            $this->assertLessThan(0.005, $selisih, "{$grup}: selisih relatif U < 0,5 % ({$selisih})");
            $this->assertGreaterThan(0.001, $selisih, "{$grup}: selisih relatif U > 0,1 % ({$selisih}) — drift/365 vs /12");
        }
    }

    /** Depth: tidak ada faktor umur di drift (`MAX(H106:H120)` tanpa umur) -> uc, veff, k, U SAMA dengan cache Excel. */
    public function test_uc_veff_k_u_depth_cocok_excel(): void
    {
        $g = $this->hitung()['grup']['depth'];
        $a = self::w()['excel']['agregat']['depth'];

        $this->assertSamaExcel($a['uc'], $g['ketidakpastian_gabungan'], 'depth: uc');
        $this->assertSamaExcel($a['veff'], (float) $g['derajat_kebebasan_efektif'], 'depth: v_eff');
        $this->assertKSamaExcel($a['k'], $g['faktor_cakupan_k'], 'depth: k (TINV, df dipotong)');
        $this->assertUSamaExcel($a['u_hitung'], $g['ketidakpastian_gabungan'] * $g['faktor_cakupan_k'], 'depth: U = k*uc');
        $this->assertUSamaExcel($a['u_sertifikat'], $g['u95_sertifikat'], 'depth: U sertifikat (tanpa lantai CMC)');
    }

    /**
     * SELISIH sengaja (P-13): lantai CMC 0,015 mm. Master memasang CMC di `DATABASE!T5` tetapi
     * `AA20/AA39/AA59` kosong dan `AA21 = MAX(AA19:AA20)/T23`, jadi tidak ada lantai. Aplikasi
     * memasangnya untuk kapasitas <= 300 mm. Pada sesi sampel U > CMC di ketiga budget, jadi
     * lantai TIDAK mengikat dan angka yang diterbitkan sama; pembedanya hanya status akreditasi
     * (> 300 mm: tanpa klaim akreditasi).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_lantai_cmc_dipasang_aplikasi_tidak_tersambung_di_master_dan_tidak_mengikat_di_sampel(): void
    {
        $w = self::w();
        $lantai = $w['excel']['cetak']['lantai_cmc_di_sheet'];

        $this->assertSame([null, null, null], $lantai['nilai'], 'Excel: sel CMC budget (AA20, AA39, AA59) kosong');
        foreach ($lantai['rumus_sertifikat'] as $rumus) {
            $this->assertMatchesRegularExpression('/^=IF\(AF\d+="mm",MAX\(AA\d+:A[AE]\d+\)\/DATABASE!\$T\$23/', $rumus, 'U sertifikat = MAX(U; sel CMC kosong)');
        }

        $cmcMm = self::nilai($w['konstanta']['cmc_um']) / 1000.0;
        $asli = $this->hitung();
        $pita300 = $this->hitung(false, ['kapasitas_mm' => 300.0]);

        $this->assertNull($asli['pita_cmc'], 'kapasitas 600 mm: di luar pita CMC');
        $this->assertNotNull($pita300['pita_cmc'], 'kapasitas 300 mm: dalam pita CMC');

        foreach (['outside', 'inside', 'depth'] as $grup) {
            $this->assertGreaterThan($cmcMm, $asli['grup'][$grup]['ketidakpastian_diperluas'], "{$grup}: U hitung > CMC {$cmcMm} mm, lantai tidak mengikat");
            $this->assertSame(
                $asli['grup'][$grup]['u95_sertifikat'],
                $pita300['grup'][$grup]['u95_sertifikat'],
                "{$grup}: U yang diterbitkan sama dengan atau tanpa lantai pada sampel ini",
            );
        }

        // 600 mm terbit tetapi di luar lingkup akreditasi (tanpa logo/nomor KAN): master tidak membedakan.
        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = ['jangka_sorong' => ['kapasitas_mm' => 600, 'resolusi_mm' => 0.05]];
        $this->assertFalse((new JangkaSorongProfile)->dalamLingkupAkreditasiSesi($sesi), '600 mm di luar 0-300 mm akreditasi');
    }

    // ------------------------------------------------------------------ kesejajaran dan kerataan

    public function test_kesejajaran_dan_kerataan_cocok_excel(): void
    {
        $w = self::w();
        $hasil = $this->hitung();

        foreach ($w['excel']['cetak']['kesejajaran'] as $i => $c) {
            $app = $hasil['kesejajaran'][$i];
            $this->assertSamaExcel($c['nominal'], $app['nominal'], "kesejajaran baris {$i}: nominal");
            $this->assertSamaExcel($c['pembacaan'], $app['pembacaan'], "kesejajaran baris {$i}: pembacaan");
            $this->assertSamaExcel($c['koreksi'], $app['koreksi'], "kesejajaran baris {$i}: koreksi (nominal - pembacaan)");
        }

        // Kerataan: master mencetak teks `Z22` (checkbox Baik) — hanya checkbox PERTAMA dibaca.
        $this->assertTrue($w['masukan']['kerataan_baik_dicentang'], 'INPUT DATA!Y22 (Baik) dicentang');
        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = ['jangka_sorong' => ['kerataan_muka_ukur' => 'baik']];
        $catatan = (new JangkaSorongProfile)->catatanSertifikat($sesi);

        $this->assertSame($w['excel']['cetak']['kerataan_teks']['nilai'], $catatan[0]['tabel'][0][1], 'kerataan muka ukur tercetak vs '.$w['excel']['cetak']['kerataan_teks']['sel']);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Outside: teks cetak SAMA PERSIS dengan `SERTIFIKAT!D25:L34` (format `0.00`), U95
     * `J35` (`0.000`), pada pemformat produksi `TataLetakSertifikat::formatAngka`. k (`O36`)
     * berformat `General` di kolom sempit: nilainya diadu; tampilan `2` hanya klaim repo
     * (B3a/R9) — MENUNGGU render Excel.
     */
    public function test_angka_cetak_outside_sama_dengan_sertifikat_excel(): void
    {
        $w = self::w();
        $hasil = $this->hitung();
        $profil = new JangkaSorongProfile;
        $sesi = new CalibrationSession;
        $app = collect($hasil['grup']['outside']['titik'])->keyBy('titik_ke');

        foreach ($w['excel']['cetak']['outside'] as $c) {
            $h = $app[$c['titik_ke']];
            $format = $profil->formatKolomSertifikat($sesi, $h['total_nominal']);

            foreach (['standar' => 'standar_terkoreksi', 'uut' => 'rata_rata', 'koreksi' => 'koreksi'] as $kolom => $kunci) {
                $this->assertSame($c[$kolom]['format'], $format[$kolom] ?? null, "outside titik {$c['titik_ke']} {$kolom}: format sel {$c[$kolom]['sel']}");
                $teksApp = self::keTitik(TataLetakSertifikat::formatAngka($h[$kunci], $format[$kolom], $profil->tandaNolDicetak()));
                $teksExcel = $c[$kolom]['nol_bertanda_minus'] ? ltrim($c[$kolom]['teks'], '-') : $c[$kolom]['teks'];

                $this->assertSame($teksExcel, $teksApp, "outside titik {$c['titik_ke']} {$kolom}: teks cetak vs {$c[$kolom]['sel']} ({$c[$kolom]['nilai']})");
            }
        }

        $formatU = $profil->formatKolomSertifikat($sesi, 0.0);
        $u = $w['excel']['cetak']['u95']['outside'];
        $this->assertSame($u['format'], $formatU['u95'], "format sel {$u['sel']}");
        $this->assertSame(
            $u['teks'],
            self::keTitik(TataLetakSertifikat::formatAngka($hasil['grup']['outside']['u95_sertifikat'], $formatU['u95'], $profil->tandaNolDicetak())),
            "teks cetak U95 Outside vs {$u['sel']} ({$u['nilai']}) — drift aplikasi menggeser nilai, bukan angka cetak",
        );

        // k: drift aplikasi menurunkan v_eff dari 150,04 ke 148,53; df dipotong ke 150 (Excel) lawan 148
        // (aplikasi), jadi k BEDA 2,2e-4 — akibat P-11, bukan selisih algoritma TINV. Diadu ke rumus sel
        // dengan drift aplikasi; angka cetak (format 0, kolom sempit) sama-sama `2`.
        $k = $w['excel']['cetak']['k']['outside'];
        $kApp = $hasil['grup']['outside']['faktor_cakupan_k'];
        $this->assertSamaHitungUlang($w['dengan_drift_aplikasi']['outside']['k'], $kApp, 'k Outside = TINV rumus sel dengan drift aplikasi', 0.0, self::TOL_K);
        $this->assertLessThan(5e-4, abs(self::nilai($k) - $kApp), 'k Outside vs '.$k['sel'].': beda hanya karena df (P-11)');
        $this->assertSame('2', TataLetakSertifikat::formatAngka($kApp, $profil->formatKolomSertifikat($sesi, 0.0)['k'], true), 'k tercetak (format 0) — klaim repo, MENUNGGU render Excel');
    }

    /**
     * Baris Outside yang di Excel (emulasi) tercetak `-0.00` — jumlahnya dikunci agar
     * perubahan terlihat (P-5 di docs/paritas-micrometer-ph.md; sesi sampel ini: nol baris).
     */
    public function test_baris_nol_bertanda_minus_outside_dicatat(): void
    {
        $jumlah = 0;

        foreach (self::w()['excel']['cetak']['outside'] as $c) {
            $jumlah += (int) $c['koreksi']['nol_bertanda_minus'];
        }

        $this->assertSame(0, $jumlah, 'jumlah baris koreksi Outside yang membulat ke nol berbalik minus (emulasi)');
    }

    /**
     * SELISIH P-14 (baru): blok Inside dan Depth sheet `SERTIFIKAT` (dan salinannya
     * `SERTIFIKAT (2)`) merujuk kolom `PERHITUNGAN` yang SALAH. Outside: D->AH (standar
     * terkoreksi), I->U (rata-rata), L->AJ (koreksi). Inside dan Depth: D->AC (delta-suhu),
     * I->N (SATU bacaan mentah X3'), L->AE (lw). Nilai-cache tercetak: Standar Reading 0,00 dan
     * Correction 0,00 di semua baris; UUT = satu bacaan. Aplikasi mencetak nilai yang benar.
     * Tes mengunci rujukan, nilai tercetak Excel, dan bahwa aplikasi BEDA.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_cetak_inside_dan_depth_master_salah_rujuk_kolom_dikunci(): void
    {
        $w = self::w();
        $hasil = $this->hitung();
        $profil = new JangkaSorongProfile;
        $sesi = new CalibrationSession;
        $benar = ['outside' => ['D' => 'AH', 'I' => 'U', 'L' => 'AJ'], 'inside' => ['D' => 'AC', 'I' => 'N', 'L' => 'AE'], 'depth' => ['D' => 'AC', 'I' => 'N', 'L' => 'AE']];

        foreach (['outside', 'inside', 'depth'] as $grup) {
            foreach ($w['excel']['cetak'][$grup] as $c) {
                foreach (['rumus', 'rumus_lembar_2'] as $lembar) {
                    foreach ($benar[$grup] as $kolom => $kolomHitung) {
                        if ($grup === 'outside' && $kolom === 'L' && $c['titik_ke'] === 1) {
                            // Baris pertama Outside: Correction = Standar - UUT di sel sendiri (titik nol).
                            $this->assertSame('=D25-I25', $c[$lembar][$kolom], 'SERTIFIKAT!L25');

                            continue;
                        }

                        $this->assertMatchesRegularExpression(
                            '/PERHITUNGAN!'.$kolomHitung.'\d+\/DATABASE/',
                            (string) $c[$lembar][$kolom],
                            "{$grup} titik {$c['titik_ke']} SERTIFIKAT".($lembar === 'rumus' ? '' : ' (2)')." kolom {$kolom}: rujukan kolom PERHITUNGAN",
                        );
                    }
                }
            }
        }

        foreach (['inside', 'depth'] as $grup) {
            $app = collect($hasil['grup'][$grup]['titik'])->keyBy('titik_ke');
            $nominalBeda = 0;
            $uutBeda = 0;

            foreach ($w['excel']['cetak'][$grup] as $c) {
                $h = $app[self::OFFSET[$grup] + $c['titik_ke']];
                $format = $profil->formatKolomSertifikat($sesi, $h['total_nominal']);

                // Excel: Standar Reading dan Correction = 0 di SEMUA baris.
                $this->assertSame('0.00', $c['standar']['teks'], "{$grup} titik {$c['titik_ke']}: Excel Standar Reading ({$c['standar']['sel']}) = 0.00");
                $this->assertSame('0.00', $c['koreksi']['teks'], "{$grup} titik {$c['titik_ke']}: Excel Correction ({$c['koreksi']['sel']}) = 0.00");

                $standarApp = self::keTitik(TataLetakSertifikat::formatAngka($h['standar_terkoreksi'], $format['standar'], false));
                $uutApp = self::keTitik(TataLetakSertifikat::formatAngka($h['rata_rata'], $format['uut'], false));
                $nominalBeda += (int) ($standarApp !== $c['standar']['teks']);
                $uutBeda += (int) ($uutApp !== $c['uut']['teks']);
            }

            $this->assertGreaterThan(0, $nominalBeda, "{$grup}: aplikasi mencetak Standar Reading yang BEDA dari Excel (Excel selalu 0.00)");
            if ($grup === 'inside') {
                // Depth: bacaan lokal identik per titik, jadi rata-rata = satu bacaan dan teksnya kebetulan sama.
                $this->assertGreaterThan(0, $uutBeda, "{$grup}: aplikasi mencetak rata-rata, Excel mencetak satu bacaan (X3')");
            }
        }

        // Cabang `inch` sel L28 juga salah rujuk (V46 = ci Depth, bukan AJ46); cabang mm benar.
        $this->assertStringContainsString('PERHITUNGAN!V46/DATABASE!$T$24', (string) $w['excel']['cetak']['rumus_inch_l28']['rumus'], 'SERTIFIKAT!L28 cabang inch merujuk V46');
        $this->assertStringContainsString('PERHITUNGAN!AJ46/DATABASE!$T$23', (string) $w['excel']['cetak']['rumus_inch_l28']['rumus'], 'SERTIFIKAT!L28 cabang mm merujuk AJ46 (benar)');
    }

    /** U95 Inside dan Depth (`J51`, `J62`) memang merujuk budgetnya sendiri (benar) — teks cetak SAMA. */
    public function test_u95_cetak_inside_dan_depth_sama_dengan_excel(): void
    {
        $hasil = $this->hitung();
        $profil = new JangkaSorongProfile;
        $formatU = $profil->formatKolomSertifikat(new CalibrationSession, 0.0);

        foreach (['inside', 'depth'] as $grup) {
            $u = self::w()['excel']['cetak']['u95'][$grup];
            $this->assertSame($u['format'], $formatU['u95'], "format sel {$u['sel']}");
            $this->assertSame(
                $u['teks'],
                self::keTitik(TataLetakSertifikat::formatAngka($hasil['grup'][$grup]['u95_sertifikat'], $formatU['u95'], $profil->tandaNolDicetak())),
                "teks cetak U95 {$grup} vs {$u['sel']} ({$u['nilai']})",
            );
        }
    }
}
