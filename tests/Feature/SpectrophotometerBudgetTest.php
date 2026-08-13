<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden test Visible Spectrophotometer lawan
 * `Master Olah Data_Spectrofotometer.xlsm`.
 *
 * Sel yang diadu (sheet `PERHITUNGAN U95%`):
 *
 *   kelompok   | uici budget | Uc  | v_eff | k   | U95% | CMC | sertifikat
 *   Holmium    | J10:J14     | J17 | J18   | J19 | J20  | J21 | J22
 *   Didynium   | J26:J30     | J33 | J34   | J35 | J36  | J37 | J38
 *   %T @560 nm | J46:J50     | J53 | J54   | J55 | J56  | J57 | J58
 *
 * Rata-rata & koreksi per titik diadu ke sheet `PERHITUNGAN` (kolom K/L) lewat
 * angka yang sama yang kecetak di `SERTIFIKAT`.
 *
 * Yang paling penting diuji di sini bukan cuma "angkanya cocok", tapi **tiga
 * penyimpangan master yang sengaja ditiru** — kalau salah satunya diam-diam
 * "dibenerin" nanti, tes ini yang jatuh duluan:
 *
 *  1. Pembagi Type A kelompok panjang gelombang `SQRT(SQRT(3))`, bukan `SQRT(n)`.
 *  2. `SUM(J47:J49)` di blok %T yang ngelewatin baris 46 & 50.
 *  3. Lantai CMC `MAX(U95, CMC)` yang naikin Didynium ke 0,4 nm dan %T ke 0,5 %T.
 */
class SpectrophotometerBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hitungan murni — nggak lewat kolom `decimal(20,8)` mana pun, jadi
     * toleransinya boleh sehalus batas presisi double, bukan batas DB. Tes
     * yang MEMANG lewat DB (`SpectrophotometerApiTest`) pakai 1e-8.
     */
    private const TOLERANSI = 1e-12;

    /**
     * Celah spectral bandwidth `'PERHITUNGAN U95%'!Q30 = ABS(AVERAGE(P10:P27))`.
     * Dipakai tiga-tiganya sebagai komponen terakhir budget.
     */
    private const CELAH = 0.07555555555555789;

    /**
     * Pembacaan sesi master + sel hasilnya, per kelompok.
     *
     * @var array<string, array<string, mixed>>
     */
    private const MASTER = [
        SpectrophotometerCalculator::GRUP_HOLMIUM => [
            'satuan' => 'nm',
            'u_standar' => 0.1,          // STANDAR_KALIBRATOR!M5
            'resolusi' => 0.01,          // INPUT DATA!G16
            'cmc' => 0.4,                // DATABASE!S5
            'titik' => [
                [279.6, [280, 280, 280]],
                [287.7, [288, 288, 288]],
                [334.0, [333.74, 333.74, 333.74]],
                [360.9, [360.35, 360.59, 360.6]],
                [418.6, [418.6, 418.34, 418.34]],
                [445.8, [445.44, 445.69, 445.69]],
                [453.6, [453.55, 453.29, 453.29]],
                [460.0, [459.13, 459.37, 459.37]],
                [536.3, [536.63, 536.37, 536.37]],
                [637.9, [637.45, 637.18, 637.18]],
            ],
            'stdev_maks' => 0.15588457268125408,   // PERHITUNGAN!M36
            'uici' => [
                0.11844666116576621,   // J10 keterulangan
                0.05,                  // J11 gelas filter
                0.002886751345948129,  // J12 resolusi
                0.005773502691896259,  // J13 drift
                0.043622020338773076,  // J14 spectral bandwidth
            ],
            'uc' => 0.1359196779955751,
            'veff' => 3.4642618700507275,
            'k' => 3.182446305283709,
            'u95' => 0.43255707705236945,
            'sertifikat' => 0.43255707705236945,
        ],
        SpectrophotometerCalculator::GRUP_DIDYNIUM => [
            'satuan' => 'nm',
            'u_standar' => 0.2,          // STANDAR_KALIBRATOR!M18
            'resolusi' => 0.01,
            'cmc' => 0.4,                // DATABASE!S6
            'titik' => [
                [475.2, [477.86, 477.93, 477.93]],
                [513.7, [513.32, 513.58, 513.58]],
                [529.7, [529.53, 529.28, 529.28]],
                [572.7, [572.6, 572.34, 572.34]],
                [585.7, [585.28, 585.51, 585.51]],
                [684.9, [684.55, 684.3, 684.3]],
                [738.5, [738.63, 738.52, 738.52]],
                [748.0, [747.87, 747.79, 747.78]],
                [806.1, [806.49, 806.35, 806.35]],
            ],
            'stdev_maks' => 0.15011106998929746,   // PERHITUNGAN!M49
            'uici' => [
                0.11405974778921205,   // J26
                0.1,                   // J27
                0.002886751345948129,  // J28
                0.011547005383792518,  // J29
                0.043622020338773076,  // J30
            ],
            'uc' => 0.15828510160732645,
            'veff' => 7.367683516139811,
            'k' => 2.364624251592785,
            'u95' => 0.3742847899265122,
            // U hitungnya 0,374 — di BAWAH CMC, jadi yang dilaporin lantainya.
            'sertifikat' => 0.4,
        ],
        SpectrophotometerCalculator::GRUP_TRANSMITAN => [
            'satuan' => '%T',
            'u_standar' => 0.5,          // STANDAR_KALIBRATOR!M29
            'resolusi' => 0.001,         // INPUT DATA!E16
            'cmc' => 0.5,                // DATABASE!S7
            'titik' => [
                [0.0, [0.001, 0.001, 0, 0.001, 0.001, 0]],
                [9.9, [9.668, 9.661, 9.666, 9.668, 9.661, 9.666]],
                [20.0, [19.531, 19.525, 19.534, 19.531, 19.525, 19.534]],
                [30.1, [29.25, 29.249, 29.249, 29.25, 29.249, 29.249]],
                [100.0, [100.004, 100.003, 100.001, 100.004, 100.003, 100.001]],
            ],
            'stdev_maks' => 0.00409878030638399,
            'uici' => [
                0.25,                    // J46 — DIKECUALIKAN dari SUM
                0.0016733200530682146,   // J47 keterulangan
                0.0002886751345948129,   // J48 resolusi
                0.02886751345948129,     // J49 drift
                0.00029962599828653143,  // J50 — DIKECUALIKAN dari SUM
            ],
            'uc' => 0.028917411133548367,
            'veff' => 50.340915292002045,
            'k' => 2.008559112100761,
            'u95' => 0.058082329630652574,
            'sertifikat' => 0.5,
        ],
    ];

    /**
     * Rata-rata & koreksi yang kecetak di `SERTIFIKAT`, per titik yang disebut
     * di spec. Koreksi = nilai standar − rata-rata UUT.
     *
     * @var list<array{grup: string, titik: float, rata_rata: float, koreksi: float}>
     */
    private const MASTER_TITIK = [
        ['grup' => SpectrophotometerCalculator::GRUP_HOLMIUM, 'titik' => 637.9, 'rata_rata' => 637.27, 'koreksi' => 0.63],
        ['grup' => SpectrophotometerCalculator::GRUP_DIDYNIUM, 'titik' => 475.2, 'rata_rata' => 477.9066666666667, 'koreksi' => -2.706666666666706],
        ['grup' => SpectrophotometerCalculator::GRUP_DIDYNIUM, 'titik' => 806.1, 'rata_rata' => 806.3966666666666, 'koreksi' => -0.2966666666666242],
        ['grup' => SpectrophotometerCalculator::GRUP_TRANSMITAN, 'titik' => 0.0, 'rata_rata' => 0.0006666666666666666, 'koreksi' => -0.0006666666666666666],
        ['grup' => SpectrophotometerCalculator::GRUP_TRANSMITAN, 'titik' => 9.9, 'rata_rata' => 9.665, 'koreksi' => 0.235],
        ['grup' => SpectrophotometerCalculator::GRUP_TRANSMITAN, 'titik' => 100.0, 'rata_rata' => 100.00266666666668, 'koreksi' => -0.0026666666666841365],
    ];

    private Equipment $alat;

    /** @var array<string, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Organisasi & kategori dibikin baru, bukan dipatok id 1 — di MySQL
        // AUTO_INCREMENT terus maju lintas tes, jadi `=> 1` bakal mutusin FK.
        // Pelajaran yang sama kayak ConductivityBudgetTest.
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);

        $filter = [
            SpectrophotometerCalculator::GRUP_HOLMIUM => ['Filter Standard 1', 'SPG080982.A', 0.1, 'nm'],
            SpectrophotometerCalculator::GRUP_DIDYNIUM => ['Filter Standard 2', 'SPG080982.B', 0.2, 'nm'],
            SpectrophotometerCalculator::GRUP_TRANSMITAN => ['Filter Standard 3', 'SPG080982.C', 0.5, '%T'],
        ];

        foreach ($filter as $grup => [$nama, $serial, $u, $satuan]) {
            $this->standar[$grup] = Standard::factory()->create([
                'organization_id' => $org->id,
                'nama' => $nama,
                'serial_number' => $serial,
                'no_sertifikat' => $serial,
                'tertelusur_ke' => 'BSN-SNSU',
                'ketidakpastian' => $u,
                'satuan_ketidakpastian' => $satuan,
                'faktor_cakupan' => 2,
                'koefisien_suhu' => null,
                'parameter_kondisi' => null,
            ]);
        }

        $cmc = [
            ['parameter' => 'panjang gelombang (nm)-Holmium', 'min' => 283, 'max' => 641, 'satuan' => 'nm', 'u' => 0.4],
            ['parameter' => 'panjang gelombang (nm)-Didynium', 'min' => 474, 'max' => 810, 'satuan' => 'nm', 'u' => 0.4],
            ['parameter' => 'akurasi (%T)', 'min' => 10, 'max' => 30.5, 'satuan' => '%T', 'u' => 0.5],
        ];

        foreach ($cmc as $c) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Spectrophotometer',
                'parameter' => $c['parameter'],
                'range_min' => $c['min'],
                'range_max' => $c['max'],
                'satuan' => $c['satuan'],
                'ketidakpastian_terbaik' => $c['u'],
                'satuan_ketidakpastian' => $c['satuan'],
                'faktor_cakupan' => 2,
                'metode' => SpectrophotometerProfile::KODE_METODE,
            ]);
        }

        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Visible Spectrofotometer',
            'nama_alat_kemampuan' => 'Spectrophotometer',
            'satuan' => 'nm',
            'resolusi' => 0.01,
            'toleransi' => null,
        ]);
    }

    /**
     * Satu kelompok lewat kalkulator murni, spek-nya persis dari [MASTER].
     *
     * @return array<string, mixed>
     */
    private function hitungGrup(string $grup): array
    {
        $m = self::MASTER[$grup];
        $titikKe = 0;

        return (new SpectrophotometerCalculator)->hitungGrup(
            array_map(static function (array $t) use (&$titikKe): array {
                return ['titik_ke' => ++$titikKe, 'titik_ukur' => $t[0], 'pembacaan' => $t[1]];
            }, $m['titik']),
            [
                'grup' => $grup,
                'satuan' => $m['satuan'],
                'nama_standar' => 'Filter uji',
                'u_standar' => $m['u_standar'],
                'k_standar' => 2.0,
                'resolusi' => $m['resolusi'],
                'cmc' => $m['cmc'],
            ],
        );
    }

    public function test_registry_milih_profil_spectrophotometer(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($this->alat);

        $this->assertInstanceOf(SpectrophotometerProfile::class, $profil);
        $this->assertSame('gum-spectro', $profil->kodeFormula());
        // Dua nomor yang beda jenis: FORMULIR lembar kerjanya vs METODE
        // kalibrasinya. Sempat ketuker (kode_dokumen keisi nomor IK), jadi
        // dua-duanya dipatok di sini.
        $this->assertSame('SIDIK-FM-CAL-0511_Rev.5', SpectrophotometerProfile::KODE_DOKUMEN);
        $this->assertSame('SIDIK-IK-CAL-0508_Rev.4', SpectrophotometerProfile::KODE_METODE);
    }

    /**
     * Celah spectral bandwidth dihitung dari tabel `N10:O27` yang ditanam di
     * kelas kalkulator, bukan ditulis sebagai konstanta hasil. Jadi kalau satu
     * baris tabelnya salah salin, angka ini yang berubah.
     */
    public function test_celah_spectral_bandwidth_cocok_sel_q30(): void
    {
        $this->assertEqualsWithDelta(
            self::CELAH,
            (new SpectrophotometerCalculator)->celahSpectralBandwidth(),
            self::TOLERANSI,
        );

        $this->assertCount(18, SpectrophotometerCalculator::GAP_SPECTRAL_BANDWIDTH);
    }

    public function test_uc_veff_k_dan_u95_cocok_master(): void
    {
        foreach (self::MASTER as $grup => $m) {
            $hasil = $this->hitungGrup($grup);

            $this->assertEqualsWithDelta(
                $m['stdev_maks'],
                $hasil['standar_deviasi_maks'],
                self::TOLERANSI,
                "STDEV maks kelompok {$grup} meleset dari master",
            );

            $this->assertEqualsWithDelta(
                $m['uc'],
                $hasil['ketidakpastian_gabungan'],
                self::TOLERANSI,
                "Uc kelompok {$grup} meleset dari master",
            );

            // v_eff toleransinya RELATIF — dia rasio pangkat empat, jadi
            // selisih di `uc` teramplifikasi ~4× dan nilainya sendiri bisa
            // puluhan/ratusan, bukan pecahan.
            $this->assertEqualsWithDelta(
                $m['veff'],
                $hasil['derajat_kebebasan_efektif'],
                abs($m['veff']) * self::TOLERANSI,
                "v_eff kelompok {$grup} meleset dari master",
            );

            $this->assertEqualsWithDelta(
                $m['k'],
                $hasil['faktor_cakupan_k'],
                self::TOLERANSI,
                "k kelompok {$grup} meleset dari master",
            );

            $this->assertEqualsWithDelta(
                $m['u95'],
                $hasil['ketidakpastian_diperluas'],
                self::TOLERANSI,
                "U95 kelompok {$grup} meleset dari master",
            );
        }
    }

    /**
     * `k` diambil dari `TINV(0.05, veff)` — dan Excel MOTONG derajat kebebasan
     * ke bilangan bulat sebelum nyari t-nya. Tiga kelompok ini kebetulan nutup
     * tiga rentang yang beda (3, 7, 50), jadi pemotongannya kebukti bukan
     * kebetulan satu angka.
     */
    public function test_k_ngikut_tinv_excel_yang_motong_derajat_kebebasan(): void
    {
        $harapan = [
            SpectrophotometerCalculator::GRUP_HOLMIUM => [3.4642618700507275, 3.182446305283709],
            SpectrophotometerCalculator::GRUP_DIDYNIUM => [7.367683516139811, 2.364624251592785],
            SpectrophotometerCalculator::GRUP_TRANSMITAN => [50.340915292002045, 2.008559112100761],
        ];

        foreach ($harapan as $grup => [$veff, $k]) {
            $hasil = $this->hitungGrup($grup);

            $this->assertEqualsWithDelta($veff, $hasil['derajat_kebebasan_efektif'], abs($veff) * self::TOLERANSI);
            $this->assertEqualsWithDelta($k, $hasil['faktor_cakupan_k'], self::TOLERANSI);
        }
    }

    /**
     * Tiap baris `uici` budget diadu satu-satu ke selnya.
     *
     * Ini yang ngunci penyimpangan #1: baris keterulangan Holmium 0,118447 itu
     * `STDEV / 3^0,25`, bukan `STDEV / √3` (0,090) apalagi `STDEV / √n`
     * (0,090). Kalau ada yang "mbenerin" jadi √n, angka ini yang jatuh.
     */
    public function test_tiap_komponen_budget_cocok_sel_master(): void
    {
        foreach (self::MASTER as $grup => $m) {
            $hasil = $this->hitungGrup($grup);

            $this->assertCount(5, $hasil['budget'], "Jumlah komponen budget {$grup} berubah");

            foreach ($m['uici'] as $i => $uici) {
                $komponen = $hasil['budget'][$i];

                $this->assertEqualsWithDelta(
                    $uici,
                    $komponen['u'] * $komponen['ci'],
                    self::TOLERANSI,
                    sprintf('uici komponen "%s" kelompok %s meleset dari master', $komponen['sumber'], $grup),
                );
            }
        }
    }

    /**
     * Penyimpangan #2: `SUM(J47:J49)` di blok %T ngelewatin baris 46
     * (ketidakpastian standar) & baris 50 (spectral bandwidth). Ditiru apa
     * adanya, TAPI dua-duanya tetap muncul di budget dengan
     * `disertakan => false` + alasannya, dan selisihnya dicatat.
     */
    public function test_blok_transmitan_ngecualiin_dua_komponen_persis_kayak_master(): void
    {
        $hasil = $this->hitungGrup(SpectrophotometerCalculator::GRUP_TRANSMITAN);

        $dikecualikan = array_values(array_filter(
            $hasil['budget'],
            static fn (array $k): bool => $k['disertakan'] === false,
        ));

        $this->assertCount(2, $dikecualikan);
        $this->assertSame(
            ['ketidakpastian_standar', 'spectral_bandwidth'],
            array_column($dikecualikan, 'sumber'),
        );

        foreach ($dikecualikan as $k) {
            $this->assertNotEmpty($k['alasan_dikecualikan']);
        }

        // Dua kelompok panjang gelombang NGGAK punya pengecualian — SUM-nya
        // di master emang nutup semua barisnya.
        foreach ([SpectrophotometerCalculator::GRUP_HOLMIUM, SpectrophotometerCalculator::GRUP_DIDYNIUM] as $grup) {
            foreach ($this->hitungGrup($grup)['budget'] as $k) {
                $this->assertTrue($k['disertakan'], "Komponen {$k['sumber']} kelompok {$grup} mestinya ikut");
            }
        }
    }

    /**
     * Pengecualian itu nggak boleh cuma "ditiru diam-diam" — jejak auditnya
     * wajib nyebut angka kalau semuanya diikutkan, biar orang yang meriksa
     * sertifikat tahu bedanya berapa.
     */
    public function test_catatan_audit_nyebut_angka_kalau_semua_komponen_diikutkan(): void
    {
        $hasil = $this->hitungGrup(SpectrophotometerCalculator::GRUP_TRANSMITAN);

        $this->assertNotEmpty($hasil['catatan_audit']);

        // Dicari lewat `kode`, bukan lewat urutan — catatan perbandingan CMC
        // juga ada di daftar yang sama dan urutannya bukan bagian dari kontrak.
        $catatan = collect($hasil['catatan_audit'])->firstWhere('kode', 'komponen_dikecualikan_master');

        $this->assertNotNull($catatan, 'Pengecualian komponen %T nggak ninggalin catatan audit');
        $this->assertStringContainsString('ketidakpastian_standar', $catatan['pesan']);
        $this->assertStringContainsString('spectral_bandwidth', $catatan['pesan']);

        // Angka pembandingnya dihitung beneran, bukan teks kosong. Uc kalau
        // semua ikut = √(0,25² + 0,0016733² + 0,00028868² + 0,028868² +
        // 0,00029963²) = 0,2516670547417868 — naik dari 0,0289 yang dipakai
        // master, hampir 9× lipat, semata gara-gara jangkauan SUM-nya.
        $this->assertEqualsWithDelta(0.2516670547417868, $catatan['ketidakpastian_gabungan'], 1e-12);
    }

    /**
     * Penyimpangan #3, dan yang paling kelihatan di sertifikat: `MAX(U95, CMC)`.
     */
    public function test_lantai_cmc_naikin_didynium_dan_transmitan(): void
    {
        foreach (self::MASTER as $grup => $m) {
            $hasil = $this->hitungGrup($grup);

            $this->assertEqualsWithDelta(
                $m['sertifikat'],
                $hasil['u95_sertifikat'],
                self::TOLERANSI,
                "U95 sertifikat kelompok {$grup} meleset dari master",
            );

            $this->assertSame(
                $m['sertifikat'] > $m['u95'] ? 'cmc' : 'hitung',
                $hasil['sumber_u95'],
                "Sumber U95 kelompok {$grup} salah label",
            );
        }

        // Holmium satu-satunya yang U hitungnya menang.
        $this->assertSame('hitung', $this->hitungGrup(SpectrophotometerCalculator::GRUP_HOLMIUM)['sumber_u95']);
    }

    public function test_rata_rata_dan_koreksi_cocok_sertifikat(): void
    {
        $perGrup = [];

        foreach (array_keys(self::MASTER) as $grup) {
            $perGrup[$grup] = collect($this->hitungGrup($grup)['titik'])->keyBy('titik_ukur');
        }

        foreach (self::MASTER_TITIK as $m) {
            $titik = $perGrup[$m['grup']][$m['titik']];

            $this->assertEqualsWithDelta(
                $m['rata_rata'],
                $titik['rata_rata'],
                self::TOLERANSI,
                "Rata-rata titik {$m['titik']} meleset dari sertifikat",
            );

            $this->assertEqualsWithDelta(
                $m['koreksi'],
                $titik['koreksi'],
                self::TOLERANSI,
                "Koreksi titik {$m['titik']} meleset dari sertifikat",
            );

            // Koreksi = −error, selalu. Ini yang bikin arah tandanya nggak bisa
            // kebalik diam-diam.
            $this->assertEqualsWithDelta(-$titik['error'], $titik['koreksi'], self::TOLERANSI);
        }
    }

    /**
     * `n` yang dipakai budget diambil dari titik yang NGASILIN STDEV terbesar,
     * bukan dari titik pertama. Blok %T master emang n=6 (dua baris X1..X3 per
     * standar), dua kelompok panjang gelombang n=3.
     */
    public function test_jumlah_pengulangan_ngikut_titik_penentu(): void
    {
        $this->assertSame(3, $this->hitungGrup(SpectrophotometerCalculator::GRUP_HOLMIUM)['jumlah_pengulangan']);
        $this->assertSame(3, $this->hitungGrup(SpectrophotometerCalculator::GRUP_DIDYNIUM)['jumlah_pengulangan']);
        $this->assertSame(6, $this->hitungGrup(SpectrophotometerCalculator::GRUP_TRANSMITAN)['jumlah_pengulangan']);
    }

    /**
     * Tiap titik satu kelompok bawa U95 yang SAMA — itu yang dicetak
     * sertifikat (satu baris `Uncertainty U95% = ± …` per blok).
     */
    public function test_semua_titik_satu_kelompok_bawa_u95_yang_sama(): void
    {
        $hasil = $this->profil()->hitungPerGrup($this->siapHitung(), $this->alat);

        $perGrup = collect($hasil['hitungan'])->groupBy(
            fn (array $h): string => (string) $this->profil()->grupTitik((float) $h['titik_ukur']),
        );

        $this->assertCount(3, $perGrup);

        foreach ($perGrup as $grup => $baris) {
            $u95 = array_unique(array_column($baris->all(), 'ketidakpastian_diperluas'));
            $k = array_unique(array_column($baris->all(), 'faktor_cakupan_k'));

            $this->assertCount(1, $u95, "Kelompok {$grup} punya lebih dari satu U95");
            $this->assertCount(1, $k, "Kelompok {$grup} punya lebih dari satu k");
            $this->assertEqualsWithDelta(self::MASTER[$grup]['sertifikat'], reset($u95), self::TOLERANSI);
        }
    }

    public function test_semua_24_titik_master_kehitung_lewat_profil(): void
    {
        $hasil = $this->profil()->hitungPerGrup($this->siapHitung(), $this->alat);

        $this->assertCount(24, $hasil['hitungan']);
        $this->assertSame([], $hasil['belum_dihitung']);

        // Urut naik — `titik_ke` yang kebolak bikin baris sertifikat salah petak.
        $this->assertSame(range(1, 24), array_column($hasil['hitungan'], 'titik_ke'));

        // Spectrophotometer nggak divonis PASS/FAIL.
        foreach ($hasil['hitungan'] as $baris) {
            $this->assertNull($baris['toleransi']);
            $this->assertNull($baris['keputusan']);
        }

        $this->assertFalse($this->profil()->punyaToleransi());
    }

    /**
     * Kelompok itu ditentukan FILTER yang dipakai, bukan kedekatan angka.
     *
     * 529,7 nm (Didynium) dan 536,3 nm (Holmium) cuma beda 6,6 nm — di dalam
     * jendela 2% yang dipakai `CalibrationProfile::TOLERANSI_PASANGAN_TITIK`.
     * Kalau profil ini ikut pakai toleransi relatif itu, baris Didynium bakal
     * dapat U95 kelompok Holmium dan sertifikatnya salah tanpa gejala.
     */
    public function test_titik_berdekatan_beda_kelompok_nggak_ketuker(): void
    {
        $profil = $this->profil();

        $this->assertSame(SpectrophotometerCalculator::GRUP_DIDYNIUM, $profil->grupTitik(529.7));
        $this->assertSame(SpectrophotometerCalculator::GRUP_HOLMIUM, $profil->grupTitik(536.3));

        $hasil = collect($profil->hitungPerGrup($this->siapHitung(), $this->alat)['hitungan'])
            ->keyBy('titik_ukur');

        $this->assertEqualsWithDelta(0.4, $hasil[529.7]['ketidakpastian_diperluas'], self::TOLERANSI);
        $this->assertEqualsWithDelta(
            0.43255707705236945,
            $hasil[536.3]['ketidakpastian_diperluas'],
            self::TOLERANSI,
        );

        // Standarnya juga ikut bener — bukan cuma angkanya.
        $this->assertSame(
            $this->standar[SpectrophotometerCalculator::GRUP_DIDYNIUM]->id,
            $hasil[529.7]['standard_id'],
        );
        $this->assertSame(
            $this->standar[SpectrophotometerCalculator::GRUP_HOLMIUM]->id,
            $hasil[536.3]['standard_id'],
        );
    }

    public function test_satuan_remark_dan_desimal_ngikut_kelompok(): void
    {
        $profil = $this->profil();

        $this->assertSame('nm', $profil->satuanTitik(637.9));
        $this->assertSame('%T', $profil->satuanTitik(9.9));

        $this->assertSame(2, $profil->desimalTitik(637.9));
        $this->assertSame(3, $profil->desimalTitik(9.9));

        $this->assertSame(0.01, $profil->resolusiTitik(637.9));
        $this->assertSame(0.001, $profil->resolusiTitik(9.9));

        $this->assertSame('Wave Length ( λ ) - Filter Holmium', $profil->remarkTitik(637.9));
        $this->assertSame('Wave Length ( λ ) - Filter Didynium', $profil->remarkTitik(475.2));
        $this->assertSame('Accuracy %T and Linierity at λ = 560nm', $profil->remarkTitik(9.9));

        // Titik asing nggak dipaksa masuk kelompok terdekat.
        $this->assertNull($profil->grupTitik(600.0));
        $this->assertNull($profil->remarkTitik(600.0));
        $this->assertNull($profil->satuanTitik(600.0));
    }

    public function test_titik_di_luar_daftar_dilaporin_bukan_dibuang_diam_diam(): void
    {
        $titik = [
            ...$this->siapHitung(),
            [
                'titik_ke' => 25,
                'titik_ukur' => 600.0,
                'pembacaan' => [600.1, 600.2, 600.1],
                'standard' => $this->standar[SpectrophotometerCalculator::GRUP_HOLMIUM],
            ],
        ];

        $hasil = $this->profil()->hitungPerGrup($titik, $this->alat);

        $this->assertCount(24, $hasil['hitungan']);
        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(25, $hasil['belum_dihitung'][0]['titik_ke']);
        $this->assertStringContainsString('600', $hasil['belum_dihitung'][0]['alasan']);
    }

    /**
     * Titik yang keluar dari rentang CMC terakreditasi TETAP dihitung (master
     * pun gitu — Holmium 279,6 nm di bawah batas 283 nm), tapi wajib ninggalin
     * jejak. Kalau nggak, sertifikat ngeklaim di luar ruang lingkup tanpa ada
     * yang tahu.
     */
    public function test_titik_di_luar_rentang_cmc_dicatat_di_jejak_audit(): void
    {
        $hasil = collect($this->profil()->hitungPerGrup($this->siapHitung(), $this->alat)['hitungan'])
            ->keyBy('titik_ukur');

        $sumber = array_column($hasil[279.6]['type_b_components'], 'sumber');

        $this->assertContains('titik_luar_rentang_cmc', $sumber);
        $this->assertContains('titik_luar_rentang_cmc', array_column($hasil[9.9]['type_b_components'], 'sumber'));

        $catatan = collect($hasil[279.6]['type_b_components'])
            ->firstWhere('sumber', 'titik_luar_rentang_cmc');

        $this->assertStringContainsString('279.6', $catatan['keterangan']);
        $this->assertStringContainsString('283', $catatan['keterangan']);
    }

    /**
     * Nggak ada satu pun angka hasil yang boleh NaN / INF — itu padanan
     * `#DIV/0!` di Excel, dan di sertifikat dia kecetak sebagai teks aneh atau
     * `0` tanpa peringatan apa pun.
     */
    public function test_nggak_ada_nan_atau_infinity_di_hasil(): void
    {
        $hasil = $this->profil()->hitungPerGrup($this->siapHitung(), $this->alat);

        foreach ($hasil['hitungan'] as $baris) {
            foreach ($baris as $kolom => $nilai) {
                if (! is_float($nilai)) {
                    continue;
                }

                $this->assertTrue(
                    is_finite($nilai),
                    "Kolom {$kolom} titik ke-{$baris['titik_ke']} bukan angka terhingga",
                );
            }
        }
    }

    /**
     * Titik yang cuma punya SATU pembacaan nggak bisa punya standar deviasi —
     * di Excel itu yang ngasilin `#DIV/0!`. Di sini dia gagal keras dengan
     * pesan, bukan diam-diam ngasih 0.
     */
    public function test_pembacaan_kurang_dari_dua_ditolak_bukan_diam_diam_nol(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SpectrophotometerCalculator)->hitungTitik(1, 637.9, [637.45]);
    }

    public function test_kelompok_tanpa_titik_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SpectrophotometerCalculator)->hitungGrup([], [
            'grup' => SpectrophotometerCalculator::GRUP_HOLMIUM,
            'satuan' => 'nm',
            'u_standar' => 0.1,
            'resolusi' => 0.01,
            'cmc' => 0.4,
        ]);
    }

    /**
     * @param  array<string, mixed>  $spek
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('spekTidakMasukAkal')]
    public function test_spek_tidak_masuk_akal_ditolak(array $spek): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SpectrophotometerCalculator)->hitungGrup(
            [['titik_ke' => 1, 'titik_ukur' => 637.9, 'pembacaan' => [637.45, 637.18, 637.18]]],
            [
                'grup' => SpectrophotometerCalculator::GRUP_HOLMIUM,
                'satuan' => 'nm',
                'u_standar' => 0.1,
                'resolusi' => 0.01,
                'cmc' => 0.4,
                ...$spek,
            ],
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function spekTidakMasukAkal(): array
    {
        return [
            'u standar negatif' => [['u_standar' => -0.1]],
            'resolusi negatif' => [['resolusi' => -0.01]],
            'cmc negatif' => [['cmc' => -0.4]],
            'u standar NaN' => [['u_standar' => NAN]],
            'resolusi tak hingga' => [['resolusi' => INF]],
        ];
    }

    private function profil(): SpectrophotometerProfile
    {
        return new SpectrophotometerProfile;
    }

    /**
     * 24 titik master, siap disuap ke `hitungPerGrup()` — bentuknya persis
     * kayak yang disusun `CalibrationController::susunPengukuran()`.
     *
     * @return list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>
     */
    private function siapHitung(): array
    {
        $siap = [];
        $titikKe = 0;

        foreach (self::MASTER as $grup => $m) {
            foreach ($m['titik'] as $t) {
                $siap[] = [
                    'titik_ke' => ++$titikKe,
                    'titik_ukur' => $t[0],
                    'pembacaan' => $t[1],
                    'standard' => $this->standar[$grup],
                ];
            }
        }

        return $siap;
    }
}
