<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden test Conductivity Meter lawan `Master Olah Data_Conductivity.xlsm`,
 * job `0189-CAL-524`.
 *
 * Angka pembandingnya diambil dari DUA ekspor independen dari workbook yang
 * sama — pembacaan langsung sel `.xlsm` yang didekripsi, dan folder
 * `conductivity_csv` yang dikirim lab. Keduanya cocok digit demi digit, jadi
 * yang diuji di sini bukan "hasil sistem sama kayak tebakan saya", tapi sama
 * kayak angka yang beneran keluar dari master.
 *
 * Sel yang diadu (sheet `PERHITUNGAN U95%`):
 *
 *   titik      | Uc        | v_eff     | k         | U95%      | CMC  | sertifikat
 *   25 µS/cm   | AB14      | AB15      | AB16      | AB17      | AB18 | AB19
 *   1412 µS/cm | AB28      | AB29      | AB30      | AB31      | AB32 | AB33
 *   111 mS/cm  | AB42      | AB43      | AB44      | AB45      | AB46 | AB47
 */
class ConductivityBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sel PERHITUNGAN U95% master, per titik ukur.
     *
     * @var list<array{titik: float, standar: int, pembacaan: list<float>, suhu: list<float>, stdev: float, uc: float, veff: float, k: float, u95: float, cmc: float, sertifikat: float}>
     */
    private const MASTER = [
        [
            'titik' => 25.0,
            'standar' => 0,
            'pembacaan' => [25, 25, 25, 25.1, 25.1],
            'suhu' => [25.2, 25.2, 25.2, 25.3, 25.2],
            'stdev' => 0.05477225575051739,
            'uc' => 0.25337606633141846,
            'veff' => 210.05249036488587,
            'k' => 1.9713247932433295,
            'u95' => 0.49948652157359164,
            'cmc' => 0.41,
            'sertifikat' => 0.49948652157359164,
        ],
        [
            'titik' => 1412.0,
            'standar' => 1,
            'pembacaan' => [1413, 1413, 1413, 1413, 1413],
            'suhu' => [25, 24.9, 24.9, 24.9, 24.9],
            'stdev' => 0.0,
            'uc' => 4.114873303302707,
            'veff' => 223.35477391804778,
            'k' => 1.9706589608358136,
            'u95' => 8.109011947857544,
            'cmc' => 5.1,
            'sertifikat' => 8.109011947857544,
        ],
        [
            'titik' => 111.0,
            'standar' => 2,
            'pembacaan' => [110.69, 110.67, 110.67, 110.67, 110.67],
            'suhu' => [25.2, 25.2, 25.2, 25.2, 25.2],
            'stdev' => 0.008944271909997378,
            'uc' => 0.8032870446419409,
            'veff' => 203.2936948692954,
            'k' => 1.9717188484634527,
            'u95' => 1.5838562066470179,
            // Satu-satunya titik yang U hitungnya di BAWAH CMC, jadi yang
            // dilaporin lantai CMC-nya (sel AB47 = 1,7, bukan 1,58).
            'cmc' => 1.7,
            'sertifikat' => 1.7,
        ],
    ];

    private Equipment $alat;

    /** @var array<int, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $kategori = EquipmentCategory::factory()->create(['organization_id' => 1]);
        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        $spek = [
            ['nama' => 'Conductivity Std Solution 25 µS/cm', 'u' => 0.5, 'satuan' => 'µS/cm', 'koef' => '25', 'titik' => 25, 'cmc' => 0.41],
            ['nama' => 'Conductivity Std Solution 1412 µS/cm', 'u' => 8.0, 'satuan' => 'µS/cm', 'koef' => '1412', 'titik' => 1412, 'cmc' => 5.1],
            ['nama' => 'Conductivity Std Solution 111 mS/cm', 'u' => 1.6, 'satuan' => 'mS/cm', 'koef' => '111', 'titik' => 111, 'cmc' => 1.7],
        ];

        foreach ($spek as $i => $s) {
            $this->standar[$i] = Standard::factory()->create([
                'organization_id' => 1,
                'nama' => $s['nama'],
                'ketidakpastian' => $s['u'],
                'satuan_ketidakpastian' => $s['satuan'],
                'faktor_cakupan' => 2,
                'koefisien_suhu' => ConductivityProfile::KOEF_SUHU[$s['koef']],
                'parameter_kondisi' => null,
            ]);

            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Conductivitymeter',
                'range_min' => $s['titik'],
                'range_max' => $s['titik'],
                'parameter' => 'Conductivity',
                'satuan' => $s['satuan'],
                'ketidakpastian_terbaik' => $s['cmc'],
                'satuan_ketidakpastian' => $s['satuan'],
                'faktor_cakupan' => 2,
                'u_temperature' => $uTemperature,
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ]);
        }

        $this->alat = Equipment::factory()->create([
            'organization_id' => 1,
            'customer_id' => Customer::factory()->create(['organization_id' => 1])->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Conductivity Meter',
            'nama_alat_kemampuan' => 'Conductivitymeter',
            'satuan' => 'mS/cm',
            'resolusi' => 0.01,
            'toleransi' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function hitung(int $index): array
    {
        $m = self::MASTER[$index];

        return (new GumCalculator)->hitungTitik(
            $index + 1,
            $m['titik'],
            $m['pembacaan'],
            $this->alat,
            $this->standar[$m['standar']],
            array_sum($m['suhu']) / count($m['suhu']),
        );
    }

    public function test_registry_milih_profil_conductivity(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($this->alat);

        $this->assertInstanceOf(ConductivityProfile::class, $profil);
        $this->assertSame('gum-conductivity', $profil->kodeFormula());
    }

    public function test_uc_veff_k_dan_u95_cocok_master(): void
    {
        foreach (self::MASTER as $i => $m) {
            $hasil = $this->hitung($i);
            $titik = $m['titik'];

            $this->assertEqualsWithDelta(
                $m['uc'],
                $hasil['ketidakpastian_gabungan'],
                1e-12,
                "Uc titik {$titik} meleset dari master",
            );

            $this->assertEqualsWithDelta(
                $m['veff'],
                $hasil['derajat_kebebasan_efektif'],
                1e-9,
                "v_eff titik {$titik} meleset dari master",
            );

            // Master pakai `TINV(0.05; v_eff)`, dan Excel MOTONG derajat
            // kebebasan non-bulat ke bawah sebelum nyari t-nya — persis yang
            // dilakuin `GumCalculator` (GUM G.4.1). Makanya k-nya bisa cocok
            // sampai belasan digit walaupun v_eff-nya pecahan.
            $this->assertEqualsWithDelta(
                $m['k'],
                $hasil['faktor_cakupan_k'],
                1e-9,
                "k titik {$titik} meleset dari master",
            );

            // U95% SEBELUM lantai CMC (sel AB17/AB31/AB45). `hitungTitik()`
            // udah nerapin lantainya di `ketidakpastian_diperluas`, jadi yang
            // diadu di sini `Uc × k` — dua angka yang dia pakai buat nyusunnya.
            $this->assertEqualsWithDelta(
                $m['u95'],
                $hasil['ketidakpastian_gabungan'] * $hasil['faktor_cakupan_k'],
                1e-9,
                "U95% titik {$titik} meleset dari master",
            );

            // Yang beneran dilaporkan (sel AB19/AB33/AB47), lantai CMC udah
            // kepasang.
            $this->assertEqualsWithDelta(
                $m['sertifikat'],
                $hasil['ketidakpastian_diperluas'],
                1e-9,
                "Ketidakpastian sertifikat titik {$titik} meleset dari master",
            );
        }
    }

    public function test_lantai_cmc_cuma_kena_di_titik_111(): void
    {
        // Yang bikin titik 3 beda: U hitungnya (1,58) lebih KECIL dari CMC
        // (1,7), jadi yang dilaporin CMC-nya. Dua titik lain sebaliknya —
        // U hitungnya di atas CMC, jadi lantainya nggak kepakai.
        foreach (self::MASTER as $i => $m) {
            $hasil = $this->hitung($i);
            $uHitung = $hasil['ketidakpastian_gabungan'] * $hasil['faktor_cakupan_k'];

            $this->assertEqualsWithDelta(
                $m['sertifikat'],
                $hasil['ketidakpastian_diperluas'],
                1e-9,
                "Ketidakpastian sertifikat titik {$m['titik']} meleset dari master",
            );

            $this->assertEqualsWithDelta(
                max($uHitung, $m['cmc']),
                $hasil['ketidakpastian_diperluas'],
                1e-9,
                "Lantai CMC titik {$m['titik']} nggak kepasang sebagaimana mestinya",
            );
        }

        $this->assertLessThan(1.7, $this->hitung(2)['ketidakpastian_gabungan'] * $this->hitung(2)['faktor_cakupan_k']);
        $this->assertGreaterThan(0.41, $this->hitung(0)['ketidakpastian_diperluas']);
        $this->assertGreaterThan(5.1, $this->hitung(1)['ketidakpastian_diperluas']);
    }

    /**
     * Correction = Nilai Standar − Rata-rata Pembacaan (E-2 / Q1).
     *
     * Master nyimpen rumus ini di `SERTIFIKAT!P40` (`=C40-J40`), BUKAN di
     * `PERHITUNGAN` baris 47 — sel `J47` di situ isinya `=AVERAGE(...)` yang
     * nggak dibaca siapa-siapa. Yang diuji sini yang beneran kecetak.
     */
    public function test_correction_ikut_rumus_sertifikat_bukan_sel_buntu(): void
    {
        // PERHITUNGAN F47/H47/L47 — nilai yang kecetak di kolom "Correction".
        //
        // Titik tengah SENGAJA beda dari master: −2,16 lawan −1, karena nilai
        // acuannya sekarang dikoreksi suhu (E-5 dibetulkan). Lihat
        // `test_titik_1412_dikoreksi_suhu_bukan_angka_ketik`.
        $master = [-0.03999999999999915, -2.1599999999999966, 0.5195679999999925];

        foreach (self::MASTER as $i => $m) {
            $this->assertEqualsWithDelta(
                $master[$i],
                $this->hitung($i)['koreksi'],
                1e-9,
                "Correction titik {$m['titik']} meleset dari master",
            );
        }
    }

    /**
     * Nilai acuan larutan digeser polinomial suhu — dan titik 111 mS/cm itu
     * satu-satunya yang bener-bener bergeser.
     */
    public function test_nilai_acuan_terkoreksi_suhu_cocok_master(): void
    {
        // PERHITUNGAN F39 / H39 / L39. Titik tengah sengaja beda dari master
        // (1410,84 lawan 1412) — E-5 dibetulkan, lihat test di bawah.
        $master = [25.0, 1410.8399999999999, 111.193568];

        foreach (self::MASTER as $i => $m) {
            $this->assertEqualsWithDelta(
                $master[$i],
                $this->hitung($i)['titik_ukur'],
                1e-9,
                "Nilai acuan titik {$m['titik']} meleset dari master",
            );
        }
    }

    /**
     * E-5 DIBETULKAN — titik 1412 µS/cm dikoreksi suhu pakai polinomial yang
     * didokumentasikan sheet `nilai koefisien sensitifitas` A39, bukan angka
     * ketik `1412` di `PERHITUNGAN!H26`.
     *
     * Ini SATU-SATUNYA tempat sistem sengaja beda dari master, dan test ini
     * yang menahannya tetap terlihat: kalau ada yang mbalikin ke konstanta,
     * dia merah; kalau selisihnya melar lebih dari yang diperkirakan, dia juga
     * merah.
     */
    public function test_titik_1412_dikoreksi_suhu_bukan_angka_ketik(): void
    {
        $suhu = (25 + 24.9 * 4) / 5; // 24,919999999999998 — sel PERHITUNGAN I46
        $koef = ConductivityProfile::KOEF_SUHU['1412'];
        $polinomial = $koef['a'] * $suhu ** 2 + $koef['b'] * $suhu + $koef['c'];

        $this->assertEqualsWithDelta(
            $polinomial,
            $this->hitung(1)['titik_ukur'],
            1e-9,
            'Titik 1412 harus ikut polinomial suhu, bukan konstanta',
        );

        // Angka master yang ditinggalkan, dan sebesar apa geserannya.
        $master = ConductivityProfile::KOEF_SUHU_1412_MASTER['c'];

        $this->assertSame(1412.0, $master);
        $this->assertEqualsWithDelta(1410.84, $polinomial, 1e-6);
        $this->assertEqualsWithDelta(1.16, $master - $polinomial, 1e-6);

        // Geserannya harus tetap jauh di dalam U95% titik ini (±8,1) — kalau
        // nggak, dia bukan lagi koreksi halus tapi perubahan hasil.
        $this->assertLessThan(
            $this->hitung(1)['ketidakpastian_diperluas'],
            abs($master - $polinomial),
            'Geseran E-5 nggak boleh melampaui ketidakpastian titiknya sendiri',
        );
    }

    /**
     * Q3 — conductivity nggak divonis PASS/FAIL, karena master nggak punya
     * satu pun batas keberterimaan.
     */
    public function test_tidak_ada_vonis_lulus_gagal(): void
    {
        foreach (array_keys(self::MASTER) as $i) {
            $this->assertNull(
                $this->hitung($i)['keputusan'],
                'Conductivity nggak boleh divonis PASS/FAIL — master nggak punya toleransi',
            );
        }
    }

    /**
     * Q4 / E-6 — titik indeks thermohygro = titik kalibrasi TERDEKAT, dan itu
     * mereproduksi pilihan manual operator di master buat suhu MAUPUN
     * kelembaban.
     */
    public function test_titik_indeks_thermohygro_mereproduksi_pilihan_operator(): void
    {
        // Titik kalibrasi TH-6, sheet DATABASE C56:C60 & G56:G60.
        $titikSuhu = [14.64, 19.33, 29.75, 39.34, 49.95];
        $titikRh = [28.67, 46.83, 67.23, 76.79, 86.52];

        // Rata-rata terukur sesi: (25,4+25,5)/2 dan (54+55)/2.
        $this->assertSame(29.75, ConductivityProfile::titikIndeksTerdekat(25.45, $titikSuhu));
        $this->assertSame(46.83, ConductivityProfile::titikIndeksTerdekat(54.5, $titikRh));
    }

    /**
     * Resolusi & desimal cetak beda per titik — INPUT DATA E17/G17/I17 dan
     * format sel SERTIFIKAT STYLE 2 C35/C36/C41 (`0.0` / `0` / `0.00`).
     */
    public function test_resolusi_dan_desimal_per_titik(): void
    {
        $profil = new ConductivityProfile;

        $this->assertSame(0.1, $profil->resolusiTitik(25.0));
        $this->assertSame(1.0, $profil->resolusiTitik(1412.0));
        $this->assertSame(0.01, $profil->resolusiTitik(111.0));

        $this->assertSame(1, $profil->desimalTitik(25.0));
        $this->assertSame(0, $profil->desimalTitik(1412.0));
        $this->assertSame(2, $profil->desimalTitik(111.0));

        // Nilai acuan terkoreksi (111,193568) harus tetap nyangkut ke titik
        // 111 — bukan ke titik lain — walaupun geser 0,19.
        $this->assertSame(0.01, $profil->resolusiTitik(111.193568));
        $this->assertSame('mS/cm', $profil->satuanTitik(111.193568));
        $this->assertSame('µS/cm', $profil->satuanTitik(25.0));
    }

    /**
     * Dua pengaman yang bikin sesi conductivity bisa terbit sama sekali.
     *
     * Tanpa keduanya, `CalibrationValidator` nahan SETIAP sesi conductivity:
     * satu error "toleransi kosong" + satu error "keputusan sesi salah", plus
     * peringatan palsu "pembacaan di luar rentang" buat tiap pembacaan µS/cm.
     */
    public function test_pengaman_validator_conductivity(): void
    {
        $profil = new ConductivityProfile;

        // Toleransi NULL di alat ini = "emang nggak divonis", bukan "belum diisi".
        $this->assertFalse($profil->punyaToleransi());

        // 1413 µS/cm = 1,413 mS/cm — di dalam rentang alat 0–100 mS/cm.
        $this->assertEqualsWithDelta(
            1.413,
            $profil->nilaiDalamSatuanAlat(1413.0, 'µS/cm', $this->alat),
            1e-9,
        );

        // Yang satuannya udah sama nggak disentuh.
        $this->assertEqualsWithDelta(
            110.67,
            $profil->nilaiDalamSatuanAlat(110.67, 'mS/cm', $this->alat),
            1e-9,
        );
    }

    /**
     * Alat lain nggak boleh ikut kena: defaultnya identitas & punya toleransi.
     */
    public function test_profil_lain_tidak_ikut_berubah(): void
    {
        foreach ([new \App\Services\Calibration\Profiles\PhMeterProfile,
            new \App\Services\Calibration\Profiles\TurbidimeterProfile,
            new \App\Services\Calibration\Profiles\ChlorineProfile,
            new \App\Services\Calibration\Profiles\RefractometerProfile] as $lain) {
            $this->assertTrue($lain->punyaToleransi(), get_class($lain).' harus tetap divonis');
            $this->assertSame(
                1413.0,
                $lain->nilaiDalamSatuanAlat(1413.0, 'µS/cm', $this->alat),
                get_class($lain).' nggak boleh ngonversi apa pun',
            );
        }
    }

    /**
     * Desimal Env. Condition ikut format sel master, bukan aturan global —
     * sel T14 (`0.0` → 25,8) & T15 (`0` → 51) di kedua sheet sertifikat.
     */
    public function test_desimal_env_condition_ikut_master(): void
    {
        $profil = new ConductivityProfile;

        $this->assertSame(1, $profil->desimalSuhuEnv());
        $this->assertSame(0, $profil->desimalKelembabanEnv());
    }

    /**
     * Style sertifikat ditentukan satuan titik tengah — catatan INPUT DATA!K57.
     */
    public function test_style_sertifikat_dari_satuan_titik_tengah(): void
    {
        $profil = new ConductivityProfile;

        $this->assertSame(2, $profil->styleSertifikat([25.0, 1412.0, 111.0]));
        $this->assertSame(1, $profil->styleSertifikat([25.0, 1.412, 111.0]));

        // Varian mS/cm bawa peringatan; varian µS/cm nggak.
        $this->assertNull($profil->peringatanVarianMili([25.0, 1412.0, 111.0]));
        $this->assertStringContainsString(
            'mS/cm',
            (string) $profil->peringatanVarianMili([25.0, 1.412, 111.0]),
        );
    }
}
