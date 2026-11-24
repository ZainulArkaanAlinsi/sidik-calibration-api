<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\GumCalculator;
use App\Support\Angka;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PhMeterCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS pH Meter: aplikasi lawan DUA workbook master pH, tahap demi tahap.
 *
 * Titik panggil terkecil tanpa HTTP: `GumCalculator::hitungTitik()` — fungsi yang
 * sama dengan jalur preview, simpan (`CalibrationController`), dan hitung ulang,
 * dengan masukan yang disusun seperti controller menyusunnya (suhu larutan =
 * rata-rata lima suhu SESUDAH adjustment; titik ukur = label botol, yang diganti
 * `Standard::nilaiPadaSuhu()`). Konstanta aplikasi dibaca dari SUMBER produksi,
 * bukan diketik ulang: polinomial & U95 buffer dari
 * `database/data/kalibrasi-ph-meter.json`, konstanta budget dari
 * `PhMeterCapabilitySeeder` — jadi tes ini juga membuktikan konstanta seeder = sel
 * workbook.
 *
 * ## Dua workbook = dua termometer standar
 *
 *  - `trial` (Yokogawa CA 150, U95 0,72 -> UTemperature 0,3612478…; resolusi UUT
 *    0,01): konstanta seeder SAMA dengan workbook -> semua tahap SAMA.
 *  - `imte_wq_129` (Constant/SH 10, U95 0,5 -> UTemperature 0,2517935…; resolusi
 *    0,001): aplikasi memakai SATU konstanta (Yokogawa), jadi komponen
 *    `temperature` BEDA — SELISIH TERCATAT (konflik J1, lembar centang
 *    `ph_meter#19`, `docs/pertanyaan-lab-ph-dua-master.md` §1). Tes mengunci:
 *    (a) selisih itu satu-satunya penyebab (hitung ulang rumus sel dengan
 *    UTemperature aplikasi = angka aplikasi), (b) arahnya aman (U aplikasi >=
 *    Excel), (c) angka CETAK tetap sama karena lantai CMC menang di ketiga titik.
 *
 * Jalur cetak dicek di tingkat pemformat (`Angka`, yang dipakai renderer); jalur
 * HTML penuh sesi `2405.13.A` (vektor 1) sudah dijaga
 * `SieveAutoclaveTimerPhSesiContohCocokMasterTest`.
 */
#[Group('paritas')]
class ParitasPhMeterTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** nama sumber budget aplikasi (`PhMeterProfile::komponenBudget`) -> kunci komponen fixture (urutan sheet baris 11-15). */
    private const PEMETAAN_KOMPONEN = [
        'ketidakpastian_standar' => 'sertifikat_buffer',
        'resolusi_alat' => 'daya_baca',
        'ketidakpastian_temperature' => 'temperature',
        'pengaruh_perbedaan_suhu' => 'perbedaan_suhu',
        'pengulangan_pembacaan' => 'pengulangan',
    ];

    /** Label botol per titik (`PhMeterProfile::standarPerTitik`); nilai acuan dihitung dari polinomialnya. */
    private const TITIK_NOMINAL = ['pH 4' => 4.00, 'pH 7' => 7.00, 'pH 10' => 10.01];

    private const URUTAN_BUFFER = ['pH 4' => 0, 'pH 7' => 1, 'pH 10' => 2];

    private ?EquipmentCategory $kategori = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Urutan yang sama dengan `DatabaseSeeder` (PhMeterCapabilitySeeder menimpa
        // baris instrumen-analitik yang ditulis CalibrationCapabilitySeeder).
        $this->seed(OrganizationSeeder::class);
        $this->seed(CalibrationCapabilitySeeder::class);
        $this->seed(PhMeterCapabilitySeeder::class);

        $this->kategori = EquipmentCategory::where('kode', 'instrumen-analitik')->firstOrFail();
    }

    /** @return array<string, array{string}> */
    public static function workbook(): array
    {
        return [
            'for trial (Yokogawa, 0,01 pH)' => ['trial'],
            'IMTE-WQ-129 (Constant, 0,001 pH)' => ['imte_wq_129'],
        ];
    }

    /** @return array<string, mixed> */
    private static function w(string $kode): array
    {
        return self::fixtureParitas('ph-meter')['workbook'][$kode];
    }

    private function alat(string $kode): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => 1,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'pH Meter',
            'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH',
            'resolusi' => self::nilai(self::w($kode)['masukan']['resolusi_alat']),
            'toleransi' => 0.2,
        ]);
    }

    /** Buffer dari data produksi (`kalibrasi-ph-meter.json`), diurut pH 4, 7, 10. */
    private function standar(string $nama): Standard
    {
        static $data = null;
        $data ??= json_decode((string) file_get_contents(base_path('database/data/kalibrasi-ph-meter.json')), true)['standards'];

        $s = $data[self::URUTAN_BUFFER[$nama]];

        return Standard::factory()->make([
            'nama' => $s['nama'],
            'ketidakpastian' => $s['ketidakpastian'],
            'satuan_ketidakpastian' => $s['satuan_ketidakpastian'],
            'faktor_cakupan' => $s['faktor_cakupan'],
            'koefisien_suhu' => $s['koefisien_suhu'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $t  titik fixture
     * @return array<string, mixed>
     */
    private function hitung(string $kode, array $t): array
    {
        $suhu = $t['masukan']['suhu_larutan_sesudah_c']['nilai'];

        return (new GumCalculator)->hitungTitik(
            self::URUTAN_BUFFER[$t['nama']] + 1,
            self::TITIK_NOMINAL[$t['nama']],
            array_map('floatval', $t['masukan']['pembacaan_sesudah']['nilai']),
            $this->alat($kode),
            $this->standar($t['nama']),
            // Seperti `CalibrationController::susunPengukuran`: rata-rata suhu baris yang terisi.
            array_sum($suhu) / count($suhu),
        );
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi (data produksi) = konstanta workbook: polinomial buffer
     * (tertulis di rumus `PERHITUNGAN!G35/I35/K35`), U95 sertifikat buffer
     * (`DATABASE!V13:V15`), CMC (`DATABASE!S5:S7`), dan konstanta budget
     * (`PERHITUNGAN U95%` W13, M14, W14 ...).
     */
    #[DataProvider('workbook')]
    public function test_konstanta_aplikasi_sama_dengan_workbook(string $kode): void
    {
        foreach (self::w($kode)['titik'] as $t) {
            $s = $this->standar($t['nama']);
            $p = $t['polinomial_buffer'];

            foreach (['a', 'b', 'c'] as $kunci) {
                $this->assertEqualsWithDelta(
                    $p[$kunci],
                    (float) $s->koefisien_suhu[$kunci],
                    0.0,
                    "{$t['nama']}: koefisien {$kunci} polinomial vs rumus {$p['sel']}",
                );
            }

            $this->assertSamaExcel($t['u95_sertifikat_buffer'], (float) $s->ketidakpastian, "{$t['nama']}: U95 sertifikat buffer");

            $kem = CalibrationCapability::where('equipment_category_id', $this->kategori->id)
                ->where('nama_alat', 'pH Meter')
                ->where('range_min', (int) filter_var($t['nama'], FILTER_SANITIZE_NUMBER_INT))
                ->firstOrFail();

            $k = $t['komponen'];
            $this->assertSamaExcel($t['cmc'], (float) $kem->ketidakpastian_terbaik, "{$t['nama']}: CMC (DATABASE!S5:S7)");
            $this->assertSamaExcel($k['temperature']['ci'], (float) $kem->ci_suhu, "{$t['nama']}: ci suhu", rel: 1e-9);
            $this->assertSamaExcel($k['perbedaan_suhu']['u_mentah'], (float) $kem->u_perbedaan_suhu, "{$t['nama']}: U perbedaan suhu");
            $this->assertSamaExcel($k['perbedaan_suhu']['ci'], (float) $kem->ci_perbedaan_suhu, "{$t['nama']}: ci perbedaan suhu", rel: 1e-9);
        }
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar (polinomial pada suhu larutan rata-rata), rata-rata UUT, STDEV
     * sampel, dan koreksi cetak (Standar - UUT) cocok di KEDUA workbook.
     */
    #[DataProvider('workbook')]
    public function test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel(string $kode): void
    {
        foreach (self::w($kode)['titik'] as $t) {
            $h = $this->hitung($kode, $t);
            $s = $t['standar'];
            $ke = $t['nama'];

            $suhu = $t['masukan']['suhu_larutan_sesudah_c']['nilai'];
            $this->assertSamaExcel($s['suhu_larutan_rata_c'], array_sum($suhu) / count($suhu), "{$ke}: suhu larutan rata-rata (°C)");
            $this->assertSamaExcel($s['nilai_standar'], $h['titik_ukur'], "{$ke}: nilai standar pada suhu larutan");
            $this->assertSamaExcel($s['rata_rata_uut'], $h['rata_rata'], "{$ke}: rata-rata UUT sesudah adjustment");
            $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], "{$ke}: STDEV sampel");
            $this->assertSamaExcel($t['cetak']['koreksi'], $h['koreksi'], "{$ke}: koreksi cetak (Standar - UUT)");
            // Lembar menampilkan kebalikannya (UUT - Standar) di PERHITUNGAN; sertifikat memakai Standar - UUT.
            $this->assertSamaExcel($s['koreksi_lembar_uut_minus_standar'], -$h['koreksi'], "{$ke}: koreksi lembar (UUT - Standar) = -koreksi aplikasi");
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /**
     * Lima komponen (ui, ci, vi, uici). Di `imte_wq_129` hanya `temperature` yang boleh
     * beda (konflik J1) — dan bedanya persis UTemperature konstanta aplikasi.
     */
    #[DataProvider('workbook')]
    public function test_lima_komponen_budget_cocok_excel(string $kode): void
    {
        foreach (self::w($kode)['titik'] as $t) {
            $h = $this->hitung($kode, $t);
            $komp = collect($h['type_b_components'])->keyBy('sumber');

            foreach (self::PEMETAAN_KOMPONEN as $sumberApp => $kunci) {
                $x = $t['komponen'][$kunci];
                $b = $komp[$sumberApp];
                $ke = "{$t['nama']} {$kunci}";

                $this->assertSamaExcel($x['vi'], (float) $b['vi'], "{$ke}: vi");

                if ($kunci === 'temperature' && $kode === 'imte_wq_129') {
                    $app = $t['dengan_utemperature_aplikasi'];
                    $this->assertSamaHitungUlang($app['ui_temperature'], (float) $b['u_baku'], "{$ke}: ui = UTemperature aplikasi / 2 (konflik J1)");
                    $this->assertGreaterThan(self::nilai($x['ui']), (float) $b['u_baku'], "{$ke}: ui aplikasi HARUS lebih besar dari {$x['ui']['sel']} (termometer Constant U95 0,5 < Yokogawa 0,72)");
                    $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$ke}: ci");

                    continue;
                }

                $this->assertSamaExcel($x['ui'], (float) $b['u_baku'], "{$ke}: ui = u/pembagi");
                $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$ke}: ci");
                $this->assertSamaExcel($x['uici'], (float) $b['nilai'], "{$ke}: ui*ci");
            }
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /** uc, v_eff, k, U (sebelum lantai), CMC, dan U cetak — `trial`: SAMA di semua tahap. */
    public function test_uc_veff_k_u_dan_lantai_cmc_cocok_excel_untuk_trial(): void
    {
        foreach (self::w('trial')['titik'] as $t) {
            $h = $this->hitung('trial', $t);
            $a = $t['agregat'];
            $ke = $t['nama'];

            $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc");
            $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff");
            $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], "{$ke}: k (TINV, df dipotong)");
            $this->assertUSamaExcel($a['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U = k*uc sebelum lantai");
            $this->assertUSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U sesudah lantai CMC (=MAX(U; CMC))");
        }
    }

    /**
     * `imte_wq_129`: selisih UTemperature (konflik J1) DIKUNCI. Aplikasi = rumus sel
     * dengan UTemperature aplikasi (satu-satunya penyebab); aplikasi >= Excel;
     * sesudah lantai CMC angkanya SAMA persis.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_imte_selisih_utemperature_terkunci_dan_cetak_tetap_sama_karena_lantai_cmc(): void
    {
        foreach (self::w('imte_wq_129')['titik'] as $t) {
            $h = $this->hitung('imte_wq_129', $t);
            $a = $t['agregat'];
            $skenario = $t['dengan_utemperature_aplikasi'];
            $ke = $t['nama'];

            // (a) satu-satunya penyebab: rumus sel dengan UTemperature aplikasi == aplikasi.
            $this->assertSamaHitungUlang($skenario['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc (rumus sel dengan UTemperature aplikasi)");
            $this->assertSamaHitungUlang($skenario['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff");
            $this->assertSamaHitungUlang($skenario['k'], $h['faktor_cakupan_k'], "{$ke}: k", 0.0, self::TOL_K);
            $this->assertSamaHitungUlang($skenario['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U sebelum lantai", self::TOL_K_REL);

            // (b) selisih terhadap Excel ADA dan arahnya aman (aplikasi lebih besar).
            $this->assertGreaterThan(self::nilai($a['uc']), $h['ketidakpastian_gabungan'], "{$ke}: uc aplikasi harus > {$a['uc']['sel']} (konflik J1)");
            $this->assertGreaterThan(self::nilai($a['u_hitung']), $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U-hitung aplikasi harus > {$a['u_hitung']['sel']}");

            // (c) yang tercetak sama: lantai CMC menang di kedua sisi.
            $this->assertSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U cetak sesudah lantai CMC");
            $this->assertSamaExcel($a['cmc'], $h['ketidakpastian_diperluas'], "{$ke}: U cetak = CMC (lantai menang)");
        }
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik (Standard Value, UUT, Correction, U95) dan `k` (satu
     * angka, milik pH 7), teks persis sama dengan sel `SERTIFIKAT` — pada pemformat
     * produksi `Angka` dengan desimal dari resolusi alat (`Angka::desimalDariResolusi`).
     */
    #[DataProvider('workbook')]
    public function test_angka_cetak_sama_dengan_sertifikat_excel(string $kode): void
    {
        $w = self::w($kode);
        $profil = new PhMeterProfile;
        $desimal = Angka::desimalDariResolusi(self::nilai($w['masukan']['resolusi_alat']));
        $hasilPerTitik = [];

        foreach ($w['titik'] as $t) {
            $h = $this->hitung($kode, $t);
            $hasilPerTitik[$t['nama']] = $h;
            $c = $t['cetak'];
            $ke = $t['nama'];

            $this->assertSame((string) $desimal, (string) strlen(substr(strrchr($c['standar']['format'], '.'), 1)), "{$ke}: desimal cetak = desimal format sel {$c['standar']['sel']}");

            $standar = $profil->nolBelakangStandarDibuang()
                ? Angka::nilaiStandar($h['titik_ukur'], $desimal)
                : Angka::hasil($h['titik_ukur'], $desimal);
            $this->assertSame($c['standar']['teks'], self::keTitik($standar), "{$ke}: Standard Value vs {$c['standar']['sel']} ({$c['standar']['nilai']})");
            $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal)), "{$ke}: Unit Under Test vs {$c['uut']['sel']}");
            $this->assertSame($c['koreksi']['teks'], self::keTitik(Angka::hasil($h['koreksi'], $desimal)), "{$ke}: Correction vs {$c['koreksi']['sel']}");
            $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal)), "{$ke}: U95 vs {$c['u95']['sel']}");
        }

        // Excel mencetak SATU k (`SERTIFIKAT!U24` = k blok pH 7).
        $k = $w['cetak_k'];
        $this->assertSame(
            $k['teks'],
            self::keTitik(Angka::hasil($hasilPerTitik['pH 7']['faktor_cakupan_k'], 0)),
            "k tercetak vs {$k['sel']} ({$k['nilai']})",
        );
    }
}
