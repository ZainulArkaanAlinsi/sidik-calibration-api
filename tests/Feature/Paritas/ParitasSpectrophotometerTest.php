<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\EquipmentCategory;
use App\Services\Acuan\FormatSertifikatAcuan;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use App\Support\TataLetakSertifikat;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\SpectrophotometerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Spectrophotometer: aplikasi lawan `Master Olah Data_Spectrofotometer.xlsm`,
 * tahap demi tahap. Fixture `tests/Fixtures/paritas/spectrophotometer.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-spectrophotometer.py` dari nilai-cache workbook
 * (tiap angka menyimpan sel asalnya `SHEET!SEL`).
 *
 * Titik panggil terkecil tanpa HTTP: `SpectrophotometerCalculator::hitungGrup()`
 * (statistik titik, budget, agregasi GUM, CMC) dan `SpectrophotometerProfile`
 * (nominal, resolusi, format kolom sertifikat, R2). Tiga grup: Holmium (10 titik),
 * Didymium (9 titik), Accuracy %T (5 titik). Angka cetak diuji pada pemformat
 * produksi (`Angka`, `TataLetakSertifikat`).
 *
 * Selisih yang dikunci (nomor dan uraian di `docs/paritas-spectrophotometer.md`):
 *  P-30 MAX STDEV Holmium: workbook membuang titik pertama (`M36 =MAX(M27:M35)`),
 *       aplikasi memakai semua titik (keputusan bawaan #15).
 *  P-31 R2 %T: sel `SERTIFIKAT!R48` konstanta ketikan 0,9359, aplikasi menghitung
 *       RSQ dari data (keputusan bawaan #24).
 *  P-32 Tanggal terbit: aplikasi +1 hari (sama dengan `SERTIFIKAT!B70`), `INPUT DATA!D80`
 *       di workbook +4 hari (keputusan bawaan #4).
 *  P-33 Nol bertanda minus: teks Excel "-0.0" pada dua koreksi %T, aplikasi mencetak "0.0".
 *  P-34 Titik, nilai standar, dan jumlah ulangan: master lawan kertas Rev.5. Aplikasi
 *       memakai angka master.
 *  P-35 Resolusi: konstanta workbook (`INPUT DATA!G16` 0,01 dan `E16` 0,001) sama dengan
 *       aplikasi; kepastian nilainya menunggu keputusan #27 (hanya dicatat di dokumen).
 *  P-36 CMC Didymium: master 0,40 (sama dengan aplikasi) lawan 0,38 di lampiran
 *       LK-285-IDN butir 47 (keputusan bawaan #32, tertulis di pesan tes CMC).
 *  P-37 Titik di luar rentang kemampuan CMC (Holmium 1, %T 3 titik); MENUNGGU karena
 *       workbook tidak punya rentang, ini fakta sisi aplikasi saja.
 *  P-38 Tampilan MAX STDEV (keputusan bawaan #43): hanya dokumen, tidak ada tes.
 */
#[Group('paritas')]
class ParitasSpectrophotometerTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Kunci grup di fixture lawan konstanta grup kalkulator (ejaan aplikasi: didynium). */
    private const PETA_GRUP = [
        'holmium' => SpectrophotometerCalculator::GRUP_HOLMIUM,
        'didymium' => SpectrophotometerCalculator::GRUP_DIDYNIUM,
        'transmitan' => SpectrophotometerCalculator::GRUP_TRANSMITAN,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(CalibrationCapabilitySeeder::class);
        $this->seed(SpectrophotometerCapabilitySeeder::class);

        ParameterKeputusanMetode::lupakan();
        FormatSertifikatAcuan::lupakan();
        config(['kalibrasi.r2_spektro' => SpectrophotometerProfile::R2_RSQ_STANDAR_UUT]);
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        FormatSertifikatAcuan::lupakan();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- bantu

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('spectrophotometer')['workbook'];
    }

    /** @return array<string, mixed> */
    private static function grupFixture(string $kunci): array
    {
        return self::w()['grup'][$kunci];
    }

    /** @return list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>}> */
    private static function titikInput(string $kunci): array
    {
        return array_map(
            static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => self::nilai($t['standar']),
                'pembacaan' => array_map('floatval', $t['pembacaan']['nilai']),
            ],
            self::grupFixture($kunci)['titik'],
        );
    }

    /** @return array<string, mixed> */
    private static function spek(string $kunci, array $tambahan = []): array
    {
        $g = self::grupFixture($kunci);

        return [
            'grup' => self::PETA_GRUP[$kunci],
            'satuan' => $g['satuan'],
            'u_standar' => self::nilai($g['konstanta']['u_standar']),
            'resolusi' => self::nilai($g['konstanta']['resolusi']),
            'cmc' => self::nilai($g['konstanta']['cmc']),
        ] + $tambahan;
    }

    /** @return array<string, mixed> */
    private function hitung(string $kunci, ?array $titik = null, array $tambahanSpek = []): array
    {
        return (new SpectrophotometerCalculator)->hitungGrup(
            $titik ?? self::titikInput($kunci),
            self::spek($kunci, $tambahanSpek),
        );
    }

    /** @return array<string, array<string, mixed>> komponen budget aplikasi, kunci = `sumber`. */
    private static function budgetPerSumber(array $hasil): array
    {
        $peta = [];
        foreach ($hasil['budget'] as $komponen) {
            $peta[$komponen['sumber']] = $komponen;
        }

        return $peta;
    }

    /** Jumlah desimal dari kode format sel ("0.0" = 1, "0" = 0). */
    private static function desimalFormat(string $format): int
    {
        $titik = strpos($format, '.');

        return $titik === false ? 0 : strlen($format) - $titik - 1;
    }

    private static function pearsonR2(array $x, array $y): float
    {
        $n = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        foreach ($x as $i => $xi) {
            $sxy += ($xi - $mx) * ($y[$i] - $my);
            $sxx += ($xi - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }

        return ($sxy * $sxy) / ($sxx * $syy);
    }

    // ------------------------------------------------------ profil dan titik

    public function test_nominal_resolusi_dan_grup_titik_profil_sama_dengan_workbook(): void
    {
        $profil = new SpectrophotometerProfile;

        foreach (self::PETA_GRUP as $kunci => $konstanta) {
            $g = self::grupFixture($kunci);
            $nominalApp = SpectrophotometerProfile::TITIK[$konstanta]['nilai'];

            $this->assertCount(count($g['titik']), $nominalApp, "{$kunci}: jumlah titik aplikasi lawan workbook (P-34: master, bukan kertas Rev.5).");

            foreach ($g['titik'] as $i => $t) {
                $ke = "{$kunci} titik {$t['titik_ke']}";
                $this->assertSamaExcel($t['standar'], (float) $nominalApp[$i], "{$ke} nominal");
                $this->assertSame($konstanta, $profil->grupTitik($nominalApp[$i]), "{$ke}: grup titik.");
                $this->assertSamaExcel($g['konstanta']['resolusi'], (float) $profil->resolusiTitik($nominalApp[$i]), "{$ke} resolusi");
            }
        }
    }

    public function test_p34_jumlah_ulangan_dan_kode_dokumen_mengikuti_master(): void
    {
        $this->assertSame(3, SpectrophotometerProfile::JUMLAH_PENGULANGAN);
        $this->assertSame(6, SpectrophotometerProfile::PENGULANGAN_TRANSMITAN);

        foreach (self::PETA_GRUP as $kunci => $konstanta) {
            $harapan = $konstanta === SpectrophotometerCalculator::GRUP_TRANSMITAN ? 6 : 3;
            foreach (self::grupFixture($kunci)['titik'] as $t) {
                $this->assertSame($harapan, $t['jumlah_pembacaan'], "{$kunci} titik {$t['titik_ke']}: jumlah pembacaan di workbook.");
            }
            $this->assertSame($harapan, SpectrophotometerProfile::TITIK[$konstanta]['pengulangan'], "{$kunci}: pengulangan profil.");
        }

        $this->assertSame('SIDIK-FM-CAL-0511_Rev.5', SpectrophotometerProfile::KODE_DOKUMEN);
        $this->assertSame('SIDIK-IK-CAL-0508_Rev.4', SpectrophotometerProfile::KODE_METODE);
    }

    // --------------------------------------------------------- statistik titik

    public function test_statistik_tiap_titik_sama_dengan_workbook(): void
    {
        foreach (array_keys(self::PETA_GRUP) as $kunci) {
            $hasil = $this->hitung($kunci);
            $fixture = self::grupFixture($kunci)['titik'];

            $this->assertCount(count($fixture), $hasil['titik'], "{$kunci}: jumlah baris titik.");

            foreach ($fixture as $i => $t) {
                $ke = "{$kunci} titik {$t['titik_ke']}";
                $app = $hasil['titik'][$i];

                $this->assertSamaExcel($t['rata_rata'], $app['rata_rata'], "{$ke} rata-rata");
                $this->assertSamaExcel($t['koreksi'], $app['koreksi'], "{$ke} koreksi");
                $this->assertSamaExcel($t['standar_deviasi'], $app['standar_deviasi'], "{$ke} stdev sampel");
                $this->assertSame($t['jumlah_pembacaan'], $app['jumlah_pengulangan'], "{$ke}: jumlah pengulangan.");
            }
        }
    }

    public function test_standar_deviasi_maks_tiap_grup_sama_dengan_workbook(): void
    {
        foreach (array_keys(self::PETA_GRUP) as $kunci) {
            $g = self::grupFixture($kunci);
            $hasil = $this->hitung($kunci);

            $this->assertSamaExcel($g['stdev_maks'], $hasil['standar_deviasi_maks'], "{$kunci} MAX STDEV");
        }
    }

    // ------------------------------------------------------------------ budget

    public function test_komponen_budget_tiap_grup_sama_dengan_workbook(): void
    {
        foreach (array_keys(self::PETA_GRUP) as $kunci) {
            $g = self::grupFixture($kunci);
            $app = self::budgetPerSumber($this->hitung($kunci));

            $this->assertEqualsCanonicalizing(array_keys($g['komponen']), array_keys($app), "{$kunci}: himpunan sumber komponen.");

            foreach ($g['komponen'] as $sumber => $k) {
                $ke = "{$kunci} {$sumber}";
                $a = $app[$sumber];

                $this->assertSamaExcel($k['ui'], (float) $a['u'], "{$ke} ui");
                $this->assertSamaExcel($k['ci'], (float) $a['ci'], "{$ke} ci");
                $this->assertSamaExcel($k['vi'], (float) $a['vi'], "{$ke} vi");
                $this->assertSamaExcel($k['uici'], (float) $a['u'] * (float) $a['ci'], "{$ke} ui*ci");
                $this->assertSame($k['disertakan'], $a['disertakan'], "{$ke}: disertakan di jumlah (sel {$k['uici']['sel']}).");
            }
        }
    }

    /**
     * Keanehan master yang DITIRU, bukan dibetulkan: kolom Type A memakai
     * `=E/SQRT(F)` dengan F = SQRT(3), jadi pembagi efektifnya 3^0,25 (bukan SQRT(3)).
     * Angka aplikasi sama dengan workbook, jadi statusnya SAMA; keanehannya dicatat
     * di dokumen sebagai butir "ditiru".
     */
    public function test_pembagi_type_a_master_adalah_tiga_pangkat_seperempat(): void
    {
        foreach (['holmium', 'didymium'] as $kunci) {
            $k = self::grupFixture($kunci)['komponen']['standar_deviasi_maks'];

            $this->assertSamaHitungUlang(
                self::nilai($k['u_mentah']) / (3 ** 0.25),
                self::nilai($k['ui']),
                "{$kunci}: u_mentah / 3^0,25 lawan {$k['ui']['sel']}",
            );
            $this->assertSame('=E'.$k['baris'].'/SQRT(F'.$k['baris'].')', $k['rumus']['ui'], "{$kunci}: rumus sel ui di workbook.");
        }
    }

    // --------------------------------------------------------------- agregasi

    public function test_agregasi_gum_dan_cmc_tiap_grup_sama_dengan_workbook(): void
    {
        $kalkulator = new SpectrophotometerCalculator;

        foreach (array_keys(self::PETA_GRUP) as $kunci) {
            $a = self::grupFixture($kunci)['agregat'];
            $hasil = $this->hitung($kunci);

            $this->assertSamaExcel($a['uc'], $hasil['ketidakpastian_gabungan'], "{$kunci} uc");
            $this->assertSamaExcel($a['veff'], $hasil['derajat_kebebasan_efektif'], "{$kunci} veff");
            $this->assertKSamaExcel($a['k'], $hasil['faktor_cakupan_k'], "{$kunci} k");
            $this->assertUSamaExcel($a['u_hitung'], $hasil['ketidakpastian_diperluas'], "{$kunci} U hitung");
            $this->assertSamaExcel($a['cmc'], $hasil['cmc'], "{$kunci} CMC");
            $this->assertUSamaExcel($a['u_cetak'], $hasil['u95_sertifikat'], "{$kunci} U95 cetak = MAX(U, CMC)");

            $dariCmc = self::nilai($a['cmc']) > self::nilai($a['u_hitung']);
            $this->assertSame($dariCmc ? 'cmc' : 'hitung', $hasil['sumber_u95'], "{$kunci}: sumber U95.");

            $kode = array_column($hasil['catatan_audit'], 'kode');
            $this->assertContains('perbandingan_cmc', $kode, "{$kunci}: catatan perbandingan CMC.");
        }

        $w = self::w();
        $this->assertSamaExcel($w['celah_spectral_bandwidth']['q30'], $kalkulator->celahSpectralBandwidth(), 'celah spectral bandwidth');
    }

    /**
     * Keanehan master yang DITIRU: di blok %T `SUM` hanya baris 47-49 (keterulangan,
     * resolusi, drift); standar dan spectral bandwidth ada di tabel tetapi tidak
     * ikut dijumlah. Aplikasi mencatatnya sebagai `komponen_dikecualikan_master`.
     */
    public function test_blok_transmitan_meniru_jumlah_master_dan_mencatat_komponen_dikecualikan(): void
    {
        $g = self::grupFixture('transmitan');
        $hasil = $this->hitung('transmitan');

        $this->assertSame([47, 48, 49], $g['agregat']['rumus']['rentang_dijumlahkan'], 'baris yang dijumlah di workbook.');

        $dikecualikan = array_keys(array_filter($g['komponen'], static fn (array $k): bool => ! $k['disertakan']));
        sort($dikecualikan);
        $this->assertSame(['ketidakpastian_standar', 'spectral_bandwidth'], $dikecualikan);

        $entri = array_values(array_filter(
            $hasil['catatan_audit'],
            static fn (array $c): bool => $c['kode'] === 'komponen_dikecualikan_master',
        ));
        $this->assertCount(1, $entri, 'catatan audit komponen_dikecualikan_master harus ada tepat satu.');

        $komponen = $entri[0]['komponen'];
        sort($komponen);
        $this->assertSame($dikecualikan, $komponen);
    }

    // ------------------------------------------------------------- angka cetak

    public function test_angka_cetak_titik_u95_dan_k_sama_persis_dengan_teks_excel(): void
    {
        $profil = new SpectrophotometerProfile;
        $minus = 0;

        foreach (array_keys(self::PETA_GRUP) as $kunci) {
            $g = self::grupFixture($kunci);
            $hasil = $this->hitung($kunci);

            foreach ($g['titik'] as $i => $t) {
                $ke = "{$kunci} titik {$t['titik_ke']}";
                $app = $hasil['titik'][$i];
                $c = $t['cetak'];
                $format = $profil->formatKolomSertifikat(new CalibrationSession, $app['titik_ukur']);

                $desimalNominal = self::desimalFormat($c['nominal']['format']);
                $this->assertSame($desimalNominal, self::desimalFormat($format['standar']), "{$ke}: format kolom standar.");
                $this->assertSame(
                    $c['nominal']['teks'],
                    self::keTitik(Angka::hasil($app['titik_ukur'], $desimalNominal, '—', $profil->tandaNolDicetak())),
                    "{$ke}: nominal cetak vs {$c['nominal']['sel']}",
                );

                $desimalUut = self::desimalFormat($c['rata_rata']['format']);
                $this->assertSame($desimalUut, self::desimalFormat($format['uut']), "{$ke}: format kolom UUT.");
                $this->assertSame(
                    $c['rata_rata']['teks'],
                    self::keTitik(Angka::hasil($app['rata_rata'], $desimalUut, '—', $profil->tandaNolDicetak())),
                    "{$ke}: rata-rata cetak vs {$c['rata_rata']['sel']}",
                );

                $desimalKoreksi = self::desimalFormat($c['koreksi']['format']);
                $this->assertSame($desimalKoreksi, self::desimalFormat($format['koreksi']), "{$ke}: format kolom koreksi.");
                $teksKoreksi = $c['koreksi']['nol_bertanda_minus'] ? ltrim($c['koreksi']['teks'], '-') : $c['koreksi']['teks'];
                $minus += (int) $c['koreksi']['nol_bertanda_minus'];
                $this->assertSame(
                    $teksKoreksi,
                    self::keTitik(Angka::hasil($app['koreksi'], $desimalKoreksi, '—', $profil->tandaNolDicetak())),
                    "{$ke}: koreksi cetak vs {$c['koreksi']['sel']} (nol bertanda minus ditunjukkan di P-33)",
                );
            }

            $pertama = $hasil['titik'][0]['titik_ukur'];
            $format = $profil->formatKolomSertifikat(new CalibrationSession, $pertama);

            $this->assertSame($g['cetak']['u95']['format'], $format['u95'], "{$kunci}: format U95.");
            $this->assertSame(
                $g['cetak']['u95']['teks'],
                self::keTitik(TataLetakSertifikat::formatAngka($hasil['u95_sertifikat'], $format['u95'], true)),
                "{$kunci}: U95 cetak vs {$g['cetak']['u95']['sel']}",
            );

            $this->assertSame($g['cetak']['k']['format'], $format['k'], "{$kunci}: format k.");
            $this->assertSame(
                $g['cetak']['k']['teks'],
                self::keTitik(TataLetakSertifikat::formatAngka($hasil['faktor_cakupan_k'], $format['k'], true)),
                "{$kunci}: k cetak vs {$g['cetak']['k']['sel']}",
            );
        }

        $this->assertFalse($profil->tandaNolDicetak());
        $this->assertSame(2, $minus, 'Jumlah koreksi %T bertanda minus-nol di fixture (O48 dan O52).');
    }

    public function test_presisi_cetak_profil_sama_dengan_format_workbook(): void
    {
        $profil = new SpectrophotometerProfile;

        $this->assertSame(self::desimalFormat(self::grupFixture('holmium')['cetak']['u95']['format']), $profil->desimalU95());
        $this->assertSame(self::desimalFormat(self::grupFixture('holmium')['cetak']['k']['format']), $profil->desimalFaktorCakupan());
        $this->assertSame(
            self::desimalFormat(self::grupFixture('holmium')['titik'][0]['cetak']['koreksi']['format']),
            $profil->desimalSertifikat(),
        );
    }

    // ---------------------------------------------------- kemampuan CMC (DB)

    public function test_cmc_kemampuan_terseed_sama_dengan_cmc_workbook(): void
    {
        $kategori = EquipmentCategory::where('kode', 'instrumen-analitik')->firstOrFail();

        foreach (self::PETA_GRUP as $kunci => $konstanta) {
            $label = SpectrophotometerProfile::TITIK[$konstanta]['parameter_cmc'];
            $kemampuan = CalibrationCapability::where('equipment_category_id', $kategori->id)
                ->where('nama_alat', 'Spectrophotometer')
                ->where('parameter', $label)
                ->firstOrFail();

            $this->assertSamaExcel(
                self::grupFixture($kunci)['konstanta']['cmc'],
                (float) $kemampuan->ketidakpastian_terbaik,
                "{$kunci} CMC kemampuan terseed ('{$label}'); P-36: 0,40 Didymium mengikuti master, bukan 0,38 lampiran",
            );
        }
    }

    // ------------------------------------------------- selisih dikunci (P-3x)

    /**
     * SELISIH P-30 (MAX STDEV Holmium): `PERHITUNGAN!M36 =MAX(M27:M35)` membuang
     * titik pertama; aplikasi bawaannya `semua_titik`. Workbook M49 dan M67 memuat
     * semua titik. Dengan data master sendiri keduanya sama (titik pertama bukan
     * yang terbesar), jadi selisih baru tampak bila STDEV titik pertama terbesar.
     * Tes ini mengunci perilaku aplikasi SEKARANG; saklar
     * `spectrophotometer.max_stdev_holmium = tanpa_titik_pertama` menyamakannya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p30_max_stdev_holmium_aplikasi_memakai_semua_titik_workbook_membuang_titik_pertama(): void
    {
        $g = self::grupFixture('holmium');
        $m36 = $g['stdev_maks'];

        $this->assertSame([1], $g['stdev_maks']['titik_di_luar_rentang_maks'], 'Workbook membuang titik 1 dari MAX.');
        $this->assertSame('=MAX(M27:M35)', $m36['rumus'], 'Rumus M36 di workbook.');

        // Data master apa adanya: tidak ada selisih (titik 1 bukan yang terbesar).
        $this->assertSamaExcel($m36, $this->hitung('holmium')['standar_deviasi_maks'], 'holmium MAX STDEV data master');
        $this->assertSamaHitungUlang(self::nilai($m36), (float) $m36['maks_semua_titik'], 'holmium MAX semua titik = M36 pada data master');

        // Titik 1 dilebarkan: STDEV sampel [278, 280, 282] = 2,0.
        $lebar = self::titikInput('holmium');
        $lebar[0]['pembacaan'] = [278.0, 280.0, 282.0];

        $bawaan = $this->hitung('holmium', $lebar);
        $this->assertSame(2.0, $bawaan['standar_deviasi_maks'], 'SELISIH P-30: aplikasi bawaan memakai semua titik, titik 1 ikut MAX.');
        $this->assertGreaterThan(1.0, abs($bawaan['standar_deviasi_maks'] - self::nilai($m36)), "SELISIH P-30: Excel {$m36['sel']} tetap ".self::nilai($m36).' bila titik 1 dilebarkan.');

        // Saklar keputusan: tanpa titik pertama = perilaku workbook.
        $tanpa = $this->hitung('holmium', $lebar, ['stdev_maks_kecualikan_nm' => [279.6]]);
        $this->assertSamaExcel($m36, $tanpa['standar_deviasi_maks'], 'holmium MAX STDEV tanpa titik pertama (data lebar)');
        $this->assertContains('max_stdev_tanpa_titik_pertama', array_column($tanpa['catatan_audit'], 'kode'));

        // Keputusan bawaan terdaftar dan dipakai profil.
        $this->assertSame('semua_titik', app(ParameterKeputusanMetode::class)->putusan(SpectrophotometerProfile::PARAMETER_MAX_STDEV_HOLMIUM)->nilai);
        $spek = new ReflectionMethod(SpectrophotometerProfile::class, 'spekStdevMaks');
        $profil = new SpectrophotometerProfile;
        $this->assertSame([], $spek->invoke($profil, SpectrophotometerCalculator::GRUP_HOLMIUM), 'SELISIH P-30: profil tidak menambah pengecualian.');
        $this->assertSame([], $spek->invoke($profil, SpectrophotometerCalculator::GRUP_DIDYNIUM));
        $this->assertSame([], $spek->invoke($profil, SpectrophotometerCalculator::GRUP_TRANSMITAN));
    }

    /**
     * SELISIH P-31 (R2 %T): `SERTIFIKAT!R48` adalah konstanta ketikan 0,9359, bukan
     * rumus (tidak ada sel RSQ/CORREL/LINEST di workbook). Aplikasi menghitung RSQ dari
     * data (rata-rata UUT lawan standar). Selisih tidak menggeser angka cetak: format
     * "0" mencetak 1 untuk keduanya. Pembanding RSQ di sini hitung ulang Pearson,
     * BUKAN nilai cache Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p31_r2_transmitan_aplikasi_hitung_rsq_workbook_konstanta_ketikan(): void
    {
        $g = self::grupFixture('transmitan');
        $r2 = self::w()['catatan_sel']['r2_transmitan'];

        $this->assertNull($r2['rumus'], 'SERTIFIKAT!R48 bukan rumus di workbook (konstanta ketikan).');

        $baris = [];
        $x = [];
        $y = [];
        foreach ($g['titik'] as $t) {
            $baris[] = ['standard_value' => self::nilai($t['standar']), 'unit_under_test' => self::nilai($t['rata_rata'])];
            $x[] = self::nilai($t['standar']);
            $y[] = self::nilai($t['rata_rata']);
        }

        $app = (new SpectrophotometerProfile)->koefisienDeterminasi($baris);
        $this->assertNotNull($app);

        $this->assertSamaHitungUlang(self::pearsonR2($x, $y), $app, 'R2 %T: aplikasi lawan hitung ulang Pearson (tanpa sel Excel)');
        $this->assertGreaterThan(0.06, abs(self::nilai($r2) - $app), "SELISIH P-31: Excel {$r2['sel']} = ".self::nilai($r2).", aplikasi = {$app}.");

        // Angka cetak sama walau nilainya beda: format 0 desimal.
        $this->assertSame($r2['teks'], Angka::id($app, self::desimalFormat($r2['format'])), 'R2 cetak lawan teks Excel R48.');
    }

    /**
     * SELISIH P-32 (tanggal terbit): aplikasi +1 hari = rumus `SERTIFIKAT!B70 =R9+1`.
     * `INPUT DATA!D80 =O16+4` di workbook memakai +4. Aplikasi mengikuti sheet cetak
     * (keputusan bawaan #4, `sertifikat.tambah_hari.spectrophotometer`).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p32_tanggal_terbit_aplikasi_tambah_satu_hari_input_data_tambah_empat(): void
    {
        $catatan = self::w()['catatan_sel'];
        $app = FormatMasterSertifikat::tanggalUntukProfil('spectrophotometer')['tambah_hari'];

        $this->assertSame(1, preg_match('/\+(\d+)$/', $catatan['tanggal_terbit_sertifikat_rumus']['rumus'], $cetak));
        $this->assertSame(1, preg_match('/\+(\d+)$/', $catatan['tanggal_terbit_input_rumus']['rumus'], $input));

        $this->assertSame((int) $cetak[1], $app, "Aplikasi lawan {$catatan['tanggal_terbit_sertifikat_rumus']['sel']}.");
        $this->assertNotSame((int) $input[1], $app, "SELISIH P-32: {$catatan['tanggal_terbit_input_rumus']['sel']} menambah {$input[1]} hari, aplikasi {$app} hari.");
    }

    /**
     * MENUNGGU P-33 (nol bertanda minus): Excel menampilkan "-0.0" untuk koreksi
     * negatif yang dibulatkan ke nol (dua titik %T). Aplikasi mencetak "0.0"
     * (`tandaNolDicetak() = false`). Keterbatasan bukti: "-0.0" disimulasikan generator
     * dari format sel dan nilai cache; tidak ada teks tampilan yang tersimpan di workbook.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p33_nol_bertanda_minus_excel_dicetak_tanpa_minus_oleh_aplikasi(): void
    {
        $g = self::grupFixture('transmitan');
        $hasil = $this->hitung('transmitan');
        $profil = new SpectrophotometerProfile;
        $ditemukan = [];

        foreach ($g['titik'] as $i => $t) {
            if (! $t['cetak']['koreksi']['nol_bertanda_minus']) {
                continue;
            }

            $ditemukan[] = $t['cetak']['koreksi']['sel'];
            $this->assertSame('-0.0', $t['cetak']['koreksi']['teks']);
            $this->assertSame(
                '0.0',
                self::keTitik(Angka::hasil($hasil['titik'][$i]['koreksi'], 1, '—', $profil->tandaNolDicetak())),
                "P-33: aplikasi mencetak tanpa minus untuk {$t['cetak']['koreksi']['sel']}.",
            );
        }

        $this->assertSame(['SERTIFIKAT!O48', 'SERTIFIKAT!O52'], $ditemukan);
    }

    /**
     * MENUNGGU P-37 (rentang kemampuan CMC): titik di luar rentang kemampuan
     * terseed (Holmium 283-641 nm, %T 10,0-30,5) tetap punya baris di lembar
     * workbook. Workbook tidak punya rentang; ini fakta sisi aplikasi saja.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p37_titik_di_luar_rentang_kemampuan_cmc_dihitung_per_grup(): void
    {
        $kategori = EquipmentCategory::where('kode', 'instrumen-analitik')->firstOrFail();
        $di_luar = [];

        foreach (self::PETA_GRUP as $kunci => $konstanta) {
            $kemampuan = CalibrationCapability::where('equipment_category_id', $kategori->id)
                ->where('nama_alat', 'Spectrophotometer')
                ->where('parameter', SpectrophotometerProfile::TITIK[$konstanta]['parameter_cmc'])
                ->firstOrFail();

            $di_luar[$kunci] = count(array_filter(
                self::grupFixture($kunci)['titik'],
                static function (array $t) use ($kemampuan): bool {
                    $nilai = (float) $t['standar']['nilai'];

                    return $nilai < (float) $kemampuan->range_min || $nilai > (float) $kemampuan->range_max;
                },
            ));
        }

        $this->assertSame(
            ['holmium' => 1, 'didymium' => 0, 'transmitan' => 3],
            $di_luar,
            'MENUNGGU P-37: jumlah titik workbook di luar rentang kemampuan terseed.',
        );
    }
}
