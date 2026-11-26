<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Services\Calibration\Profiles\DoMeterProfile;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use Database\Seeders\DoMeterCapabilitySeeder;
use Database\Seeders\DoMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\Concerns\MenyiapkanAlatAnalitik;
use Tests\TestCase;

/**
 * PARITAS DO Meter: aplikasi lawan `Master Olah Data_DO Meter.xlsm` (satu titik 8,77 mg/L, lima
 * pengulangan), tahap demi tahap. Fixture `tests/Fixtures/paritas/do-meter.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-batch4.py` dari nilai-cache workbook.
 *
 * Titik panggil terkecil tanpa HTTP: `GumCalculator::hitungTitik()`. Konstanta aplikasi dibaca dari
 * SEEDER produksi (`DoMeterCapabilitySeeder`, `DoMeterSeeder`), bukan diketik ulang.
 *
 * Catatan cakupan sampel (dikunci di bawah, bukan klaim): satu titik, pembacaan hampir identik
 * (STDEV 0,0055), blok "Before" dan "After Adjustment" berisi angka yang sama, dan U hitung (0,148)
 * lebih kecil dari CMC (0,16), jadi cabang "U di atas CMC" tidak teruji oleh sampel ini.
 *
 * Selisih yang dikunci (nomor dan uraian di `docs/paritas-batch4.md`):
 *  B4-1 blok %O2: Excel mencetak baris 20,9 / 0 / 20,9 dan U95/k `#REF!`; aplikasi tidak punya titik itu;
 *  P-25 resolusi: Excel memakai angka yang DIKETIK, aplikasi yang LEBIH BESAR dengan master.
 */
#[Group('paritas')]
class ParitasDoMeterTest extends TestCase
{
    use MenegakkanParitas;
    use MenyiapkanAlatAnalitik;
    use RefreshDatabase;

    private const STANDAR = 'Oxygen Standard Solution 8.77 mg/L';

    private const KOMPONEN = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'faktor_pengenceran', 'pengulangan_pembacaan'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAnalitik([DoMeterCapabilitySeeder::class, DoMeterSeeder::class], thermohygro: true);
    }

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('do-meter')['workbook'];
    }

    /** @return array<string, mixed> */
    private static function titik(): array
    {
        return self::w()['titik'][0];
    }

    private function alat(?float $resolusi = null): Equipment
    {
        return $this->alatAnalitik('DO Meter', 'mg/L', $resolusi ?? self::nilai(self::w()['masukan']['resolusi_alat']));
    }

    /** @return array<string, mixed> */
    private function hitung(?float $resolusiAlat = null): array
    {
        return $this->hitungTitikAnalitik(1, self::titik(), $this->alat($resolusiAlat), $this->standarSeeder(self::STANDAR));
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi (seeder produksi dan profil) = sel workbook: U95 larutan (`DATABASE!V13`),
     * CMC (`DATABASE!S5`), UTemperature (`PERHITUNGAN U95%!AE5` dari `DATABASE!V14/V15`), Uc pengenceran
     * (`AE13` dari `DATABASE!V17/V18`), resolusi (`INPUT DATA!E16`), dan rumus ci/u yang dibaca dari sel.
     */
    public function test_konstanta_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();
        $t = self::titik();
        $k = $w['konstanta'];
        $std = $this->standarSeeder(self::STANDAR);

        $this->assertSamaExcel($t['u95_standar_database'], (float) $std->ketidakpastian, 'U95 larutan oksigen 8,77');
        $this->assertSame(2.0, (float) $std->faktor_cakupan, 'k larutan = 2 (pembagi Excel P23)');

        $cmc = CalibrationCapability::where('equipment_category_id', $this->kategoriAnalitik->id)
            ->where('nama_alat', 'DO Meter')
            ->where('range_min', $t['masukan']['titik_ukur']['nilai'])
            ->firstOrFail();

        $this->assertSamaExcel($t['cmc_database'], (float) $cmc->ketidakpastian_terbaik, 'CMC');
        $this->assertSamaExcel($k['u_temperature'], (float) $cmc->u_temperature, 'UTemperature', rel: 1e-7);
        $this->assertSamaExcel($k['u_temperature'], sqrt((self::nilai($k['u95_termometer_c']) / 2) ** 2 + (self::nilai($k['u95_sensor_c']) / 2) ** 2), 'AE5 = sqrt((V14/2)^2 + (V15/2)^2)');
        $this->assertSamaExcel($k['uc_pengenceran_ml'], DoMeterProfile::ucPengenceran(), 'Uc pengenceran (mL)');
        $this->assertSamaExcel($k['uc_pengenceran_ml'], sqrt((self::nilai($k['u95_micropipette_ul']) / 2 / 1000) ** 2 + (self::nilai($k['u95_labu_ukur_ml']) / 2) ** 2), 'AE13 dari V17/V18');
        $this->assertSamaExcel($w['masukan']['resolusi_alat'], (new DoMeterProfile)->resolusiTitik($t['masukan']['titik_ukur']['nilai']), 'resolusi (INPUT DATA!E16)');

        // Rumus sel yang ditiru profil: ci suhu = (UTemp/400)*titik, ci pengenceran = (Uc/100)*titik, u pengenceran = Uc/1000.
        $this->assertSame('=(AE5/400)*C21', $k['rumus_ci_suhu']['rumus']);
        $this->assertSame('=AE13/100*C21', $k['rumus_ci_pengenceran']['rumus']);
        $this->assertSame('=AE13/1000', $k['rumus_u_pengenceran']['rumus']);
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar, rata-rata UUT, STDEV sampel, dan koreksi (cetak: Std - UUT = `SERTIFIKAT!S24`;
     * lembar: UUT - Std = `PERHITUNGAN!G43`). Blok "Before Adjustment" (`G29:G31`) berisi angka yang
     * sama dengan blok "After" di sampel, jadi tes tidak bisa membedakan blok mana yang dibaca aplikasi;
     * aplikasi membaca pembacaan sesudah adjustment (sama dengan `SERTIFIKAT!N24 = PERHITUNGAN!G42`).
     */
    public function test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel(): void
    {
        $t = self::titik();
        $h = $this->hitung();
        $s = $t['standar'];

        $this->assertSamaExcel($s['nilai_standar'], $h['titik_ukur'], 'nilai standar nominal');
        $this->assertSamaExcel($s['rata_rata_uut'], $h['rata_rata'], 'rata-rata UUT sesudah adjustment');
        $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], 'STDEV sampel');
        $this->assertSamaExcel($t['cetak']['koreksi'], $h['koreksi'], 'koreksi cetak (Std - UUT)');
        $this->assertSamaExcel($s['koreksi_lembar_uut_minus_standar'], -$h['koreksi'], 'koreksi lembar (UUT - Std) = -koreksi aplikasi');

        $sebelum = $t['sebelum_adjustment'];
        $this->assertSamaExcel($sebelum['rata_rata'], $h['rata_rata'], 'sampel: blok Before = After (rata-rata)');
        $this->assertSamaExcel($sebelum['stdev_sampel'], $h['standar_deviasi'], 'sampel: blok Before = After (STDEV)');
    }

    // ------------------------------------------------------------------ tahap budget

    /** Lima komponen (ui, ci, vi, uici) sel demi sel. */
    public function test_lima_komponen_budget_cocok_excel(): void
    {
        $t = self::titik();
        $komp = collect($this->hitung()['type_b_components'])->keyBy('sumber');

        $this->assertSame(self::KOMPONEN, array_keys($t['komponen']), 'urutan komponen = baris 23-27 PERHITUNGAN U95%');
        // `perbandingan_cmc` adalah baris jejak audit lantai CMC yang selalu ditambahkan `GumCalculator`,
        // bukan komponen budget; lima baris Excel (`PERHITUNGAN U95%` 23-27) harus ada semuanya, tidak lebih.
        $this->assertSame(['perbandingan_cmc'], array_values(array_diff($komp->keys()->all(), self::KOMPONEN)), 'komponen aplikasi = lima komponen Excel + jejak audit');

        foreach (self::KOMPONEN as $nama) {
            $x = $t['komponen'][$nama];
            $b = $komp[$nama];
            // Hanya komponen yang memakai UTemperature tersimpan (`decimal(20,8)`) yang diberi toleransi penyimpanan.
            $rel = $nama === 'ketidakpastian_temperature' ? self::TOL_SIMPAN_KOMPONEN : self::TOL_REL;

            $this->assertSamaExcel($x['vi'], (float) $b['vi'], "{$nama}: vi");
            $this->assertSamaExcel($x['ui'], (float) $b['u_baku'], "{$nama}: ui = u/pembagi", rel: $rel);
            $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$nama}: ci", rel: $rel);
            $this->assertSamaExcel($x['uici'], (float) $b['nilai'], "{$nama}: ui*ci", rel: $rel);
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /**
     * uc, v_eff, k, U (sebelum lantai), CMC, dan U cetak. Di sampel U hitung < CMC, jadi lantai CMC menang;
     * cabang U > CMC tidak teruji di sini (dikunci dengan assertLessThan agar perubahan sampel ketahuan).
     */
    public function test_uc_veff_k_u_dan_lantai_cmc_cocok_excel(): void
    {
        $h = $this->hitung();
        $a = self::titik()['agregat'];

        $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], 'uc', rel: self::TOL_SIMPAN_UC);
        $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], 'v_eff', rel: self::TOL_SIMPAN_VEFF);
        $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], 'k (TINV, df dipotong)');
        $this->assertSamaExcel($a['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], 'U = k*uc sebelum lantai', rel: self::TOL_SIMPAN_UC);
        $this->assertSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], 'U sesudah lantai CMC');
        $this->assertSamaExcel($a['cmc'], $h['ketidakpastian_diperluas'], 'U cetak = CMC (lantai menang di sampel)');
        $this->assertLessThan(self::nilai($a['cmc']), self::nilai($a['u_hitung']), 'sampel: U hitung di bawah CMC');
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK (Standard, UUT, Correction, U95; `SERTIFIKAT!E24, N24, S24, Q25`): teks sama persis pada
     * pemformat produksi `Angka`; desimal = resolusi 0,01 (`Angka::desimalDariResolusi`) = format sel `0.00`.
     * `k` tercetak `2` (`SERTIFIKAT!V26`, format `0`; `DoMeterProfile::desimalFaktorCakupan()` = 0).
     */
    public function test_angka_cetak_sama_dengan_sertifikat_excel(): void
    {
        $profil = new DoMeterProfile;
        $h = $this->hitung();
        $c = self::titik()['cetak'];
        // Urutan produksi (`CertificateSnapshotBuilder`): profil, lalu resolusi ALAT lewat `Angka`.
        $desimal = $profil->desimalSertifikat() ?? Angka::desimalDariResolusi((float) $this->alat()->resolusi);

        foreach (['standar', 'uut', 'koreksi', 'u95'] as $kolom) {
            $this->assertSame('0.00', $c[$kolom]['format'], "format sel {$c[$kolom]['sel']}");
        }
        $this->assertSame(2, $desimal, 'desimal cetak aplikasi');

        $this->assertSame($c['standar']['teks'], self::keTitik(Angka::nilaiStandar($h['titik_ukur'], $desimal)), "Standard Value vs {$c['standar']['sel']}");
        $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal)), "UUT vs {$c['uut']['sel']}");
        $this->assertSame($c['koreksi']['teks'], self::keTitik(Angka::hasil($h['koreksi'], $desimal, tandaNol: $profil->tandaNolDicetak())), "Correction vs {$c['koreksi']['sel']}");
        $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal)), "U95 vs {$c['u95']['sel']}");

        $k = self::w()['cetak_k'];
        $this->assertSame('0', $k['format'], "format sel {$k['sel']}");
        $this->assertSame(0, $profil->desimalFaktorCakupan(), 'desimal k aplikasi');
        $this->assertSame($k['teks'], self::keTitik(Angka::hasil($h['faktor_cakupan_k'], (int) $profil->desimalFaktorCakupan())), "k tercetak vs {$k['sel']}");

        $this->assertSame('Dissolved Oxygen', $profil->remarkTitik($h['titik_ukur']));
    }

    /**
     * Tanggal terbit: `SERTIFIKAT!B39 = T8+1`; aplikasi `FormatMasterSertifikat` +1 = SAMA. `INPUT DATA!D52 = O16+4`
     * (angka lain di workbook yang sama) tidak dicetak, jadi tidak ada pertentangan di sertifikat.
     */
    public function test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat(): void
    {
        $t = self::w()['tanggal_terbit'];

        $this->assertSame(1, preg_match('/^=T8\+(\d+)$/', $t['sertifikat']['rumus'], $m), "rumus {$t['sertifikat']['rumus']} tidak dikenal");
        $this->assertSame((int) $m[1], FormatMasterSertifikat::tanggalUntukProfil('do_meter')['tambah_hari']);
        $this->assertStringEndsWith('+4', $t['input_data']['rumus'], 'INPUT DATA!D52 = O16+4 berbeda tetapi tidak dicetak');
    }

    // ------------------------------------------------------------------ kondisi lingkungan

    /**
     * Blok KONDISI LINGKUNGAN (`PERHITUNGAN!E14:Q15`, thermohygro TH-2): suhu dan kelembaban SAMA di semua tahap
     * (rata-rata, indexed value, koreksi, delta, U95 TH, U95 sertifikat, nilai tercetak `SERTIFIKAT!U10/U11`).
     *
     * `PERHITUNGAN!H14` (19,77) dan `H15` (49) adalah angka yang DIKETIK di sel, bukan hasil rumus: Excel tidak
     * menurunkannya dari rata-rata. Aplikasi memilih titik indeks dengan instrumen terdekat dari rata-rata
     * (23,7 -> 20,2; 53,5 -> 50), dan di sampel itu jatuh ke baris tabel yang sama. Aturan pemilihannya
     * berbeda, hasil sampelnya SAMA.
     */
    public function test_kondisi_lingkungan_th2_cocok_excel(): void
    {
        $k = self::w()['kondisi_lingkungan'];
        $th = $k['th']['nilai'];
        $this->assertSame('TH-2', $th);
        $this->assertStringContainsString('$H$15', $k['rumus_u95_th_rh']['rumus'], 'Excel DO Meter: U95 TH per titik RH (sama dengan aplikasi)');

        foreach (['suhu', 'kelembaban'] as $parameter) {
            $e = $k[$parameter];
            $this->assertTrue($e['indexed_value_tempelan'], "{$parameter}: indexed value Excel adalah angka ketikan");

            $app = $this->kondisiLingkungan($th, $parameter, self::nilai($e['masukan_diketik']['awal']), self::nilai($e['masukan_diketik']['akhir']));

            foreach ([['average', 'average'], ['indexed_value', 'indexed_value'], ['koreksi', 'correction'], ['delta', 'delta'], ['u95_std_th', 'u95_std_th'], ['u95_sertifikat', 'u95_sertifikat']] as [$kunciExcel, $kunciApp]) {
                $this->assertSamaExcel($e[$kunciExcel], $app[$kunciApp], "{$parameter}: {$kunciExcel}");
            }
            $this->assertSamaExcel($e['nilai_cetak'], $app['nilai_terkoreksi'], "{$parameter}: nilai tercetak");
        }

        $this->assertSame('0.0', $k['format_cetak']['suhu']);
        $this->assertSame('0', $k['format_cetak']['rh']);
    }

    // ------------------------------------------------------------------ konflik

    /**
     * SELISIH B4-1 — blok %O2. Master mencetak baris kedua di `SERTIFIKAT!E19:S19` (standar 20,9, instrumen 0,
     * koreksi 20,9) dari titik `INPUT DATA!J41` yang pembacaannya KOSONG (rata-rata jadi 0), dan U95/k blok itu
     * `#REF!` (`SERTIFIKAT!Q20`, `W21`; rantai `PERHITUNGAN U95%!AB43:AB48` `#DIV/0!`). Aplikasi hanya punya
     * satu titik (8,77) dan tidak mengenali 20,9. Sengaja tidak ditiru: angka itu bukan hasil ukur.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b4_1_blok_o2_rusak_di_excel_tidak_ada_padanan_aplikasi(): void
    {
        $o2 = self::w()['blok_o2_galat'];

        $this->assertSame([true, true, true, true, true], $o2['pembacaan_o2_sesudah_kosong'], 'INPUT DATA!J43:J47 kosong');
        $this->assertSame('#REF!', $o2['u95_cetak_o2']['nilai_teks'], $o2['u95_cetak_o2']['sel']);
        $this->assertSame('#REF!', $o2['k_cetak_o2']['nilai_teks'], $o2['k_cetak_o2']['sel']);
        foreach (['komponen_suhu_o2', 'uc_o2', 'k_o2', 'u_o2', 'u_sertifikat_o2'] as $kunci) {
            $this->assertSame('#DIV/0!', $o2[$kunci]['nilai_teks'], $o2[$kunci]['sel']);
        }
        $this->assertSamaExcel($o2['cetak_o2']['standar'], self::nilai($o2['titik_ukur_o2']), 'Excel mencetak standar %O2');
        $this->assertSamaExcel($o2['cetak_o2']['instrumen'], 0.0, 'Excel mencetak instrumen %O2 = 0');
        $this->assertSamaExcel($o2['cetak_o2']['koreksi'], 20.9, 'Excel mencetak koreksi %O2 = 20,9');

        $profil = new DoMeterProfile;
        $this->assertCount(1, DoMeterProfile::TITIK, 'aplikasi hanya punya titik 8,77 mg/L');
        $this->assertNull($profil->remarkTitik(20.9), 'aplikasi tidak mengenali titik %O2');
    }

    /**
     * SELISIH P-25 — resolusi. Excel: `PERHITUNGAN U95%!M24 = G6*0,5` dan `G6 = INPUT DATA!E16` (angka DIKETIK).
     * Aplikasi memakai yang LEBIH BESAR antara master (0,01) dan resolusi alat. Ketikan lebih kasar dari master:
     * SAMA dengan Excel. Ketikan lebih halus: Excel memberi u resolusi lebih kecil, aplikasi tetap 0,01 (SELISIH).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p25_resolusi_aplikasi_lebih_besar_dari_ketikan_lebih_halus(): void
    {
        $k = self::w()['konstanta'];
        $this->assertSame('=($G$6*0.5)', $k['rumus_resolusi_ui']['rumus'], 'Excel: u resolusi = ketikan * 0,5');
        $this->assertSame("='INPUT DATA'!E16", $k['rumus_resolusi_sumber']['rumus'], 'Excel: G6 = isian INPUT DATA');

        $uiExcel = fn (float $ketikan): float => ($ketikan * 0.5) / sqrt(3);
        $uiAplikasi = function (float $resolusi): float {
            $b = collect($this->hitung($resolusi)['type_b_components'])->keyBy('sumber');

            return (float) $b['resolusi_alat']['u_baku'];
        };

        $master = self::nilai(self::w()['masukan']['resolusi_alat']);
        $this->assertSamaHitungUlang($uiExcel($master), $uiAplikasi($master), 'resolusi: ketikan = master');

        $kasar = $master * 10;
        $this->assertSamaHitungUlang($uiExcel($kasar), $uiAplikasi($kasar), 'resolusi: ketikan 10x lebih kasar, aplikasi mengikuti');

        $halus = $master / 10;
        $this->assertSamaHitungUlang($uiExcel($master), $uiAplikasi($halus), 'resolusi: aplikasi tetap memakai master walau ketikan lebih halus');
        $this->assertGreaterThan($uiExcel($halus) * 5, $uiAplikasi($halus), 'Excel memberi u resolusi 10x lebih kecil (SELISIH P-25)');
    }

    /**
     * Excel tidak punya kolom toleransi/MPE maupun sel PASS/FAIL (label dipindai di seluruh sheet; nol hasil),
     * dan sertifikatnya tidak memvonis. Aplikasi sama: `punyaToleransi()` false, `keputusan` sesi null.
     * SAMA, tidak ada konflik P-29 di alat ini.
     */
    public function test_excel_dan_aplikasi_sama_sama_tanpa_vonis(): void
    {
        $this->assertFalse((new DoMeterProfile)->punyaToleransi());
    }
}
