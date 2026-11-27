<?php

namespace Tests\Feature\Paritas;

use App\Services\Calibration\Profiles\GayaProfile;
use App\Services\Calibration\Profiles\LoadCellProfile;
use App\Services\Calibration\TabelStandarGaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitasGaya;
use Tests\TestCase;

/**
 * PARITAS Load Cell: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Fixture `tests/Fixtures/paritas/load-cell.json` dibangun
 * `docs/skrip/gen-fixture-paritas-batch8.py` dari nilai-cache workbook
 * `Master Olah Data_Gaya_Load Cell.xlsm` (sel + nilai, tidak disunting tangan; hash
 * berkas dan catatan pembukaan ada di fixture, bagian `workbook.sumber`). Bagian yang
 * sama dengan UTM ada di [MenegakkanParitasGaya]; kelas ini memegang yang khas Load
 * Cell: kolom Y yang dicetak, selisih drift, CMC `cek range`, urutan beban, serta
 * butir yang menunggu keputusan TM.
 *
 * Sesi contoh di fixture: 10 titik (0, 100, lalu 2 ... 9 kN), tarik (Pull), standar
 * Load Cell 100 kN, satuan kN, 12 bacaan per titik, suhu ruang 27,7 °C.
 *
 * ## SAMA
 *
 * Konversi, rata-rata, STDEV, RSD, koreksi standar (W), Y, Z, AA, RRPE; tujuh dari
 * delapan komponen budget; (ui ci)^2, uc, veff, k, U% dan U kN bila drift master
 * dipasang; kolom sertifikat E/L/Q (Load Cell mencetak Y, bukan Z); pita CMC
 * `DATABASE!S5:S8`; teks cetak U (`SERTIFIKAT!P35`) dan k (`T36`).
 *
 * ## SELISIH (diberi nomor B8-n; tes sendiri, `#[Group('menunggu-keputusan-tm')]`)
 *
 *  - B8-2  drift standar: master `N13 = VLOOKUP(Z3; Tabel_Drift; 2; 0)` membaca kolom
 *          TEKAN walau bebannya Tarik; aplikasi memakai kolom Tarik. Load Cell 100 kN:
 *          master 0,031834..., aplikasi 0,034272.... Pertanyaan lab PK5-1 / G2. Efeknya:
 *          uc 0,14254 lawan 0,14311, veff 20,95 lawan 21,28, k 2,0860 lawan 2,0796, U
 *          0,297342 kN lawan 0,297610 kN. Angka CETAK sama (`0.00` -> 0,30; k `0` -> 2).
 *          (Pada UTM selisih drift yang sama bernomor B8-1.)
 *
 * ## MENUNGGU (diberi nomor M-n; belum diputuskan)
 *
 *  - M-4   CMC `cek range`: rumus master `PERHITUNGAN U95%!AC22` tidak punya cabang Pull
 *          untuk rentang 100 kN (hanya Pull <= 5 kN dan Pull 5-88 kN di UTM; di Load Cell
 *          hanya Pull <= 5 kN), sehingga AC22 = "cek range", AH22 dan AC23 = `#VALUE!`,
 *          dan U cetak (`P35` membaca `AH23 = MAX(AH21; AC22)`) jatuh ke U hitung TANPA
 *          lantai CMC. Aplikasi memilih satu-satunya pita Tarik lampiran (0-500 kgf =
 *          0,020601 kN) yang tidak mengikat, jadi U cetak sama selain B8-2. Apakah master
 *          sengaja tanpa lantai di rentang ini, atau lampiran kurang pita Tarik 10-88 kN,
 *          pertanyaan lab G9.
 *  - M-1   sumber zero error (lihat [MenegakkanParitasGaya]); pertanyaan lab G8.
 *  - M-2   kolom V set point ketikan. Pada Load Cell 100 kN tabel standar tidak punya
 *          set point di 0-9 kN dan di atas 88,26 kN (G10); master mengetik 9,80665 dan
 *          aplikasi memilih 9,80665 sendiri dengan `di_luar_rentang_tabel`.
 *  - M-5   urutan beban 0, 100, 2 ... 9 kN (tidak naik). Aplikasi menghitung apa adanya
 *          dan memperingatkan di `temuan_sesi`; benar-tidaknya urutan itu data contoh
 *          (G13), bukan rumus.
 *
 * ## Batas bukti
 *
 * Satu sesi contoh (10 titik, standar 100 kN, satu arah). Standar 5 kN dan 3000 kN, arah
 * Tekan, dan satuan lain tidak dibuktikan di sini. Resolusi standar sesi contoh seeder
 * (0,001) beda dari `K6` master (0,000981); tes ini memakai nilai master (efeknya pada
 * U kecil sekali: komponen daya baca standar). Kesetaraan jalur hitung ulang disimpulkan
 * dari satu fungsi `hitungPerGrup`, bukan dari tes `raw_measurements` baru.
 */
#[Group('paritas')]
class ParitasLoadCellTest extends TestCase
{
    use MenegakkanParitasGaya;
    use RefreshDatabase;

    protected static function namaFixture(): string
    {
        return 'load-cell';
    }

    protected function profil(): GayaProfile
    {
        return new LoadCellProfile;
    }

    // ------------------------------------------------------------------ B8-2 drift

    /**
     * B8-2: drift. Master membaca kolom Tekan untuk beban Tarik, aplikasi membaca kolom Tarik.
     * Kedua sisi dikunci apa adanya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_2_selisih_drift_master_membaca_kolom_tekan_aplikasi_kolom_tarik(): void
    {
        $drift = self::wb()['excel']['budget']['drift'];
        $master = self::nilai($drift['N13_drift']);

        $this->assertSame('=VLOOKUP(Z3,Tabel_Drift,2,0)', $drift['N13_rumus']);
        $this->assertSame('Pull', self::wb()['masukan']['tipe_beban_Z22']['nilai']);

        $aplikasi = TabelStandarGaya::drift('100kN', 'Pull', 'load_cell');
        $tekan = TabelStandarGaya::drift('100kN', 'Push', 'load_cell');

        $this->assertSamaHitungUlang(0.031834452342817454, $master, 'master N13 (nilai cache)');
        $this->assertSamaHitungUlang(0.034272153479393656, (float) $aplikasi, 'aplikasi: drift 100 kN tarik workbook Load Cell');
        $this->assertSamaHitungUlang($master, (float) $tekan, 'master N13 = kolom TEKAN tabel aplikasi');
        $this->assertGreaterThan(0.0, abs($master - (float) $aplikasi), 'ini selisih nyata: master membaca kolom yang berbeda');

        $komponen = array_column($this->jejak()['komponen'], null, 'sumber');
        $this->assertSamaHitungUlang(0.034272153479393656, (float) $komponen['drift_standar']['u'], 'budget aplikasi memakai drift Tarik');
    }

    /**
     * Akibat B8-2 di agregat: nilai aplikasi dikunci dari hitung ulang independen (Python,
     * rumus GUM yang sama); nilai master dari sel cache. Selisih ini seluruhnya berasal
     * dari drift — `test_penutup_drift_master_dipasang...` membuktikan itu.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_2_akibat_pada_budget_uc_veff_k_dan_u_kedua_sisi(): void
    {
        $ag = self::wb()['excel']['budget']['agregat'];
        $h = $this->hitung()[0];
        $budget = $h['type_b_components']['gaya_budget'];

        // Master (sel cache).
        $this->assertSamaHitungUlang(0.1425442857024415, self::nilai($ag['uc_AC18']), 'master uc');
        $this->assertSamaHitungUlang(20.94988748813726, self::nilai($ag['veff_AC19']), 'master veff');
        $this->assertSamaHitungUlang(2.085963447265865, self::nilai($ag['k_AC20']), 'master k');
        $this->assertSamaHitungUlang(0.2973421695919152, self::nilai($ag['U_persen_AC21']), 'master U %');
        $this->assertSamaHitungUlang(0.2973421695919152, self::nilai($ag['U_kn_AH21']), 'master U kN (rentang 100 kN, jadi sama dengan U %)');

        // Aplikasi (hitung ulang independen dengan drift Tarik).
        $this->assertSamaHitungUlang(0.1431084257986484, (float) $h['ketidakpastian_gabungan'], 'aplikasi uc');
        $this->assertSamaHitungUlang(21.275897283218885, (float) $h['derajat_kebebasan_efektif'], 'aplikasi veff');
        $this->assertSamaHitungUlang(2.079613844727662, (float) $h['faktor_cakupan_k'], 'aplikasi k');
        $this->assertSamaHitungUlang(0.2976102635880506, (float) $budget['u_persen'], 'aplikasi U %');
        $this->assertSamaHitungUlang(0.2976102635880506, (float) $budget['u_kn'], 'aplikasi U kN');
        $this->assertSamaHitungUlang(0.2976102635880506, (float) $h['ketidakpastian_diperluas'], 'aplikasi U tercetak kN (tanpa lantai CMC)');

        // Arah selisihnya: aplikasi lebih besar di uc, U, dan k lebih kecil (veff lebih besar).
        $this->assertGreaterThan(self::nilai($ag['uc_AC18']), (float) $h['ketidakpastian_gabungan']);
        $this->assertGreaterThan(self::nilai($ag['U_kn_AH21']), (float) $h['ketidakpastian_diperluas']);
        $this->assertLessThan(self::nilai($ag['k_AC20']), (float) $h['faktor_cakupan_k']);
    }

    /**
     * ANGKA CETAK U: nilai kN aplikasi lawan `SERTIFIKAT!P35`. Nilainya BEDA (B8-2, 0,2976
     * lawan 0,2973); teksnya SAMA ("0.30") — itu yang diadu di [MenegakkanParitasGaya].
     * Di sini kedua nilai dikunci dan format kolom dibanding sel master.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_2_u_tercetak_beda_nilai_tapi_sama_teks_dan_format_kolom_sama_dengan_sel_master(): void
    {
        $profil = $this->profil();
        $sesi = self::sesiCetak();
        $cetak = $profil->cetakDalamSatuanAlat($sesi);
        $cetakan = self::wb()['excel']['cetak'];

        $this->assertSamaHitungUlang(0.2973421695919152, self::nilai($cetakan['U_cetak_P35']), 'master SERTIFIKAT!P35');

        foreach ($this->hitung() as $h) {
            $uCetak = $cetak['ubah']((float) $h['ketidakpastian_diperluas'], $h['titik_ke']);

            $this->assertSamaHitungUlang(0.2976102635880506, $uCetak, 'aplikasi U cetak dalam kN, titik '.$h['titik_ke']);
            $this->assertGreaterThan(1e-4, abs($uCetak - self::nilai($cetakan['U_cetak_P35'])), 'selisih nyata terhadap P35');
        }

        $this->assertSame(
            ['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'sebaran' => '0.00', 'u95' => '0.00', 'k' => '0'],
            $profil->formatKolomSertifikat($sesi, 100.0, 'kN'),
            'format kolom sertifikat Load Cell = format sel SERTIFIKAT!E22:V22, P35, T36',
        );
        $this->assertStringContainsString('AH23', $cetakan['U_cetak_rumus_P35'], 'P35 membaca AH23 (MAX(AH21; AC22)) dalam kN');
    }

    // ------------------------------------------------------------------ yang khas Load Cell

    /**
     * Load Cell mencetak Y di kolom Standard Value (Z hanya dipakai di lembar perhitungan),
     * dan Correction = Y - B (= AA) — beda dengan UTM yang mencetak Z. Pertanyaan lab G12/G5.
     */
    public function test_load_cell_mencetak_y_dan_koreksi_sama_dengan_aa(): void
    {
        $this->assertFalse($this->profil()->pakaiKoreksiTermalDiSertifikat());

        foreach ($this->hitung() as $i => $h) {
            $ex = self::wb()['excel']['titik'][$i];
            $titik = $h['titik_ke'];

            $this->assertSamaExcel($ex['Y_terkoreksi_kn'], $h['rata_rata'] + $h['koreksi'], "titik {$titik} Standard Value kN = Y");
            $this->assertSamaExcel($ex['AA_correction_kn'], $h['koreksi'], "titik {$titik} Correction kN = AA");
            $this->assertSamaExcel($ex['sertifikat']['E_standar'], $h['rata_rata'] + $h['koreksi'], "titik {$titik} SERTIFIKAT E = Y");
        }
    }

    /**
     * M-4: CMC `cek range`. Master tidak punya pita Pull untuk rentang 100 kN, sehingga
     * AC22 = "cek range" dan AH22/AC23 = #VALUE!; `MAX` di AH23 mengabaikan teks, jadi U cetak
     * = U hitung (AH21) tanpa lantai. Aplikasi mengambil pita Tarik terdekat (0,020601 kN),
     * yang tidak mengikat.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_m_4_cmc_cek_range_di_master_aplikasi_memakai_pita_tarik_terdekat_yang_tidak_mengikat(): void
    {
        $cmc = self::wb()['excel']['cmc'];
        $ag = self::wb()['excel']['budget']['agregat'];

        $this->assertSame('cek range', $cmc['AC22_cmc_kn']['teks'], 'master: AC22 = cek range');
        $this->assertSame('#VALUE!', $cmc['AH22']['teks'], 'master: AH22 = #VALUE!');
        $this->assertSame('#VALUE!', $cmc['AC23']['teks'], 'master: AC23 = #VALUE!');
        $this->assertSamaExcel($cmc['AH23'], self::nilai($ag['U_kn_AH21']), 'master: AH23 = AH21 (MAX mengabaikan teks)');

        $budget = $this->jejak()['gaya_budget'];

        $this->assertSamaHitungUlang(0.020600999999999998, (float) $budget['cmc_kn'], 'aplikasi: CMC pita Tarik 0-500 kgf (kN)');
        $this->assertFalse($budget['lantai_cmc_menang'], 'aplikasi: CMC tidak mengikat, U hitung yang tercetak');
        $this->assertSamaHitungUlang((float) $budget['u_kn'], (float) $budget['u95_kn'], 'aplikasi: U95 = U hitung');
    }

    /**
     * M-5: urutan beban tidak naik (0, 100, 2 ... 9 kN). Aplikasi menghitung tiap titik
     * dari nominalnya sendiri, jadi angkanya tidak terpengaruh urutan; yang dilakukan hanya
     * memperingatkan lewat `temuan_sesi` (terbaca di jejak, diulang di tiap baris).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_m_5_urutan_beban_tidak_naik_diperingatkan_di_temuan_sesi(): void
    {
        $nominal = array_map(static fn (array $t): float => self::nilai($t['nominal']), self::wb()['masukan']['titik']);

        $this->assertSame([0.0, 100.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0], $nominal);

        foreach ($this->hitung() as $h) {
            $temuan = implode(' | ', $h['type_b_components']['temuan_sesi']);

            $this->assertStringContainsString('urutan bebannya tidak naik', $temuan, 'titik '.$h['titik_ke']);
        }
    }

    /**
     * M-2: tabel standar 100 kN tidak punya set point di 0-9 kN dan di atas 88,26 kN
     * (G10). Titik-titik itu ditandai `di_luar_rentang_tabel` dan memakai set point terdekat
     * (9,80665 untuk titik kecil, 88,25985 untuk 100 kN) — sama dengan ketikan teknisi di V.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_m_2_titik_di_luar_tabel_standar_ditandai_dan_set_point_terdekat_sama_dengan_ketikan(): void
    {
        foreach (self::wb()['excel']['titik'] as $i => $ex) {
            $hasil = self::hitungTitikLangsung($i);

            $this->assertTrue($hasil['di_luar_rentang_tabel'], 'titik '.$ex['titik_ke'].': di luar tabel standar 100 kN');
            $this->assertSamaExcel($ex['V_set_point_kn'], (float) $hasil['set_point_standar_kn'], 'titik '.$ex['titik_ke'].' set point terdekat');
        }
    }
}
