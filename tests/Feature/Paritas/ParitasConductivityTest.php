<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\GumCalculator;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use App\Support\TataLetakSertifikat;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\ConductivityCapabilitySeeder;
use Database\Seeders\ConductivitySeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClassConstant;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Conductivity Meter: aplikasi lawan `Master Olah Data_Conductivity.xlsm`,
 * tahap demi tahap, untuk ketiga titik sesi contoh master (25 µS/cm, 1412 µS/cm,
 * 111 mS/cm; 5 pengulangan SESUDAH adjustment).
 *
 * Bukan duplikat `tests/Feature/ConductivityBudgetTest.php`: tes itu mengadu uc/veff/
 * k/U dengan angka yang diketik tangan di kelasnya. Di sini tiap tahap diadu dengan
 * nilai-cache sel workbook (alamat di pesan galat) dan toleransi bertulis: nilai
 * standar pada suhu larutan, rata-rata, STDEV, koreksi, keempat komponen budget
 * (ui, ci, vi, uici), uc, veff, k, U, lantai CMC, dan ANGKA CETAK. Titik panggil
 * terkecil tanpa HTTP: `GumCalculator::hitungTitik()` (fungsi yang sama dengan preview,
 * simpan, dan hitung ulang), dengan masukan disusun seperti `CalibrationController`
 * (suhu larutan = rata-rata lima suhu). Konstanta aplikasi dibaca dari SUMBER
 * produksi, bukan diketik ulang: polinomial dari `ConductivityProfile::KOEF_SUHU`,
 * U95 larutan dari `ConductivitySeeder::STANDAR`, CMC dan UTemperature dari
 * `ConductivityCapabilitySeeder`.
 *
 * ## Perilaku BAWAAN saja
 *
 * Parameter keputusan metode (PR #298, merge `3ab0a8f3`) bawaannya identik dengan kode
 * sebelumnya, jadi paritas ini adalah paritas perilaku bawaan: semua assertion ANGKA hijau
 * sebelum dan sesudah merge #298 (yang berubah hanya daftar baris jejak audit: bertambah
 * `parameter_metode` di `type_b_components`). Nilai acuan polinomial tidak disentuh.
 *
 * ## Jebakan `decimal(20,8)` (CI memakai MySQL)
 *
 * `calibration_capabilities.u_temperature` bertipe `decimal(20,8)`: MySQL menyimpan
 * 0,36124783736376886 sebagai 0,36124784 (galat mutlak <= 2,7e-9; relatif 7,3e-9);
 * SQLite menyimpan float penuh. Efek terburuk (titik 1412, bagian temperature 5 % dari
 * jumlah kuadrat): ui, ci relatif 7,3e-9; uici 1,5e-8; uc <= 3,7e-10; veff <= 1,5e-9.
 * Batas di bawah = efek itu dibulatkan ke atas, jauh di bawah galat satu konstanta
 * yang salah. Hanya komponen/tahap yang bergantung UTemperature memakainya.
 */
#[Group('paritas')]
class ParitasConductivityTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    private const TOL_SIMPAN_REL_UI = 1e-8;

    private const TOL_SIMPAN_REL_UICI = 2e-8;

    private const TOL_SIMPAN_REL_UC = 1e-9;

    private const TOL_SIMPAN_REL_VEFF = 4e-9;

    /** `calibration_capabilities.u_temperature` `decimal(20,8)`: galat mutlak <= 5e-9. */
    private const TOL_SIMPAN_MUTLAK_UTEMP = 5e-9;

    /** komponen aplikasi (`ConductivityProfile::komponenBudget`) -> bergantung UTemperature tersimpan? */
    private const KOMPONEN = [
        'ketidakpastian_standar' => false,
        'resolusi_alat' => false,
        'ketidakpastian_temperature' => true,
        'pengulangan_pembacaan' => false,
    ];

    private ?EquipmentCategory $kategori = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Urutan `DatabaseSeeder`: ConductivityCapabilitySeeder menimpa baris kategori instrumen-analitik.
        $this->seed(OrganizationSeeder::class);
        $this->seed(CalibrationCapabilitySeeder::class);
        $this->seed(ConductivityCapabilitySeeder::class);

        $this->kategori = EquipmentCategory::where('kode', 'instrumen-analitik')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('conductivity-meter')['workbook'];
    }

    /** @return list<array<string, mixed>> */
    private static function titik(): array
    {
        return self::w()['titik'];
    }

    /** @return list<array{nama: string, serial: string, ketidakpastian: float, satuan: string, koef: string, berlaku_sampai: string}> */
    private static function standarSeeder(): array
    {
        return (new ReflectionClassConstant(ConductivitySeeder::class, 'STANDAR'))->getValue();
    }

    private function alat(): Equipment
    {
        $w = self::w();
        $rentang = [];

        foreach (self::titik() as $t) {
            $rentang[] = [
                'titik' => (int) self::nilai($t['masukan']['titik_nominal']),
                'resolusi' => self::nilai($t['masukan']['resolusi']),
                'satuan' => $w['konstanta']['satuan_resolusi'][$t['kode']],
            ];
        }

        return Equipment::factory()->create([
            'organization_id' => 1,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Conductivity Meter',
            'nama_alat_kemampuan' => 'Conductivitymeter',
            'satuan' => 'mS/cm',
            'resolusi' => 0.01,
            'resolusi_rentang' => $rentang,
            'toleransi' => null,
        ]);
    }

    private function standar(int $i): Standard
    {
        $s = self::standarSeeder()[$i];

        return Standard::factory()->make([
            'nama' => $s['nama'],
            'ketidakpastian' => $s['ketidakpastian'],
            'satuan_ketidakpastian' => $s['satuan'],
            'faktor_cakupan' => 2,
            'koefisien_suhu' => ConductivityProfile::KOEF_SUHU[$s['koef']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $t  titik fixture
     * @return array<string, mixed>
     */
    private function hitung(int $i, ?array $pembacaan = null): array
    {
        $t = self::titik()[$i];
        $suhu = $t['masukan']['suhu_sesudah_c']['nilai'];

        return (new GumCalculator)->hitungTitik(
            $i + 1,
            self::nilai($t['masukan']['titik_nominal']),
            array_map('floatval', $pembacaan ?? $t['masukan']['pembacaan_sesudah']['nilai']),
            $this->alat(),
            $this->standar($i),
            array_sum($suhu) / count($suhu),
        );
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi (kode/seeder produksi) = sel workbook: polinomial nilai standar,
     * U95 sertifikat larutan, CMC, UTemperature, resolusi per titik, jumlah pengulangan.
     */
    public function test_konstanta_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();
        $k = $w['konstanta'];
        $std = self::standarSeeder();

        // Polinomial nilai standar (konstanta tertulis di rumus sel / konstanta ketik).
        $p111 = $k['nominal_larutan_ketik']['111_poli'];
        foreach (['a', 'b', 'c'] as $kunci) {
            $this->assertEqualsWithDelta($p111[$kunci], ConductivityProfile::KOEF_SUHU['111'][$kunci], 0.0, "koefisien {$kunci} polinomial 111 mS/cm vs rumus {$p111['sel']}");
        }
        $this->assertSame(['a' => 0.0, 'b' => 0.0, 'c' => self::nilai($k['nominal_larutan_ketik']['25'])], ConductivityProfile::KOEF_SUHU['25'], 'nilai standar 25 µS/cm vs '.$k['nominal_larutan_ketik']['25']['sel']);
        $this->assertSame(['a' => 0.0, 'b' => 0.0, 'c' => self::nilai($k['nominal_larutan_ketik']['1412'])], ConductivityProfile::KOEF_SUHU['1412'], 'nilai standar 1412 µS/cm vs '.$k['nominal_larutan_ketik']['1412']['sel']);

        // U95 sertifikat larutan: 111 mS/cm diketik 1,6*1000 di µS/cm (`DATABASE!V15`), dipakai /1000 (`M37`).
        $this->assertSamaExcel($k['u95_larutan']['25'], (float) $std[0]['ketidakpastian'], 'U95 larutan 25 µS/cm');
        $this->assertSamaExcel($k['u95_larutan']['1412'], (float) $std[1]['ketidakpastian'], 'U95 larutan 1412 µS/cm');
        $this->assertSamaExcel($k['u95_larutan_111_dipakai_ms'], (float) $std[2]['ketidakpastian'], 'U95 larutan 111 mS/cm (M37 = V15/1000)');
        $this->assertSamaExcel($k['u95_larutan']['111_ketik'], (float) $std[2]['ketidakpastian'] * 1000.0, 'U95 larutan 111 mS/cm dalam µS (V15)');
        $this->assertSame(['µS/cm', 'µS/cm', 'mS/cm'], array_column($std, 'satuan'), 'satuan U95 larutan');

        // CMC (DATABASE!S5, S6, S8) dan UTemperature dari kemampuan produksi.
        $kem = CalibrationCapability::where('equipment_category_id', $this->kategori->id)->where('nama_alat', 'Conductivitymeter')->get()->keyBy(fn ($r) => (int) $r->range_min);
        $this->assertSamaExcel($k['cmc']['25'], (float) $kem[25]->ketidakpastian_terbaik, 'CMC 25 µS/cm');
        $this->assertSamaExcel($k['cmc']['1412'], (float) $kem[1412]->ketidakpastian_terbaik, 'CMC 1412 µS/cm');
        $this->assertSamaExcel($k['cmc']['111'], (float) $kem[111]->ketidakpastian_terbaik, 'CMC 111 mS/cm');
        $this->assertSame('µS/cm', $kem[1412]->satuan, 'CMC 1412 dalam µS/cm (varian mS/cm: S7 = S6/1000)');
        $this->assertSamaExcel($k['cmc']['1412_mili'], (float) $kem[1412]->ketidakpastian_terbaik / 1000.0, 'CMC varian mS/cm = S6/1000');

        $this->assertSamaHitungUlang(sqrt((self::nilai($k['u_termometer_c']) / 2) ** 2 + (self::nilai($k['u_sensor_c']) / 2) ** 2), self::nilai($k['u_temperature_gabungan']), 'Z5 = akar((Z3/2)^2+(Z4/2)^2)');
        foreach ($kem as $titik => $baris) {
            $this->assertEqualsWithDelta(self::nilai($k['u_temperature_gabungan']), (float) $baris->u_temperature, self::TOL_SIMPAN_MUTLAK_UTEMP, "UTemperature titik {$titik} vs {$k['u_temperature_gabungan']['sel']} (decimal(20,8))");
        }

        // Resolusi dan jumlah desimal cetak per titik (E17/G17/I17), 5 pengulangan.
        foreach (ConductivityProfile::TITIK as $i => $baris) {
            $t = self::titik()[$i];
            $this->assertSamaExcel($t['masukan']['resolusi'], (float) $baris['resolusi'], "resolusi titik {$t['nama']}");
            $this->assertSame($k['satuan_resolusi'][$t['kode']], $baris['satuan'], "satuan resolusi titik {$t['nama']}");
            $this->assertSamaExcel($t['masukan']['titik_nominal'], (float) $baris['nilai'], "titik nominal {$t['nama']}");
        }
        $this->assertSame(5, ConductivityProfile::JUMLAH_PENGULANGAN);
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar pada suhu larutan (`PERHITUNGAN!F39/H39/L39`), suhu larutan rata-rata,
     * rata-rata UUT, STDEV sampel, dan koreksi (Standar - UUT) cocok di ketiga titik. Titik
     * 111 mS/cm satu-satunya yang bergeser ikut suhu (25,2 °C -> 111,193568).
     */
    public function test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel(): void
    {
        foreach (self::titik() as $i => $t) {
            $h = $this->hitung($i);
            $s = $t['standar'];
            $ke = $t['nama'];
            $suhu = $t['masukan']['suhu_sesudah_c']['nilai'];

            $this->assertSamaExcel($s['suhu_larutan_rata_c'], array_sum($suhu) / count($suhu), "{$ke}: suhu larutan rata-rata (°C)");
            $this->assertSamaExcel($s['nilai_standar_sesudah'], $h['titik_ukur'], "{$ke}: nilai standar pada suhu larutan");
            $this->assertSamaExcel($s['rata_rata_uut'], $h['rata_rata'], "{$ke}: rata-rata UUT sesudah adjustment");
            $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], "{$ke}: STDEV sampel");
            $this->assertSamaExcel($s['koreksi_lembar_standar_minus_rata'], $h['koreksi'], "{$ke}: koreksi (Standar - UUT)");
            $this->assertSamaExcel($t['cetak_style2']['koreksi'], $h['koreksi'], "{$ke}: koreksi di sertifikat");
            // Excel tidak punya sel PASS/FAIL sama sekali; aplikasi tanpa toleransi -> tanpa vonis.
            $this->assertNull($h['keputusan'], "{$ke}: tanpa vonis lulus/gagal (master tidak punya)");
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /** Keempat komponen per titik: ui, ci, vi, uici. */
    public function test_empat_komponen_budget_cocok_excel(): void
    {
        foreach (self::titik() as $i => $t) {
            $komp = collect($this->hitung($i)['type_b_components'])->keyBy('sumber');
            // Keempat komponen budget + baris JEJAK AUDIT (bukan komponen): `perbandingan_cmc` dan, sejak
            // PR #298, `parameter_metode` (parameter keputusan metode yang berlaku, bawaan).
            $this->assertSame(['parameter_metode', 'perbandingan_cmc'], collect(array_diff($komp->keys()->all(), array_keys(self::KOMPONEN)))->sort()->values()->all(), "{$t['nama']}: komponen aplikasi = empat + jejak audit");

            foreach (self::KOMPONEN as $kunci => $bergantungUTemp) {
                $x = $t['komponen'][$kunci];
                $b = $komp[$kunci];
                $ke = "{$t['nama']} {$kunci}";

                $this->assertSamaExcel($x['vi'], (float) $b['vi'], "{$ke}: vi");
                $this->assertSamaExcel($x['ui'], (float) $b['u_baku'], "{$ke}: ui = u/pembagi", rel: $bergantungUTemp ? self::TOL_SIMPAN_REL_UI : self::TOL_REL);
                $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$ke}: ci", rel: $bergantungUTemp ? self::TOL_SIMPAN_REL_UI : self::TOL_REL);
                $this->assertSamaExcel($x['uici'], (float) $b['nilai'], "{$ke}: ui*ci", rel: $bergantungUTemp ? self::TOL_SIMPAN_REL_UICI : self::TOL_REL);
            }
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /** uc, v_eff, k, U (sebelum lantai), CMC, U sertifikat. */
    public function test_uc_veff_k_u_dan_lantai_cmc_cocok_excel(): void
    {
        foreach (self::titik() as $i => $t) {
            $h = $this->hitung($i);
            $a = $t['agregat'];
            $ke = $t['nama'];

            $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc", rel: self::TOL_SIMPAN_REL_UC);
            $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff", rel: self::TOL_SIMPAN_REL_VEFF);
            $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], "{$ke}: k (TINV, df dipotong)");
            $this->assertSamaExcel($a['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U = k*uc sebelum lantai", rel: self::TOL_SIMPAN_REL_UC * 4);
            $this->assertSamaExcel($a['u_sertifikat'], $h['ketidakpastian_diperluas'], "{$ke}: U sertifikat = MAX(U; CMC)", rel: self::TOL_SIMPAN_REL_UC * 4);
            // Lantai: yang terbit = MAX(U; CMC) (CMC sendiri diadu di test_konstanta_aplikasi_sama_dengan_workbook).
            $this->assertGreaterThanOrEqual(self::nilai($a['cmc']), $h['ketidakpastian_diperluas'], "{$ke}: U sertifikat tidak di bawah CMC {$a['cmc']['sel']}");
        }

        // Lantai CMC mengikat HANYA di titik 111 mS/cm (U hitung 1,584 < CMC 1,7).
        $mengikat = array_map(
            fn (int $i): bool => $this->hitung($i)['ketidakpastian_gabungan'] * $this->hitung($i)['faktor_cakupan_k'] < self::nilai(self::titik()[$i]['agregat']['cmc']),
            [0, 1, 2],
        );
        $this->assertSame([false, false, true], $mengikat, 'lantai CMC mengikat hanya di titik 111 mS/cm');
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK (Standard Value, UUT, Correction, U95) per titik, teks sama persis dengan
     * `SERTIFIKAT STYLE 2` C/J/P/R baris 35, 36, 41, pada pemformat produksi (`Angka::hasil`)
     * dengan desimal dari resolusi titik. Koreksi yang membulat ke nol (`-0.0` di emulasi
     * Excel) dibandingkan TANPA tanda: aplikasi sengaja tanpa minus (`tandaNolDicetak()`),
     * emulasi fixture tidak bisa membuktikan render Excel asli — MENUNGGU (pola P-5).
     */
    public function test_angka_cetak_sama_dengan_sertifikat_excel(): void
    {
        $profil = new ConductivityProfile;
        $minus = 0;
        $bedaStandar = [];

        foreach (self::titik() as $i => $t) {
            $h = $this->hitung($i);
            $c = $t['cetak_style2'];
            $desimal = Angka::desimalDariResolusi(self::nilai($t['masukan']['resolusi']));
            $ke = $t['nama'];

            $this->assertSame((string) $desimal, (string) strlen(substr((string) strrchr($c['standar']['format'], '.'), 1)), "{$ke}: desimal cetak = desimal format sel {$c['standar']['sel']}");
            $this->assertSame((string) $desimal, (string) strlen(substr((string) strrchr($c['u95']['format'], '.'), 1)), "{$ke}: desimal U95 = format sel {$c['u95']['sel']}");

            // P-16(b): aplikasi membuang nol di belakang koma Standard Value (`nolBelakangStandarDibuang()` bawaan
            // true), master tidak (`C35` format `0.0` -> `25.0`). Hanya titik 25 yang kena (1412 dan 111,19 sama).
            $standar = $profil->nolBelakangStandarDibuang() ? Angka::nilaiStandar($h['titik_ukur'], $desimal) : Angka::hasil($h['titik_ukur'], $desimal);
            if (self::keTitik($standar) !== $c['standar']['teks']) {
                $bedaStandar[$t['kode']] = [$c['standar']['teks'], self::keTitik($standar)];
            }
            $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal, '—', $profil->tandaNolDicetak())), "{$ke}: Unit Under Test vs {$c['uut']['sel']}");

            $teksKoreksi = $c['koreksi']['nol_bertanda_minus'] ? ltrim($c['koreksi']['teks'], '-') : $c['koreksi']['teks'];
            $minus += (int) $c['koreksi']['nol_bertanda_minus'];
            $this->assertSame($teksKoreksi, self::keTitik(Angka::hasil($h['koreksi'], $desimal, '—', $profil->tandaNolDicetak())), "{$ke}: Correction vs {$c['koreksi']['sel']} ({$c['koreksi']['nilai']})");
            $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal, '—', $profil->tandaNolDicetak())), "{$ke}: U95 vs {$c['u95']['sel']} ({$c['u95']['nilai']})");
        }

        $this->assertSame(['25' => ['25.0', '25']], $bedaStandar, 'P-16(b) Standard Value: [teks Excel C35, teks aplikasi]; titik 1412 dan 111 sama');
        $this->assertFalse($profil->tandaNolDicetak(), 'Conductivity mencetak koreksi yang membulat ke nol TANPA minus');
        $this->assertSame(1, $minus, 'baris koreksi yang di emulasi Excel `-0.0` (titik 25 µS/cm): perubahan jumlah = perbarui docs/paritas-jangka-sorong-conductivity.md (MENUNGGU bukti render)');

        // k per blok satuan: blok µS memakai k titik 25 (U37), blok mS k titik 111 (U42); format `0` -> `2`.
        $format = $profil->formatKolomSertifikat(new CalibrationSession, 25.0)['k'];
        foreach (['blok_mikro' => 0, 'blok_mili' => 2] as $blok => $i) {
            $k = self::w()['k_cetak'][$blok];
            $this->assertSame($k['format'], $format, "format sel {$k['sel']}");
            $this->assertKSamaExcel($k, $this->hitung($i)['faktor_cakupan_k'], "k {$blok} vs {$k['sel']}");
            $this->assertSame($k['teks'], TataLetakSertifikat::formatAngka($this->hitung($i)['faktor_cakupan_k'], $format, true), "teks cetak k {$blok} vs {$k['sel']}");
        }
    }

    // ------------------------------------------------------------------ SELISIH dan MENUNGGU yang dikunci

    /**
     * SELISIH P-15 (E-5): titik tengah 1412 µS/cm. Master mengkoreksi varian 1,412 mS/cm dengan
     * polinomial suhu (`PERHITUNGAN!J26`) tetapi varian 1412 µS/cm KONSTAN (`H26 = 1412` diketik).
     * Aplikasi mengunci keduanya konstan atas arahan lab 11 Agt 2026; polinomial disimpan
     * (`KOEF_SUHU_1412_TERDOKUMENTASI`) tidak dipakai. Dikunci: a, b, c polinomial aplikasi =
     * rumus sel; nilai aplikasi pada suhu 24,92 °C tetap 1412.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_titik_tengah_1412_polinomial_didokumentasikan_tidak_dipakai_dikunci(): void
    {
        $p = self::w()['konstanta']['poli_1412_mili'];
        $dok = ConductivityProfile::KOEF_SUHU_1412_TERDOKUMENTASI;

        $this->assertEqualsWithDelta($p['a'], $dok['a'], 0.0, "a vs {$p['sel']}");
        $this->assertEqualsWithDelta($p['b'], $dok['b'], 0.0, "b vs {$p['sel']}");
        $this->assertEqualsWithDelta($p['c'], $dok['c'], 0.0, "c vs {$p['sel']}");
        $this->assertSame(0.0, self::nilai($p['k33_cache']), 'Excel: K33 (suhu varian mS) kosong -> polinomial pada T = 0');
        $this->assertSamaExcel($p['cache'], 0.738, 'Excel: J26 pada T = 0 = 0,738 (hasil sampah bila varian tidak dipakai)', 0.0, 1e-12);

        $suhu = array_sum($this->titik()[1]['masukan']['suhu_sesudah_c']['nilai']) / 5;
        $this->assertEqualsWithDelta(1412.0, $this->standar(1)->nilaiPadaSuhu($suhu), 0.0, 'aplikasi: nilai standar 1412 µS/cm KONSTAN pada '.round($suhu, 2).' °C');
        $polinomial = $dok['a'] * $suhu ** 2 + $dok['b'] * $suhu + $dok['c'];
        $this->assertGreaterThan(1.0, abs(1412.0 - $polinomial), 'polinomial Excel varian mS pada suhu sesi memberi nilai yang BEDA >= 1 µS/cm dari 1412 (belum dipakai)');
    }

    /**
     * SELISIH P-16(a) dan P-17 (tampilan/tanggal, tidak menyentuh angka hitung):
     *  P-16(a) nomor style sertifikat KEBALIKAN dari nama sheet (STYLE 2 mencetak titik tengah dalam µS =
     *      `PERHITUNGAN!H39`; aplikasi: µS-µS-mS = style 1) — arahan lisan lab 11 Agt 2026, sel
     *      master tidak berubah. (P-16(b), Standard Value `25.0` lawan `25`, dikunci di test angka cetak.)
     *  P-17 tanggal terbit: `INPUT DATA!D57 = Q16+2`, STYLE 1 `B55 = S12+4`, STYLE 2 `B55 = S12+2` —
     *      tiga rumus, dua nilai; aplikasi `tambah_hari = null` (tanggal terbit sistem).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_nomor_style_dan_tanggal_terbit_master_tidak_konsisten_dikunci(): void
    {
        $w = self::w();
        $st = $w['style_sertifikat'];

        $this->assertSame('=PERHITUNGAN!$H$39', $st['style2_titik_tengah']['rumus'], $st['style2_titik_tengah']['sel'].': STYLE 2 = titik tengah µS/cm (H39)');
        $this->assertSame('=PERHITUNGAN!J39', $st['style1_titik_tengah']['rumus'], $st['style1_titik_tengah']['sel'].': STYLE 1 = titik tengah mS/cm (J39)');
        $this->assertStringContainsString('1413 µS/cm', (string) $st['catatan_input_k57']['nilai'], $st['catatan_input_k57']['sel'].': catatan master "data 1413 µS/cm -> style 2"');

        $profil = new ConductivityProfile;
        $this->assertSame(1, $profil->styleSertifikat([25.0, 1412.0, 111.0]), 'aplikasi: µS-µS-mS = style 1 (master: sheet STYLE 2)');
        $this->assertSame(2, $profil->styleSertifikat([25.0, 1.412, 111.0]), 'aplikasi: µS-mS-mS = style 2 (master: sheet STYLE 1)');

        $t = $w['tanggal_terbit'];
        $this->assertSame('=Q16+2', $t['input_d57']['rumus'], $t['input_d57']['sel']);
        $this->assertSame('=S12+4', $t['style1_b55']['rumus'], $t['style1_b55']['sel']);
        $this->assertSame('=S12+2', $t['style2_b55']['rumus'], $t['style2_b55']['sel']);
        $this->assertNull(FormatMasterSertifikat::tanggalUntukProfil('conductivity_meter')['tambah_hari'], 'aplikasi: tanggal terbit sistem (nilai bentrok antar sheet)');
    }

    /**
     * SELISIH P-18: dua sel koreksi lembar PERHITUNGAN varian mS/cm (`J34`, `J47`) berisi
     * `=AVERAGE(...)` bukan selisih (BUNTU: tidak dirujuk sertifikat, cache 0). Aplikasi memakai
     * Standar - UUT (E-2) untuk semua titik. Terkunci di sisi Excel; sisi aplikasi: koreksi
     * titik 25 = -0,04 = `F47`, bukan rata-rata.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_koreksi_varian_mili_di_lembar_perhitungan_sel_buntu_dikunci(): void
    {
        foreach (self::w()['koreksi_sel_buntu'] as $sel) {
            $this->assertStringStartsWith('=AVERAGE(', (string) $sel['rumus'], "{$sel['sel']}: rumus koreksi varian mS bukan selisih");
            $this->assertSame(0, (int) $sel['cache'], "{$sel['sel']}: cache 0");
        }

        $this->assertEqualsWithDelta(-0.04, $this->hitung(0)['koreksi'], 1e-12, 'koreksi titik 25: Standar - UUT');
    }

    /**
     * SELISIH P-19: Type A terkunci di Excel (`P12/P26/P40 = 5` pembagi, `R12/R26/R40 = 4` vi) tidak
     * peduli berapa pengulangan yang diketik; aplikasi memakai n sebenarnya (vi = n - 1, pembagi
     * akar n). Sama persis di n = 5 (sesi contoh), BEDA di n lain — tidak teramati di sesi
     * contoh. Dikunci: empat pembacaan -> vi aplikasi 3, Excel tetap 4.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_pengulangan_type_a_terkunci_lima_di_excel_aplikasi_memakai_n_sebenarnya_dikunci(): void
    {
        $kunci = self::w()['konstanta']['pengulangan_terkunci'];
        $this->assertSame(5, (int) $kunci['P12'], 'Excel: pembagi akar(5) diketik (U95!P12)');
        $this->assertSame(4, (int) $kunci['R12'], 'Excel: vi 4 diketik (U95!R12)');

        $sesuai = collect($this->hitung(0)['type_b_components'])->keyBy('sumber')['pengulangan_pembacaan'];
        $this->assertSame(4.0, (float) $sesuai['vi'], 'n = 5: vi aplikasi = Excel');

        $empat = $this->hitung(0, array_slice(self::titik()[0]['masukan']['pembacaan_sesudah']['nilai'], 0, 4));
        $komp = collect($empat['type_b_components'])->keyBy('sumber')['pengulangan_pembacaan'];
        $this->assertSame(3.0, (float) $komp['vi'], 'n = 4: vi aplikasi = n - 1 = 3, Excel tetap 4');
    }

    /**
     * MENUNGGU (rujuk P-6 pH, tanpa nomor baru): UTemperature. Nilainya SAMA (konstanta 0,36124783736376886 = akar((0,72/2)^2 +
     * (0,06/2)^2) dari `DATABASE!V17/V18`), tetapi aplikasi menyimpannya SATU konstanta di kemampuan
     * (tidak mengikuti termometer standar sesi) — keputusan yang sama dengan P-6 (pH). Sel `DATABASE!W17/Y17`:
     * termometer jatuh tempo 12 Agu 2026, sudah lewat per 25 Nov 2026 — bukan perilaku hitung.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_utemperature_sama_nilainya_satu_konstanta_menunggu_keputusan_sumber(): void
    {
        $k = self::w()['konstanta'];

        $this->assertSame(0.72, self::nilai($k['u_termometer_c']), $k['u_termometer_c']['sel']);
        $this->assertSame(0.06, self::nilai($k['u_sensor_c']), $k['u_sensor_c']['sel']);
        $this->assertSame(1, CalibrationCapability::where('nama_alat', 'Conductivitymeter')->distinct()->count('u_temperature'), 'aplikasi: SATU nilai UTemperature untuk ketiga titik');
    }
}
