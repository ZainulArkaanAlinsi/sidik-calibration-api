<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use Database\Seeders\ChlorineCapabilitySeeder;
use Database\Seeders\ChlorineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\Concerns\MenyiapkanAlatAnalitik;
use Tests\TestCase;

/**
 * PARITAS Chlorine Meter: aplikasi lawan `Master Olah Data_Chlorine Meter.xlsm` (dua titik: 1,74 mg/L
 * Free Chlorine dan 1,83 mg/L Total Chlorine), tahap demi tahap. Fixture
 * `tests/Fixtures/paritas/chlorine-meter.json`, dibangun `docs/skrip/gen-fixture-paritas-analitik-batch2.py`.
 *
 * Titik panggil terkecil tanpa HTTP: `GumCalculator::hitungTitik()`. Konstanta aplikasi dibaca dari
 * SEEDER produksi (`ChlorineCapabilitySeeder`, `ChlorineSeeder`) dan `ChlorineProfile`.
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-analitik-batch2.md`):
 *  P-27 Type A titik 1,83: sel `PERHITUNGAN U95%!T41` adalah ANGKA TEMPELAN 0,0245 (bukan rumus);
 *  P-28 sisa templat Excel: baris sertifikat ke-3 (0 mg/L), k dari baris kosong, tanda koreksi
 *       `PERHITUNGAN!I43` terbalik;
 *  P-29 Excel tanpa vonis/toleransi.
 */
#[Group('paritas')]
class ParitasChlorineMeterTest extends TestCase
{
    use MenegakkanParitas;
    use MenyiapkanAlatAnalitik;
    use RefreshDatabase;

    private const STANDAR = [
        '1.74 mg/L' => 'Chlorine Standard Solution 1.74 mg/L',
        '1.83 mg/L' => 'Chlorine Standar Cuvettes 1.83 mg/L',
    ];

    private const KOMPONEN = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'faktor_pengenceran', 'pengulangan_pembacaan'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAnalitik([ChlorineCapabilitySeeder::class, ChlorineSeeder::class], thermohygro: true);
    }

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('chlorine-meter')['workbook'];
    }

    private function alat(): Equipment
    {
        // Nama kemampuan resmi profil ini `Chlorin Meter` (tanpa "e"), sama dengan lampiran akreditasi.
        return $this->alatAnalitik((new ChlorineProfile)->namaAlatKemampuan(), 'mg/L', self::nilai(self::w()['masukan']['resolusi_alat']));
    }

    /** @return array<string, mixed> */
    private function hitung(int $indeks): array
    {
        $t = self::w()['titik'][$indeks];

        return $this->hitungTitikAnalitik($indeks + 1, $t, $this->alat(), $this->standarSeeder(self::STANDAR[$t['nama']]));
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi = sel workbook: U95 larutan (`DATABASE!W13:W14`), CMC (`DATABASE!T5:T6`),
     * UTemperature (`PERHITUNGAN U95%!AE4`), resolusi (`INPUT DATA!E16`), Uc pengenceran dalam liter
     * (`M25 = AE11/1000`) dan koefisien pengenceran (`W25`, `W40`).
     */
    public function test_konstanta_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();
        $k = $w['konstanta'];

        foreach ($w['titik'] as $t) {
            $std = $this->standarSeeder(self::STANDAR[$t['nama']]);
            $this->assertSamaExcel($t['u95_standar_database'], (float) $std->ketidakpastian, "{$t['nama']}: U95 larutan standar");
            $this->assertSame(2.0, (float) $std->faktor_cakupan, "{$t['nama']}: k larutan = 2 (pembagi Excel P22/P37)");

            $cmc = CalibrationCapability::where('equipment_category_id', $this->kategoriAnalitik->id)
                ->where('nama_alat', (new ChlorineProfile)->namaAlatKemampuan())
                ->where('range_min', $t['masukan']['titik_ukur']['nilai'])
                ->firstOrFail();

            $this->assertSamaExcel($t['cmc_database'], (float) $cmc->ketidakpastian_terbaik, "{$t['nama']}: CMC");
            $this->assertSamaExcel($k['u_temperature'], (float) $cmc->u_temperature, "{$t['nama']}: UTemperature", rel: 1e-7);
        }

        $this->assertSamaExcel($w['masukan']['resolusi_alat'], ChlorineProfile::RESOLUSI, 'resolusi alat (konstanta profil = INPUT DATA!E16)');
        $this->assertSamaExcel($k['uc_pengenceran_l'], ChlorineProfile::ucPengenceran(), 'Uc pengenceran (L): sqrt((U micropipette/2)^2 + (U labu ukur/2)^2)/1000');
        $this->assertSamaExcel($k['ci_pengenceran_titik_1'], ChlorineProfile::CI_PENGENCERAN, 'ci pengenceran titik 1,74 (W25 = AE11/100*C20)');
        // Titik 1,83: W40 = W25 (disalin), BUKAN AE11/100*C35 — aplikasi memakai satu konstanta untuk kedua titik.
        $this->assertSamaExcel($k['ci_pengenceran_titik_2'], ChlorineProfile::CI_PENGENCERAN, 'ci pengenceran titik 1,83 (W40 = W25)');
        $this->assertSame('=W25', $k['rumus_ci_pengenceran_titik_2']['rumus'], 'W40 menyalin W25');
        $this->assertSamaExcel($k['u_temperature'], sqrt((self::nilai($k['u95_termometer_c']) / 2) ** 2 + (self::nilai($k['u95_sensor_c']) / 2) ** 2), 'AE4 = sqrt((W18/2)^2 + (W19/2)^2)');
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar (nominal), rata-rata UUT, STDEV, dan koreksi cetak (`SERTIFIKAT!P18:P19` = Std - UUT).
     * Koreksi di lembar `PERHITUNGAN!G43/I43`: titik 1 `=G42-G35` (UUT - Std), titik 2 `=I35-I42` (Std - UUT);
     * tandanya TERBALIK antar titik di sheet yang sama = SELISIH P-28 (hanya tampilan lembar, bukan sertifikat).
     */
    public function test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $s = $t['standar'];
            $ke = $t['nama'];

            $this->assertSamaExcel($s['nilai_standar'], $h['titik_ukur'], "{$ke}: nilai standar nominal (suhu larutan tidak mengoreksi standar)");
            $this->assertSamaExcel($s['rata_rata_uut'], $h['rata_rata'], "{$ke}: rata-rata UUT sesudah adjustment");
            $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], "{$ke}: STDEV sampel");
            $this->assertSamaExcel($t['cetak']['koreksi'], $h['koreksi'], "{$ke}: koreksi cetak (Std - UUT)");

            // Tanda koreksi di lembar PERHITUNGAN: titik 1 = UUT - Std (= -koreksi), titik 2 = Std - UUT (= +koreksi).
            $this->assertSame($i === 0 ? '=G42-G35' : '=I35-I42', $s['rumus_koreksi_lembar']['rumus'], "{$ke}: rumus {$s['rumus_koreksi_lembar']['sel']}");
            $this->assertSamaExcel($s['koreksi_lembar'], $i === 0 ? -$h['koreksi'] : $h['koreksi'], "{$ke}: koreksi lembar {$s['koreksi_lembar']['sel']}");
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /**
     * Lima komponen (ui, ci, vi, uici). Titik 1,83: komponen pengulangan BEDA = SELISIH P-27 (diuji terpisah);
     * empat komponen lain SAMA.
     */
    public function test_komponen_budget_cocok_excel_kecuali_type_a_titik_183(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $komp = collect($h['type_b_components'])->keyBy('sumber');

            foreach (self::KOMPONEN as $nama) {
                if ($i === 1 && $nama === 'pengulangan_pembacaan') {
                    continue; // P-27
                }

                $x = $t['komponen'][$nama];
                $b = $komp[$nama];
                $ke = "{$t['nama']} {$nama}";
                $rel = $nama === 'ketidakpastian_temperature' ? self::TOL_SIMPAN_KOMPONEN : self::TOL_REL;

                $this->assertSamaExcel($x['vi'], (float) $b['vi'], "{$ke}: vi");
                $this->assertSamaExcel($x['ui'], (float) $b['u_baku'], "{$ke}: ui = u/pembagi", rel: $rel);
                $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$ke}: ci", rel: $rel);
                $this->assertSamaExcel($x['uici'], (float) $b['nilai'], "{$ke}: ui*ci", rel: $rel);
            }
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /** Titik 1,74: uc, v_eff, k, U, CMC dan U cetak SAMA di semua tahap. */
    public function test_uc_veff_k_u_dan_lantai_cmc_cocok_excel_untuk_titik_174(): void
    {
        $t = self::w()['titik'][0];
        $h = $this->hitung(0);
        $a = $t['agregat'];
        $ke = $t['nama'];

        $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc", rel: self::TOL_SIMPAN_UC);
        $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff", rel: self::TOL_SIMPAN_VEFF);
        $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], "{$ke}: k (TINV, df dipotong)");
        $this->assertSamaExcel($a['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U = k*uc sebelum lantai", rel: self::TOL_SIMPAN_UC);
        $this->assertSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U sesudah lantai CMC (U hitung 0,0890 < CMC 0,091)");
        $this->assertSamaExcel($a['cmc'], $h['ketidakpastian_diperluas'], "{$ke}: U cetak = CMC (lantai menang)");
    }

    /**
     * SELISIH P-27 — Type A titik 1,83. `PERHITUNGAN U95%!T41` (ui pengulangan) adalah ANGKA TEMPELAN
     * 0,02449489742783176, bukan `=M41/SQRT(P41)`: STDEV titik ini 0 (enam pembacaan seragam 1,86), jadi
     * rumusnya memberi 0. Excel memakai tempelan (uc 0,0388; U 0,0802 > CMC 0,08); aplikasi memakai
     * Type A dari pembacaan (0; uc 0,0301; U 0,0594 < CMC, lantai CMC 0,08). Mentah beda, TERCETAK sama (0,08).
     *
     * Tes mengunci: (a) aplikasi = rumus sel dengan ui pengulangan dari pembacaan (penyebab tunggal);
     * (b) uc aplikasi jauh dari cache Excel; (c) teks cetak U95 sama.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p27_type_a_titik_183_tempelan_lawan_pembacaan(): void
    {
        $t = self::w()['titik'][1];
        $h = $this->hitung(1);
        $s = $t['dengan_type_a_dari_pembacaan'];
        $komp = collect($h['type_b_components'])->keyBy('sumber');

        $this->assertNull($t['rumus_ui_pengulangan']['rumus'], 'T41 bukan rumus: angka tempelan');
        $this->assertSamaHitungUlang(0.0, $t['standar']['stdev_sampel']['nilai'], 'STDEV titik 1,83 di workbook = 0');
        $this->assertGreaterThan(0.02, self::nilai($t['komponen']['pengulangan_pembacaan']['ui']), 'Excel: ui pengulangan tempelan 0,0245 padahal STDEV 0');
        $this->assertSamaHitungUlang(0.0, (float) $komp['pengulangan_pembacaan']['u_baku'], 'aplikasi: ui pengulangan = STDEV/sqrt(n) = 0');

        // (a) aplikasi = rumus sel dengan ui pengulangan := 0.
        $this->assertSamaHitungUlang($s['uc'], $h['ketidakpastian_gabungan'], "{$t['nama']}: uc (rumus sel, Type A dari pembacaan)", self::TOL_SIMPAN_UC);
        $this->assertSamaHitungUlang($s['veff'], (float) $h['derajat_kebebasan_efektif'], "{$t['nama']}: v_eff", self::TOL_SIMPAN_VEFF);
        $this->assertSamaHitungUlang($s['k'], $h['faktor_cakupan_k'], "{$t['nama']}: k", 0.0, self::TOL_K);
        $this->assertSamaHitungUlang($s['u_cetak'], $h['ketidakpastian_diperluas'], "{$t['nama']}: U cetak = CMC 0,08", self::TOL_SIMPAN_UC);

        // (b) selisih ke cache Excel ada dan besar (tempelan menaikkan uc 29%).
        $a = $t['agregat'];
        $this->assertGreaterThan(1.2 * $h['ketidakpastian_gabungan'], self::nilai($a['uc']), "{$a['uc']['sel']} Excel > 1,2x uc aplikasi (SELISIH P-27)");
        $this->assertGreaterThan(self::nilai($a['cmc']), self::nilai($a['u_cetak']), "Excel: U sertifikat {$a['u_cetak']['sel']} = U hitung 0,0802 > CMC 0,08");

        // (c) yang TERCETAK sama: dua desimal, 0,08 di kedua sisi.
        $this->assertSame($t['cetak']['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], 2)), "U95 tercetak {$t['cetak']['u95']['sel']}");
        $this->assertSame('0.08', $t['cetak']['u95']['teks']);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik (Standard Value, UUT, Correction, U95) dan Remark (Free/Total Chlorine), teks
     * sama persis dengan `SERTIFIKAT!C18:W19`; desimal = resolusi 0,01 (`Angka::desimalDariResolusi`) =
     * format sel `0.00`. `k` tercetak `2` (format `0`).
     */
    public function test_angka_cetak_dan_remark_sama_dengan_sertifikat_excel(): void
    {
        $profil = new ChlorineProfile;
        $desimal = $profil->desimalSertifikat() ?? Angka::desimalDariResolusi(ChlorineProfile::RESOLUSI);

        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $c = $t['cetak'];
            $ke = $t['nama'];

            foreach (['standar', 'uut', 'koreksi', 'u95'] as $kolom) {
                $this->assertSame('0.00', $c[$kolom]['format'], "{$ke}: format sel {$c[$kolom]['sel']}");
            }
            $this->assertSame(2, $desimal, "{$ke}: desimal cetak aplikasi");

            $this->assertSame($c['standar']['teks'], self::keTitik(Angka::nilaiStandar($h['titik_ukur'], $desimal)), "{$ke}: Standard Value vs {$c['standar']['sel']}");
            $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal)), "{$ke}: UUT vs {$c['uut']['sel']}");
            $this->assertSame($c['koreksi']['teks'], self::keTitik(Angka::hasil($h['koreksi'], $desimal, tandaNol: $profil->tandaNolDicetak())), "{$ke}: Correction vs {$c['koreksi']['sel']}");
            $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal)), "{$ke}: U95 vs {$c['u95']['sel']}");
            $this->assertSame($c['remark']['nilai'], $profil->remarkTitik($h['titik_ukur']), "{$ke}: Remark vs {$c['remark']['sel']}");
        }

        // `k`: SERTIFIKAT!U21 = k BARIS KETIGA (kosong) = 1,96; teks `2` sama dengan k titik 1 (1,97) berformat `0`.
        $k = self::w()['cetak_k'];
        $this->assertSame($k['teks'], self::keTitik(Angka::hasil($this->hitung(0)['faktor_cakupan_k'], 0)), "k tercetak vs {$k['sel']}");
    }

    /** Tanggal terbit: `SERTIFIKAT!B38 = T9+2`; aplikasi `FormatMasterSertifikat` +2. `INPUT DATA!D52 = O16+4` tidak dicetak. */
    public function test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat(): void
    {
        $t = self::w()['tanggal_terbit'];
        $this->assertSame(1, preg_match('/^=T9\+(\d+)$/', $t['sertifikat']['rumus'], $m), "rumus {$t['sertifikat']['rumus']} tidak dikenal");
        $this->assertSame((int) $m[1], FormatMasterSertifikat::tanggalUntukProfil('chlorine_meter')['tambah_hari']);
        $this->assertStringEndsWith('+4', $t['input_data']['rumus'], 'INPUT DATA!D52 = O16+4 berbeda tetapi tidak dicetak');
    }

    // ------------------------------------------------------------------ kondisi lingkungan

    /**
     * Blok KONDISI LINGKUNGAN (`PERHITUNGAN!G14:O15`, thermohygro TH-4): suhu dan kelembaban SAMA di semua
     * tahap (rata-rata, indexed value, koreksi, delta, U95 TH per titik RH, U95 sertifikat, nilai tercetak).
     */
    public function test_kondisi_lingkungan_th4_cocok_excel(): void
    {
        $k = self::w()['kondisi_lingkungan'];
        $th = $k['th']['nilai'];
        $this->assertSame('TH-4', $th);
        $this->assertStringContainsString('$H$15', $k['rumus_u95_th_rh']['rumus'], 'Excel Chlorine: U95 TH per titik RH (sama dengan aplikasi)');

        foreach (['suhu', 'kelembaban'] as $parameter) {
            $e = $k[$parameter];
            $app = $this->kondisiLingkungan($th, $parameter, self::nilai($e['masukan_diketik']['awal']), self::nilai($e['masukan_diketik']['akhir']));

            foreach ([['average', 'average'], ['indexed_value', 'indexed_value'], ['koreksi', 'correction'], ['delta', 'delta'], ['u95_std_th', 'u95_std_th'], ['u95_sertifikat', 'u95_sertifikat']] as [$kunciExcel, $kunciApp]) {
                $this->assertSamaExcel($e[$kunciExcel], $app[$kunciApp], "{$parameter}: {$kunciExcel}");
            }
            $this->assertSamaExcel($e['nilai_cetak'], $app['nilai_terkoreksi'], "{$parameter}: nilai tercetak");
        }
    }

    // ------------------------------------------------------------------ konflik

    /**
     * SELISIH P-28 — sisa templat Excel yang tidak ada padanannya: baris sertifikat ke-3 (`K32` kosong ->
     * `SERTIFIKAT!C20:S20` = 0 / 0 / 0 / 0,0057 dan k `U21` = 1,9600 dari baris ke-3), dan standar 1,74
     * yang tercetak dua kali. Aplikasi hanya punya DUA titik (`ChlorineProfile::TITIK`), jadi k tercetak
     * berasal dari titik 1 (1,97) — angka cetaknya (`2`) sama, nilai mentahnya beda.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p28_baris_ketiga_kosong_tidak_punya_padanan(): void
    {
        $w = self::w();
        $kosong = $w['titik_ke_3_kosong'];

        $this->assertCount(2, ChlorineProfile::TITIK, 'aplikasi punya dua titik; master mencetak baris ketiga dari sel kosong');
        $this->assertSame(0.0, self::nilai($kosong['standar']));
        $this->assertGreaterThan(0.005, self::nilai($kosong['u95']), 'Excel mencetak U95 0,0057 untuk baris kosong');
        $this->assertGreaterThan(1e-3, abs(self::nilai($w['cetak_k']) - $this->hitung(0)['faktor_cakupan_k']), 'k Excel (baris ke-3) 1,9600 lawan k aplikasi (titik 1) 1,9718');
    }

    /** P-29 — Excel tidak punya vonis/toleransi; aplikasi menahan hitung sampai `toleransi` alat diisi. */
    #[Group('menunggu-keputusan-tm')]
    public function test_p29_profil_menuntut_toleransi_walau_excel_tidak_punya(): void
    {
        $this->assertTrue((new ChlorineProfile)->punyaToleransi(), 'aplikasi menuntut toleransi (P-29); workbook tidak punya satu pun sel PASS/FAIL/toleransi');
    }
}
