<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use Database\Seeders\RefractometerCapabilitySeeder;
use Database\Seeders\RefractometerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\Concerns\MenyiapkanAlatAnalitik;
use Tests\TestCase;

/**
 * PARITAS Refractometer (n20D): aplikasi lawan `Master Olah Data_Refractometer.xlsm`,
 * tahap demi tahap. Fixture `tests/Fixtures/paritas/refractometer.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-analitik-batch2.py` dari nilai-cache workbook.
 *
 * Titik panggil terkecil tanpa HTTP: `GumCalculator::hitungTitik()` (fungsi yang sama
 * dengan preview, simpan, dan hitung ulang). Konstanta aplikasi dibaca dari SEEDER
 * produksi (`RefractometerCapabilitySeeder`, `RefractometerSeeder`), bukan diketik ulang.
 *
 * Hanya perilaku BAWAAN yang diuji: parameter keputusan metode `refractometer.sumber_deviasi_suhu`
 * (PR #298) dibiarkan di bawaannya `suhu_ruang`; pilihan `baris_tetap_21c` tidak disentuh.
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-analitik-batch2.md`):
 *  P-20 deviasi suhu: sel tetap Excel lawan suhu ruang aplikasi (sama di sesi contoh saja);
 *  P-21 U95 kelembaban thermohygro: per unit TH (Excel) lawan per titik RH (aplikasi);
 *  P-22 UTemperature: dua nilai untuk satu termometer di workbook yang sama.
 */
#[Group('paritas')]
class ParitasRefractometerTest extends TestCase
{
    use MenegakkanParitas;
    use MenyiapkanAlatAnalitik;
    use RefreshDatabase;

    /** nama larutan seeder per titik fixture. */
    private const STANDAR = ['1.33659 n20D' => 'Refractometer Std Solution 1.33659 n20D', '1.39986 n20D' => 'Refractometer Std Solution 1.39986 n20D'];

    private const KOMPONEN = ['ketidakpastian_standar', 'resolusi_alat', 'perbedaan_suhu', 'ketidakpastian_temperature', 'pengulangan_pembacaan'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAnalitik([RefractometerCapabilitySeeder::class, RefractometerSeeder::class], thermohygro: true);
    }

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('refractometer')['workbook'];
    }

    private function alat(): Equipment
    {
        return $this->alatAnalitik('Refractometer', 'n20D', self::nilai(self::w()['masukan']['resolusi_alat']));
    }

    /** Suhu ruang MENTAH (awal+akhir)/2 seperti `CalibrationController` — bukan suhu terkoreksi thermohygro. */
    private static function suhuRuang(): float
    {
        return self::nilai(self::w()['masukan']['suhu_ruang_c']['rata_rata']);
    }

    /** @return array<string, mixed> */
    private function hitung(int $indeks, ?float $suhuRuang = null): array
    {
        $t = self::w()['titik'][$indeks];

        return $this->hitungTitikAnalitik($indeks + 1, $t, $this->alat(), $this->standarSeeder(self::STANDAR[$t['nama']]), $suhuRuang ?? self::suhuRuang());
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi (seeder produksi) = sel workbook: U95 larutan (`DATABASE!V15:V16`),
     * CMC (`DATABASE!S5:S6`), UTemperature (`PERHITUNGAN U95%!AJ3` dari V4/AC4), resolusi (`INPUT DATA!E16`)
     * dan pembagi larutan 1 (`PERHITUNGAN U95%!Q9`).
     */
    public function test_konstanta_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();

        foreach ($w['titik'] as $t) {
            $std = $this->standarSeeder(self::STANDAR[$t['nama']]);
            $this->assertSamaExcel($t['u95_standar_database'], (float) $std->ketidakpastian, "{$t['nama']}: U95 larutan standar");

            $cmc = CalibrationCapability::where('equipment_category_id', $this->kategoriAnalitik->id)
                ->where('nama_alat', 'Refractometer')
                ->where('satuan', 'n20D')
                ->whereBetween('range_min', [(float) $t['masukan']['titik_ukur']['nilai'] - 0.0005, (float) $t['masukan']['titik_ukur']['nilai'] + 0.0005])
                ->firstOrFail();

            $this->assertSamaExcel($t['cmc_database'], (float) $cmc->ketidakpastian_terbaik, "{$t['nama']}: CMC");
            $this->assertSamaExcel($w['konstanta']['u_temperature'], (float) $cmc->u_temperature, "{$t['nama']}: UTemperature", rel: 1e-7);
        }

        $this->assertSamaExcel($w['masukan']['resolusi_alat'], RefractometerProfile::RESOLUSI, 'resolusi alat (konstanta profil = isian INPUT DATA!E16)');
        $this->assertSame(1.0, self::nilai($w['konstanta']['pembagi_standar_refracto']), 'Excel membagi U larutan dengan 1 (bukan k=2); aplikasi menirunya (RefractometerBudgetTest)');
    }

    // ------------------------------------------------------------------ tabel konversi suhu

    /**
     * 113 baris `Tab Konversi Temperatur` (19,0-30,2 C). Aplikasi memakai model garis lurus
     * (`koefSuhu` x (T-20)), jadi tiap baris diadu: deviasi (H / E) dan nilai terkoreksi (G / D)
     * untuk kedua satuan.
     */
    public function test_tabel_konversi_suhu_113_baris_sama_dengan_model_linear(): void
    {
        $tabel = self::w()['tabel_konversi_suhu'];
        $profil = new RefractometerProfile;
        $n20d = $this->alat();
        $brix = $this->alatAnalitik('Refractometer', '°Brix', 0.1);

        $this->assertCount(113, $tabel['suhu_c'], 'jumlah baris tabel konversi berubah di workbook');

        foreach ($tabel['suhu_c'] as $i => $suhu) {
            $ke = "T={$suhu} C (baris ".($i + 4).')';

            $this->assertSamaExcel(['sel' => "{$tabel['rentang_sel']} H baris ".($i + 4), 'nilai' => $tabel['n20d_deviasi'][$i]], $profil->deviasiSuhu($suhu, 'n20D'), "{$ke}: deviasi n20D");
            $this->assertSamaExcel(['sel' => 'G baris '.($i + 4), 'nilai' => $tabel['n20d_terkoreksi'][$i]], $profil->rataRataPadaSuhuAcuan($tabel['n20d_teramati'][$i], $suhu, $n20d), "{$ke}: terkoreksi n20D");
            $this->assertSamaExcel(['sel' => 'E baris '.($i + 4), 'nilai' => $tabel['brix_deviasi'][$i]], $profil->deviasiSuhu($suhu, '°Brix'), "{$ke}: deviasi Brix");
            $this->assertSamaExcel(['sel' => 'D baris '.($i + 4), 'nilai' => $tabel['brix_terkoreksi'][$i]], $profil->rataRataPadaSuhuAcuan($tabel['brix_teramati'][$i], $suhu, $brix), "{$ke}: terkoreksi Brix");
        }
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar (nominal), suhu larutan rata-rata, STDEV, rata-rata UUT TERKOREKSI 20 C
     * (`PERHITUNGAN!J46/J47`) dan koreksi cetak (`SERTIFIKAT!O18/O19` = Std - Terkoreksi).
     */
    public function test_nilai_standar_rata_rata_terkoreksi_stdev_dan_koreksi_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $s = $t['standar'];
            $ke = $t['nama'];
            $pembacaan = $t['masukan']['pembacaan_sesudah']['nilai'];
            $suhu = $t['masukan']['suhu_sesudah_c']['nilai'];

            // Fixture konsisten dengan sel hitungannya sendiri (penjaga pembacaan fixture).
            $this->assertSamaExcel($s['rata_rata_teramati'], array_sum($pembacaan) / count($pembacaan), "{$ke}: rata-rata teramati (hitung dari 5 pembacaan)");
            $this->assertSamaExcel($s['suhu_larutan_rata_c'], array_sum($suhu) / count($suhu), "{$ke}: suhu larutan rata-rata");

            $this->assertSamaExcel($s['nilai_standar'], $h['titik_ukur'], "{$ke}: nilai standar nominal");
            $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], "{$ke}: STDEV sampel");
            $this->assertSamaExcel($s['rata_rata_terkoreksi_20c'], $h['rata_rata'], "{$ke}: rata-rata UUT terkoreksi ke 20 C (=H + (T-20)*0,00045)");
            $this->assertSamaExcel($t['cetak']['koreksi'], $h['koreksi'], "{$ke}: koreksi cetak (Standar - Terkoreksi)");
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /** Lima komponen (ui, ci, vi, uici) sel demi sel, pada suhu ruang sesi contoh (21,05 C). */
    public function test_lima_komponen_budget_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $komp = collect($h['type_b_components'])->keyBy('sumber');

            foreach (self::KOMPONEN as $nama) {
                $x = $t['komponen'][$nama];
                $b = $komp[$nama];
                $ke = "{$t['nama']} {$nama}";
                $suhuBergantung = $nama === 'ketidakpastian_temperature'; // satu-satunya yang memakai kolom decimal(20,8)
                $rel = $suhuBergantung ? self::TOL_SIMPAN_KOMPONEN : self::TOL_REL;

                $this->assertSamaExcel($x['vi'], (float) $b['vi'], "{$ke}: vi");
                $this->assertSamaExcel($x['ui'], (float) $b['u_baku'], "{$ke}: ui = u/pembagi", rel: $rel);
                $this->assertSamaExcel($x['ci'], (float) $b['ci'], "{$ke}: ci");
                $this->assertSamaExcel($x['uici'], (float) $b['nilai'], "{$ke}: ui*ci", rel: $rel);
            }
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /** uc, v_eff, k, U (sebelum lantai), CMC, dan U cetak: SAMA di kedua titik pada suhu ruang sesi contoh. */
    public function test_uc_veff_k_u_dan_lantai_cmc_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $a = $t['agregat'];
            $ke = $t['nama'];

            $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc", rel: self::TOL_SIMPAN_UC);
            $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff", rel: self::TOL_SIMPAN_VEFF);
            $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], "{$ke}: k (TINV, df dipotong)");
            $this->assertSamaExcel($a['u_hitung'], $h['ketidakpastian_gabungan'] * $h['faktor_cakupan_k'], "{$ke}: U = k*uc sebelum lantai", rel: self::TOL_SIMPAN_UC);
            $this->assertSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U sesudah lantai CMC (=MAX(U; CMC))", rel: self::TOL_SIMPAN_UC);
            // Lantai CMC tidak aktif di sampel (U hitung > CMC): yang dilaporkan U hitung.
            $this->assertGreaterThan(self::nilai($a['cmc']), self::nilai($a['u_hitung']), "{$ke}: sampel ini tidak menyentuh lantai CMC");
        }
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik (Standard Value, UUT, Correction, U95) dan `k` — teks sama persis dengan
     * `SERTIFIKAT!C18:R19`, pada pemformat produksi `Angka` dengan desimal yang ditetapkan profil
     * (5 desimal: `RefractometerProfile::desimalSertifikat()`; format sel `0.00000`).
     */
    public function test_angka_cetak_sama_dengan_sertifikat_excel(): void
    {
        $profil = new RefractometerProfile;
        $desimal = $profil->desimalSertifikat();

        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $c = $t['cetak'];
            $ke = $t['nama'];

            $this->assertSame((string) $desimal, (string) strlen(substr(strrchr($c['standar']['format'], '.'), 1)), "{$ke}: desimal profil = desimal format sel {$c['standar']['sel']}");
            $this->assertFalse($c['koreksi']['nol_bertanda_minus'], "{$ke}: koreksi tidak membulat ke nol di sampel ini");

            $standar = $profil->nolBelakangStandarDibuang() ? Angka::nilaiStandar($h['titik_ukur'], $desimal) : Angka::hasil($h['titik_ukur'], $desimal);
            // Excel mencetak nol di belakang (`0.00000` -> 1.33659); aplikasi membuangnya hanya bila ada nol belakang.
            $this->assertSame(rtrim(rtrim($c['standar']['teks'], '0'), '.'), self::keTitik($standar), "{$ke}: Standard Value vs {$c['standar']['sel']} ({$c['standar']['nilai']})");
            $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal)), "{$ke}: UUT vs {$c['uut']['sel']}");
            $this->assertSame($c['koreksi']['teks'], self::keTitik(Angka::hasil($h['koreksi'], $desimal)), "{$ke}: Correction vs {$c['koreksi']['sel']}");
            $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal)), "{$ke}: U95 vs {$c['u95']['sel']}");
        }

        // `k` tercetak SATU angka (`SERTIFIKAT!U21` = k titik 1, format `0`).
        $k = self::w()['cetak_k'];
        $this->assertSame($k['teks'], self::keTitik(Angka::hasil($this->hitung(0)['faktor_cakupan_k'], 0)), "k tercetak vs {$k['sel']} ({$k['nilai']})");
    }

    /** Tanggal terbit: `SERTIFIKAT!B37 = S9+4`; aplikasi `FormatMasterSertifikat` +4. */
    public function test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat(): void
    {
        $rumus = self::w()['tanggal_terbit']['rumus'];
        $this->assertSame(1, preg_match('/^=S9\+(\d+)$/', $rumus, $m), "rumus {$rumus} tidak dikenal");
        $this->assertSame((int) $m[1], FormatMasterSertifikat::tanggalUntukProfil('refractometer')['tambah_hari']);
    }

    // ------------------------------------------------------------------ konflik

    /**
     * SELISIH P-20 — deviasi suhu ("Ketidakpastian Pengaruh Perbedaan Temperature").
     *
     * Excel (`PERHITUNGAN U95%!N11/N26`) menunjuk SEL TETAP `Tab Konversi Temperatur!H24` (baris T = 21,0 C);
     * suhu ruang sesi tidak dibaca. Aplikasi (bawaan `suhu_ruang`) mencari baris menurut suhu ruang sesi.
     * Sama hanya bila suhu ruang rata-rata jatuh di 21,0-21,1 C (sesi contoh: 21,05). Di 23,0 C aplikasi
     * mengambil baris 23,0 (-0,00135) sementara Excel tetap -0,00045, dan U95 menjadi ~3x lebih besar.
     * Tes mengunci: (a) sama di sesi contoh; (b) aplikasi = rumus sel dengan baris suhu ruang
     * (penyebab tunggal); (c) beda dari cache Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p20_deviasi_suhu_sel_tetap_lawan_suhu_ruang(): void
    {
        $w = self::w();
        $tetap = $w['konstanta']['deviasi_suhu_sel_tetap'];
        $profil = new RefractometerProfile;

        // (a) Sesi contoh (21,05 C): aplikasi = N11 = 'Tab Konversi Temperatur'!H24.
        $this->assertSamaExcel($tetap, $profil->deviasiSuhu(self::suhuRuang(), 'n20D'), 'deviasi pada suhu ruang sesi contoh (21,05 C -> baris 21,0)');
        $this->assertSamaExcel($tetap['baris_tetap_deviasi'], $profil->deviasiSuhu(self::nilai($tetap['baris_tetap_suhu_c']), 'n20D'), 'sel tetap H24 = deviasi pada T = B24');

        foreach ($w['titik'] as $i => $t) {
            $s = $t['dengan_suhu_ruang_23c'];
            $ke = "{$t['nama']} pada suhu ruang 23,0 C";
            $h = $this->hitung($i, $s['suhu_ruang_c']);

            // (b) penyebab tunggal: aplikasi = rumus sel dengan deviasi baris 23,0.
            $this->assertSamaExcel($s['deviasi_tabel'], $profil->deviasiSuhu($s['suhu_ruang_c'], 'n20D'), "{$ke}: deviasi aplikasi = {$s['deviasi_tabel']['sel']}");
            $this->assertSamaHitungUlang($s['uc'], $h['ketidakpastian_gabungan'], "{$ke}: uc (rumus sel dengan deviasi baris suhu ruang)", self::TOL_SIMPAN_UC);
            $this->assertSamaHitungUlang($s['veff'], (float) $h['derajat_kebebasan_efektif'], "{$ke}: v_eff", self::TOL_SIMPAN_VEFF);
            $this->assertSamaHitungUlang($s['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U cetak", self::TOL_SIMPAN_UC);

            // (c) berbeda dari yang Excel simpan (Excel tidak peduli suhu ruang).
            $this->assertGreaterThan(2 * self::nilai($t['agregat']['uc']), $h['ketidakpastian_gabungan'], "{$ke}: uc aplikasi > 2x {$t['agregat']['uc']['sel']} (SELISIH P-20)");
            $this->assertGreaterThan(2 * self::nilai($t['agregat']['u_cetak']), $h['ketidakpastian_diperluas'], "{$ke}: U cetak aplikasi > 2x {$t['agregat']['u_cetak']['sel']} (SELISIH P-20)");
        }
    }

    /**
     * Blok KONDISI LINGKUNGAN (`PERHITUNGAN!G14:Q15`, thermohygro TH-5) — suhu SAMA di semua tahap;
     * kelembaban: rata-rata, indexed value, koreksi, delta SAMA, tetapi U95 std thermohygro BEDA = SELISIH P-21.
     *
     * Excel mengambil U95 TH-5 per UNIT (`VLOOKUP($E$16, Termohygro_Lingkungan, 9, 0)` -> 4,9);
     * aplikasi mengambil per TITIK RH (titik terdekat -> 4,8), seperti master Turbidimeter dan Chlorine.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_kondisi_lingkungan_th5_suhu_sama_dan_u95_kelembaban_selisih_p21(): void
    {
        $k = self::w()['kondisi_lingkungan'];
        $th = $k['th']['nilai'];
        $this->assertSame('TH-5', $th);

        $suhu = $this->kondisiLingkungan($th, 'suhu', self::nilai($k['suhu']['masukan_diketik']['awal']), self::nilai($k['suhu']['masukan_diketik']['akhir']));
        $this->assertSamaExcel($k['suhu']['average'], $suhu['average'], 'suhu: rata-rata awal/akhir');
        $this->assertSamaExcel($k['suhu']['indexed_value'], $suhu['indexed_value'], 'suhu: indexed value (titik koreksi thermohygro)');
        $this->assertSamaExcel($k['suhu']['koreksi'], $suhu['correction'], 'suhu: koreksi sertifikat TH');
        $this->assertSamaExcel($k['suhu']['delta'], $suhu['delta'], 'suhu: delta |akhir-awal|');
        $this->assertSamaExcel($k['suhu']['u95_std_th'], $suhu['u95_std_th'], 'suhu: U95 std TH');
        $this->assertSamaExcel($k['suhu']['u95_sertifikat'], $suhu['u95_sertifikat'], 'suhu: U95 sertifikat = sqrt(U95 TH^2 + delta^2)');
        $this->assertSamaExcel($k['suhu']['nilai_cetak'], $suhu['nilai_terkoreksi'], 'suhu: nilai tercetak (rata-rata + koreksi)');

        $rh = $this->kondisiLingkungan($th, 'kelembaban', self::nilai($k['kelembaban']['masukan_diketik']['awal']), self::nilai($k['kelembaban']['masukan_diketik']['akhir']));
        $this->assertSamaExcel($k['kelembaban']['average'], $rh['average'], 'RH: rata-rata');
        $this->assertSamaExcel($k['kelembaban']['indexed_value'], $rh['indexed_value'], 'RH: indexed value');
        $this->assertSamaExcel($k['kelembaban']['koreksi'], $rh['correction'], 'RH: koreksi sertifikat TH');
        $this->assertSamaExcel($k['kelembaban']['delta'], $rh['delta'], 'RH: delta');
        $this->assertSamaExcel($k['kelembaban']['nilai_cetak'], $rh['nilai_terkoreksi'], 'RH: nilai tercetak');

        // SELISIH P-21: penyebab = rumus sel (kolom 9 per unit lawan per titik).
        $this->assertStringContainsString('VLOOKUP($E$16,Termohygro_Lingkungan,9,0)', $k['rumus_u95_th_rh']['rumus'], 'Excel Refractometer: U95 TH per UNIT');
        $this->assertNotEqualsWithDelta(self::nilai($k['kelembaban']['u95_std_th']), $rh['u95_std_th'], 1e-9, "RH: U95 std TH aplikasi harus beda dari {$k['kelembaban']['u95_std_th']['sel']} (SELISIH P-21)");
        $this->assertEqualsWithDelta(4.8, $rh['u95_std_th'], 1e-9, 'aplikasi: U95 TH-5 pada titik ~60 %RH = 4,8 (thermohygro-lab.json)');
        $this->assertNotEqualsWithDelta(self::nilai($k['kelembaban']['u95_sertifikat']), $rh['u95_sertifikat'], 1e-3, 'RH: U95 sertifikat 5,2 (aplikasi) lawan 5,29 (Excel)');
        $this->assertSame('5,2', Angka::id($rh['u95_sertifikat'], 1), 'tercetak aplikasi `± 5,2`');
        $this->assertSame('5,3', Angka::id(self::nilai($k['kelembaban']['u95_sertifikat']), 1), 'tercetak Excel `± 5,3`');
    }

    /**
     * P-22 — satu termometer fisik, dua nilai di workbook yang SAMA: `PERHITUNGAN U95%!V4/AC4` = 0,07/0,036
     * (dipakai Excel dan aplikasi) lawan `DATABASE!V17/V18` = 0,72/0,06 (dipakai Turbidimeter dan Chlorine).
     * Aplikasi = Excel (SAMA), tetapi mengikuti sel yang kelihatannya basi (revisi 11 dan 13 di FORM VALIDASI).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p22_utemperature_mengikuti_sheet_u95_bukan_database(): void
    {
        $k = self::w()['konstanta'];
        $kemampuan = CalibrationCapability::where('nama_alat', 'Refractometer')->where('satuan', 'n20D')->firstOrFail();

        $dariSheetU95 = sqrt((self::nilai($k['u95_termometer_c']) / 2) ** 2 + (self::nilai($k['u95_sensor_c']) / 2) ** 2);
        $dariDatabase = sqrt((self::nilai($k['u95_termometer_database_c']) / 2) ** 2 + (self::nilai($k['u95_sensor_database_c']) / 2) ** 2);

        $this->assertSamaExcel($k['u_temperature'], $dariSheetU95, 'AJ3 = sqrt((V4/2)^2 + (AC4/2)^2)');
        $this->assertSamaExcel($k['u_temperature'], (float) $kemampuan->u_temperature, 'UTemperature aplikasi = AJ3', rel: 1e-7);
        $this->assertGreaterThan(5 * (float) $kemampuan->u_temperature, $dariDatabase, 'DATABASE!V17/V18 (0,72/0,06) memberi UTemperature ~9x lebih besar: SELISIH internal workbook (P-22)');
    }
}
