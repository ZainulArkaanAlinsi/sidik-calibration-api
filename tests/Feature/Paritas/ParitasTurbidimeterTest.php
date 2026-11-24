<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Support\Angka;
use App\Support\FormatMasterSertifikat;
use Database\Seeders\TurbidimeterCapabilitySeeder;
use Database\Seeders\TurbidimeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\Concerns\MenyiapkanAlatAnalitik;
use Tests\TestCase;

/**
 * PARITAS Turbidimeter: aplikasi lawan `Master Olah Data_Turbidimeter.xlsm` (tiga titik: 1, 100,
 * 1000 NTU), tahap demi tahap. Fixture `tests/Fixtures/paritas/turbidimeter.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-analitik-batch2.py` dari nilai-cache workbook.
 *
 * Titik panggil terkecil tanpa HTTP: `GumCalculator::hitungTitik()`. Konstanta aplikasi dibaca
 * dari SEEDER produksi (`TurbidimeterCapabilitySeeder`, `TurbidimeterSeeder`).
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-analitik-batch2.md`):
 *  P-23 RH awal: Excel membaca RH AKHIR untuk awal dan akhir; aplikasi memakai keduanya;
 *  P-24 tanggal terbit: +1 (`SERTIFIKAT!B40`) atau +4 (`INPUT DATA!D55`) atau tanggal sistem (aplikasi);
 *  P-25 resolusi per titik: Excel memakai yang DIKETIK, aplikasi yang LEBIH BESAR dengan master;
 *  P-26 tanda minus pada koreksi yang membulat ke nol (`-0,00`).
 */
#[Group('paritas')]
class ParitasTurbidimeterTest extends TestCase
{
    use MenegakkanParitas;
    use MenyiapkanAlatAnalitik;
    use RefreshDatabase;

    private const STANDAR = [
        '1 NTU' => 'Turbidity Standard 1 NTU',
        '100 NTU' => 'Turbidity Standard 100 NTU',
        '1000 NTU' => 'Turbidity Standard 1000 NTU',
    ];

    private const KOMPONEN = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'pengulangan_pembacaan'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAnalitik([TurbidimeterCapabilitySeeder::class, TurbidimeterSeeder::class], thermohygro: true);
    }

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('turbidimeter')['workbook'];
    }

    private function alat(): Equipment
    {
        // Resolusi tunggal hanya cadangan; budget memakai resolusi per titik dari profil (0,01 / 0,1 / 1).
        return $this->alatAnalitik('Turbidimeter', 'NTU', self::nilai(self::w()['masukan']['resolusi_titik'][0]));
    }

    /** @return array<string, mixed> */
    private function hitung(int $indeks, array $konteks = []): array
    {
        $t = self::w()['titik'][$indeks];

        return $this->hitungTitikAnalitik($indeks + 1, $t, $this->alat(), $this->standarSeeder(self::STANDAR[$t['nama']]), null, $konteks);
    }

    // ------------------------------------------------------------------ konstanta

    /**
     * Konstanta aplikasi (seeder produksi) = sel workbook: U95 larutan (`DATABASE!W13:W15`),
     * CMC (`DATABASE!T5:T7`), UTemperature (`PERHITUNGAN U95%!AE4` dari `DATABASE!W18/W19`),
     * resolusi per titik (`INPUT DATA!E17:G17`) dan pembagi komponen suhu `rect.` = akar 3 (`P13`).
     */
    public function test_konstanta_aplikasi_sama_dengan_workbook(): void
    {
        $w = self::w();
        $profil = new TurbidimeterProfile;

        foreach ($w['titik'] as $i => $t) {
            $titik = $t['masukan']['titik_ukur'];
            $std = $this->standarSeeder(self::STANDAR[$t['nama']]);
            $this->assertSamaExcel($t['u95_standar_database'], (float) $std->ketidakpastian, "{$t['nama']}: U95 larutan standar");
            $this->assertSame(2.0, (float) $std->faktor_cakupan, "{$t['nama']}: k larutan = 2 (pembagi Excel P11/P25/P39)");

            $cmc = CalibrationCapability::where('equipment_category_id', $this->kategoriAnalitik->id)
                ->where('nama_alat', 'Turbidimeter')
                ->where('range_min', $titik['nilai'])
                ->firstOrFail();

            $this->assertSamaExcel($t['cmc_database'], (float) $cmc->ketidakpastian_terbaik, "{$t['nama']}: CMC");
            $this->assertSamaExcel($w['konstanta']['u_temperature'], (float) $cmc->u_temperature, "{$t['nama']}: UTemperature", rel: 1e-7);
            $this->assertSamaExcel($t['masukan']['resolusi_titik'], $profil->resolusiTitik($titik['nilai']), "{$t['nama']}: resolusi titik (INPUT DATA!E17:G17)");
        }

        $this->assertSamaExcel($w['konstanta']['pembagi_komponen_suhu'], sqrt(3), 'pembagi komponen suhu (rect. = akar 3)');
        $this->assertSamaExcel($w['konstanta']['u_temperature'], sqrt((self::nilai($w['konstanta']['u95_termometer_c']) / 2) ** 2 + (self::nilai($w['konstanta']['u95_sensor_c']) / 2) ** 2), 'AE4 = sqrt((W18/2)^2 + (W19/2)^2)');
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Nilai standar, rata-rata UUT sesudah adjustment, STDEV sampel, dan koreksi (cetak: Std - UUT =
     * `SERTIFIKAT!P22:P24`; lembar: UUT - Std = `PERHITUNGAN!G43/I43/K43`).
     */
    public function test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $s = $t['standar'];
            $ke = $t['nama'];

            $this->assertSamaExcel($s['nilai_standar'], $h['titik_ukur'], "{$ke}: nilai standar nominal");
            $this->assertSamaExcel($s['rata_rata_uut'], $h['rata_rata'], "{$ke}: rata-rata UUT sesudah adjustment");
            $this->assertSamaExcel($s['stdev_sampel'], $h['standar_deviasi'], "{$ke}: STDEV sampel");
            $this->assertSamaExcel($t['cetak']['koreksi'], $h['koreksi'], "{$ke}: koreksi cetak (Std - UUT)");
            $this->assertSamaExcel($s['koreksi_lembar_uut_minus_standar'], -$h['koreksi'], "{$ke}: koreksi lembar (UUT - Std) = -koreksi aplikasi");
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /** Empat komponen (ui, ci, vi, uici) sel demi sel di ketiga titik. */
    public function test_empat_komponen_budget_cocok_excel(): void
    {
        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $komp = collect($h['type_b_components'])->keyBy('sumber');

            foreach (self::KOMPONEN as $nama) {
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

    /** uc, v_eff, k, U (sebelum lantai), CMC, dan U cetak: SAMA di tiga titik. Lantai CMC menang di ketiganya. */
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
            $this->assertSamaExcel($a['u_cetak'], $h['ketidakpastian_diperluas'], "{$ke}: U sesudah lantai CMC");
            $this->assertSamaExcel($a['cmc'], $h['ketidakpastian_diperluas'], "{$ke}: U cetak = CMC (lantai menang di sampel)");
        }
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik dan `k`, teks sama persis dengan `SERTIFIKAT!C22:U27` pada pemformat produksi
     * `Angka`; desimal per titik = `TurbidimeterProfile::desimalTitik()` (2/1/0) = format sel Excel.
     * Koreksi yang membulat ke nol tetap bertanda minus (`-0,00`, `-0,0`): `tandaNolDicetak()` true
     * sama dengan emulasi Excel; bukti PDF master bertentangan = P-26 (MENUNGGU).
     */
    public function test_angka_cetak_sama_dengan_sertifikat_excel(): void
    {
        $profil = new TurbidimeterProfile;
        $minusNol = 0;

        foreach (self::w()['titik'] as $i => $t) {
            $h = $this->hitung($i);
            $c = $t['cetak'];
            $ke = $t['nama'];
            $desimal = $profil->desimalTitik($t['masukan']['titik_ukur']['nilai']);

            foreach (['uut', 'koreksi', 'u95'] as $kolom) {
                $this->assertSame((string) $desimal, (string) (str_contains($c[$kolom]['format'], '.') ? strlen(substr(strrchr($c[$kolom]['format'], '.'), 1)) : 0), "{$ke}: desimal profil = format sel {$c[$kolom]['sel']}");
            }
            $this->assertSame('0', $c['standar']['format'], "{$ke}: Standard Value berformat 0");

            $this->assertSame($c['standar']['teks'], self::keTitik(Angka::nilaiStandar($h['titik_ukur'], $desimal)), "{$ke}: Standard Value vs {$c['standar']['sel']}");
            $this->assertSame($c['uut']['teks'], self::keTitik(Angka::hasil($h['rata_rata'], $desimal)), "{$ke}: UUT vs {$c['uut']['sel']}");
            // Tanda minus pada nol: emulasi Excel = aplikasi (`tandaNolDicetak()` true). Lihat P-26.
            $this->assertSame($c['koreksi']['teks'], self::keTitik(Angka::hasil($h['koreksi'], $desimal, tandaNol: $profil->tandaNolDicetak())), "{$ke}: Correction vs {$c['koreksi']['sel']}");
            $this->assertSame($c['u95']['teks'], self::keTitik(Angka::hasil($h['ketidakpastian_diperluas'], $desimal)), "{$ke}: U95 vs {$c['u95']['sel']}");
            $minusNol += $c['koreksi']['nol_bertanda_minus'] ? 1 : 0;
        }

        // Dikunci agar perubahan emulasi/format ketahuan: 2 dari 3 baris koreksi membulat ke -0,00 dan -0,0.
        $this->assertSame(2, $minusNol, 'baris koreksi -0,00/-0,0: perbarui docs/paritas-analitik-batch2.md (P-26)');

        $k = self::w()['cetak_k'];
        $this->assertSame($k['teks'], self::keTitik(Angka::hasil($this->hitung(0)['faktor_cakupan_k'], 1)), "k tercetak vs {$k['sel']} ({$k['nilai']}): format 0.0 -> 2.0");
    }

    // ------------------------------------------------------------------ konflik

    /**
     * SELISIH P-24 — tanggal terbit. Workbook bertentangan dengan dirinya sendiri: `SERTIFIKAT!B40 = T9+1`
     * (tercetak) lawan `INPUT DATA!D55 = O16+4`. Aplikasi menahan (`tambah_hari` null: tanggal terbit sistem)
     * menunggu pemilik, jadi tidak sama dengan keduanya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p24_tanggal_terbit_ditahan_dan_workbook_bertentangan(): void
    {
        $t = self::w()['tanggal_terbit'];
        $this->assertSame(1, preg_match('/\+(\d+)$/', $t['sertifikat']['rumus'], $a));
        $this->assertSame(1, preg_match('/\+(\d+)$/', $t['input_data']['rumus'], $b));

        $this->assertSame(1, (int) $a[1], 'SERTIFIKAT!B40 = +1');
        $this->assertSame(4, (int) $b[1], 'INPUT DATA!D55 = +4');
        $this->assertNull(FormatMasterSertifikat::tanggalUntukProfil('turbidimeter')['tambah_hari'], 'aplikasi menahan tanggal terbit Turbidimeter (SELISIH P-24)');
    }

    /**
     * SELISIH P-25 — resolusi per titik. Excel: `PERHITUNGAN U95%!M12 = L9*0.5` dan `L9 = INPUT DATA!E17`
     * (angka DIKETIK, mis. 0,001 bila teknisi menulis lebih halus). Aplikasi (`resolusiDipakai`) memakai yang
     * LEBIH BESAR antara master dan ketikan ("aturan emas": perbaikan rumus tidak boleh mengecilkan U diam-diam).
     * Sama untuk sampel (ketikan = master), beda bila teknisi menulis resolusi lebih halus.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_p25_resolusi_aplikasi_lebih_besar_dari_ketikan_lebih_halus(): void
    {
        $profil = new TurbidimeterProfile;
        $alat = $this->alat();

        foreach (self::w()['titik'] as $i => $t) {
            $r = $t['rumus_resolusi'];
            $this->assertMatchesRegularExpression('/^=L\d+\*0\.5$/', $r['rumus_ui'], "{$t['nama']}: {$r['sel_ui']} memakai resolusi ketikan");
            $this->assertMatchesRegularExpression("/^='INPUT DATA'!\\w+17$/", $r['rumus_resolusi'], "{$t['nama']}: {$r['sel_resolusi']} = isian INPUT DATA");

            $titik = $t['masukan']['titik_ukur']['nilai'];
            $master = self::nilai($t['masukan']['resolusi_titik']);

            // Ketikan SAMA dengan master: SAMA dengan Excel.
            $this->assertSamaExcel($t['masukan']['resolusi_titik'], $profil->resolusiDipakai($titik, $alat, ['spesifikasi_alat' => ['resolusi_titik_'.($i + 1) => $master]]), "{$t['nama']}: resolusi dipakai (ketikan = master)");

            // Ketikan 10x lebih halus: Excel memakai ketikan, aplikasi tetap master (SELISIH sengaja).
            $halus = $master / 10;
            $dipakai = $profil->resolusiDipakai($titik, $alat, ['spesifikasi_alat' => ['resolusi_titik_'.($i + 1) => $halus]]);
            $this->assertSamaHitungUlang($master, $dipakai, "{$t['nama']}: aplikasi tetap memakai resolusi master walau ditulis {$halus}");
            $this->assertGreaterThan($halus * 5, $dipakai, "{$t['nama']}: Excel (M = {$halus}*0,5) memberi u resolusi 10x lebih kecil (SELISIH P-25)");
        }
    }

    /**
     * Blok KONDISI LINGKUNGAN (`PERHITUNGAN!G14:Q15`, thermohygro TH-6). Suhu SAMA. Kelembaban:
     *  (a) dengan RH yang Excel PAKAI (`PERHITUNGAN!E15 = INPUT DATA!$F$23` -> awal = akhir = 55) aplikasi SAMA;
     *  (b) dengan RH yang DIKETIK di `INPUT DATA!E23/F23` (2 dan 55) aplikasi memakai KEDUANYA (rata-rata 28,5,
     *      delta 53) sedangkan Excel mengabaikan awal (55, delta 0) = SELISIH P-23.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_kondisi_lingkungan_th6_suhu_sama_dan_selisih_p23_rh_awal(): void
    {
        $k = self::w()['kondisi_lingkungan'];
        $th = $k['th']['nilai'];
        $this->assertSame('TH-6', $th);

        $suhu = $this->kondisiLingkungan($th, 'suhu', self::nilai($k['suhu']['masukan_diketik']['awal']), self::nilai($k['suhu']['masukan_diketik']['akhir']));
        foreach ([['average', 'average'], ['indexed_value', 'indexed_value'], ['koreksi', 'correction'], ['delta', 'delta'], ['u95_std_th', 'u95_std_th'], ['u95_sertifikat', 'u95_sertifikat']] as [$kunciExcel, $kunciApp]) {
            $this->assertSamaExcel($k['suhu'][$kunciExcel], $suhu[$kunciApp], "suhu: {$kunciExcel}");
        }
        $this->assertSamaExcel($k['suhu']['nilai_cetak'], $suhu['nilai_terkoreksi'], 'suhu: nilai tercetak');

        $rh = $k['kelembaban'];
        // (a) RH yang Excel pakai: awal = akhir = PERHITUNGAN!E15/F15.
        $a = $this->kondisiLingkungan($th, 'kelembaban', self::nilai($rh['excel_awal']), self::nilai($rh['excel_akhir']));
        foreach ([['average', 'average'], ['indexed_value', 'indexed_value'], ['koreksi', 'correction'], ['delta', 'delta'], ['u95_std_th', 'u95_std_th'], ['u95_sertifikat', 'u95_sertifikat']] as [$kunciExcel, $kunciApp]) {
            $this->assertSamaExcel($rh[$kunciExcel], $a[$kunciApp], "RH (awal=akhir seperti Excel): {$kunciExcel}");
        }
        $this->assertSamaExcel($rh['nilai_cetak'], $a['nilai_terkoreksi'], 'RH: nilai tercetak');

        // (b) RH yang diketik: awal 2, akhir 55.
        $this->assertStringContainsString('$F$23', $k['rumus_awal_rh']['rumus'], 'Excel membaca RH akhir (F23) untuk awal');
        $b = $this->kondisiLingkungan($th, 'kelembaban', self::nilai($rh['masukan_diketik']['awal']), self::nilai($rh['masukan_diketik']['akhir']));
        $this->assertSamaHitungUlang((self::nilai($rh['masukan_diketik']['awal']) + self::nilai($rh['masukan_diketik']['akhir'])) / 2, $b['average'], 'RH diketik: rata-rata aplikasi = (awal+akhir)/2');
        $this->assertGreaterThan(10, abs(self::nilai($rh['average']) - $b['average']), "RH diketik: rata-rata aplikasi jauh dari {$rh['average']['sel']} (SELISIH P-23)");
        $this->assertGreaterThan(10, $b['delta'], "RH diketik: delta aplikasi besar, Excel {$rh['delta']['sel']} = 0 (SELISIH P-23)");
    }

    /** P-29 — Excel tidak punya vonis/toleransi; aplikasi menahan hitung sampai `toleransi` alat diisi. */
    #[Group('menunggu-keputusan-tm')]
    public function test_p29_profil_menuntut_toleransi_walau_excel_tidak_punya(): void
    {
        $this->assertTrue((new TurbidimeterProfile)->punyaToleransi(), 'aplikasi menuntut toleransi (P-29); workbook tidak punya satu pun sel PASS/FAIL/toleransi');
    }
}
