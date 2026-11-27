<?php

namespace Tests\Feature\Paritas;

use App\Services\Calibration\Profiles\GayaProfile;
use App\Services\Calibration\Profiles\UtmProfile;
use App\Services\Calibration\TabelStandarGaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitasGaya;
use Tests\TestCase;

/**
 * PARITAS Mesin UTM: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Fixture `tests/Fixtures/paritas/utm.json` dibangun
 * `docs/skrip/gen-fixture-paritas-batch8.py` dari nilai-cache workbook
 * `Master Olah Data_Gaya_UTM.xlsm` (sel + nilai, tidak disunting tangan; hash
 * berkas dan catatan pembukaan ada di fixture, bagian `workbook.sumber`). Pesan
 * galat tiap banding menyebut sel workbook, jadi tes merah menunjuk ke sel yang
 * menyimpang (lihat [MenegakkanParitas]). Bagian yang sama dengan Load Cell ada
 * di [MenegakkanParitasGaya]; kelas ini memegang yang khas UTM: selisih drift,
 * pita CMC, angka cetak U dan k, serta butir yang menunggu keputusan TM.
 *
 * Sesi contoh di fixture: 6 titik 0, 100 ... 500 kgf, tarik (Pull), standar Load
 * Cell 5 kN, 12 bacaan per titik, suhu ruang 24,5 / 24,3 °C. Satu pintu masuk
 * produksi yang diadu, yaitu `UtmProfile::hitungPerGrup()` (fungsi yang SAMA
 * dengan pratinjau, simpan, dan `HitungUlangSesi`), ditambah
 * `GayaCalculator::hitungTitik()` untuk konversi bacaan ke kN.
 *
 * ## SAMA
 *
 * Konversi kgf -> kN, rata-rata, STDEV, RSD, koreksi standar (W), Y, koreksi
 * termal (Z), AA, RRPE; tujuh dari delapan komponen budget (ui, ci, vi); jumlah
 * (ui ci)^2, uc, veff, k, U% bila drift master dipasang; kolom sertifikat E/L/Q;
 * pita CMC `DATABASE!S5:S8`; U cetak `SERTIFIKAT!P35` (dikunci lantai CMC) dan teks
 * k `T36`.
 *
 * ## SELISIH (diberi nomor B8-n; tes sendiri, `#[Group('menunggu-keputusan-tm')]`)
 *
 *  - B8-1  drift standar: master `PERHITUNGAN U95%!N13 = VLOOKUP(Z3; Tabel_Drift; 2; 0)`
 *          membaca kolom TEKAN `STANDAR_LOADCELL!Q5:R7` walau bebannya Tarik; aplikasi
 *          memakai kolom Tarik. Load Cell 5 kN: master 0,06928..., aplikasi 0,0. Ini
 *          pertanyaan lab PK5-1 / G2 (`docs/pertanyaan-lab-adu-cetak-b.md`). Efeknya
 *          pada budget: uc 0,20109 lawan 0,21269, veff 204,4 lawan 241,8, k 1,9717 lawan
 *          1,9699, U 0,3965 % lawan 0,4190 %. Angka CETAK tidak bergeser (lantai CMC
 *          0,020601 kN menang di kedua sisi, k tercetak "2" di kedua sisi).
 *
 * ## MENUNGGU (diberi nomor M-n; belum diputuskan)
 *
 *  - M-1   sumber zero error. Master memakai selisih berpasangan 12 bacaan di beban
 *          nol dibagi beban uji terbesar (`PERHITUNGAN FC!C44:P46`); aplikasi memakai
 *          nilai mutlak terbesar `preload_zero` dibagi kapasitas. Data contoh membuat
 *          keduanya 0, jadi data tidak membedakan. Pertanyaan lab G8.
 *  - M-2   kolom set point V di workbook ANGKA KETIKAN di semua titik (bukan rumus).
 *          Aplikasi memilih set point terdekat dari tabel standar; pada sesi ini hasilnya
 *          sama dengan ketikan, tapi itu bukan bukti rumus.
 *  - M-3   sel status berbasis `NOW()` (`INPUT DATA!Z23:Z27`, `DATABASE!Z13:Z15`,
 *          `FORM VALIDASI` status): nilai cache bergeser tiap workbook dihitung ulang,
 *          jadi tidak dipakai sebagai acuan.
 *
 * ## Batas bukti
 *
 * Satu sesi contoh (6 titik, satu standar, satu arah). Arah Tekan, standar 100 kN dan
 * 3000 kN, serta satuan lain tidak dibuktikan di sini. Kesetaraan jalur hitung ulang
 * (`HitungUlangSesi`) disimpulkan dari satu fungsi `hitungPerGrup`, bukan dari tes
 * `raw_measurements` baru; jalur sesi penuh dijaga `GayaSesiContohCocokMasterTest`.
 * Angka tercetak diadu lewat format kolom profil, bukan lewat PDF.
 */
#[Group('paritas')]
class ParitasUtmTest extends TestCase
{
    use MenegakkanParitasGaya;
    use RefreshDatabase;

    protected static function namaFixture(): string
    {
        return 'utm';
    }

    protected function profil(): GayaProfile
    {
        return new UtmProfile;
    }

    // ------------------------------------------------------------------ B8-1 drift

    /**
     * B8-1: drift. Kedua sisi dikunci apa adanya; tidak ada yang disetel agar cocok.
     * Master membaca kolom Tekan untuk beban Tarik, aplikasi membaca kolom Tarik.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_1_selisih_drift_master_membaca_kolom_tekan_aplikasi_kolom_tarik(): void
    {
        $master = self::nilai(self::wb()['excel']['budget']['drift']['N13_drift']);
        $this->assertTrue(self::wb()['excel']['budget']['drift']['N13_drift']['rumus'], 'N13 sel berumus, bukan ketikan');
        $this->assertSame('=VLOOKUP(Z3,Tabel_Drift,2,0)', self::wb()['excel']['budget']['drift']['N13_rumus']);

        $aplikasi = TabelStandarGaya::drift('5kN', 'Pull', 'utm');
        $tekan = TabelStandarGaya::drift('5kN', 'Push', 'utm');

        $this->assertSame(0.0, $aplikasi, 'aplikasi: drift 5 kN tarik workbook UTM');
        $this->assertSamaHitungUlang(0.0692820323027551, $master, 'master N13 (nilai cache)');
        $this->assertSamaHitungUlang($master, (float) $tekan, 'master N13 = kolom TEKAN tabel aplikasi');
        $this->assertGreaterThan(0.0, abs($master - $aplikasi), 'ini selisih nyata: master membaca kolom yang berbeda');

        $komponen = array_column($this->jejak()['komponen'], null, 'sumber');
        $this->assertSame(0.0, (float) $komponen['drift_standar']['u'], 'budget aplikasi memakai drift 0');
    }

    /**
     * Akibat B8-1 di agregat: nilai aplikasi dikunci dari hitung ulang independen (Python,
     * rumus GUM yang sama); nilai master dari sel cache. Selisih ini seluruhnya berasal
     * dari drift — `test_penutup_drift_master_dipasang...` membuktikan itu.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_1_akibat_pada_budget_uc_veff_k_dan_u_kedua_sisi(): void
    {
        $ag = self::wb()['excel']['budget']['agregat'];
        $h = $this->hitung()[0];
        $budget = $h['type_b_components']['gaya_budget'];

        // Master (sel cache).
        $this->assertSamaHitungUlang(0.21269280931915782, self::nilai($ag['uc_AC18']), 'master uc');
        $this->assertSamaHitungUlang(241.83321287301314, self::nilai($ag['veff_AC19']), 'master veff');
        $this->assertSamaHitungUlang(1.9698562125960952, self::nilai($ag['k_AC20']), 'master k');
        $this->assertSamaHitungUlang(0.4189742518118597, self::nilai($ag['U_persen_AC21']), 'master U %');
        $this->assertSamaHitungUlang(0.020550687051371717, self::nilai($ag['U_kn_AH21']), 'master U kN');

        // Aplikasi (hitung ulang independen dengan drift 0,0).
        $this->assertSamaHitungUlang(0.20109259343913097, (float) $h['ketidakpastian_gabungan'], 'aplikasi uc');
        $this->assertSamaHitungUlang(204.36458063636027, (float) $h['derajat_kebebasan_efektif'], 'aplikasi veff');
        $this->assertSamaHitungUlang(1.9716608894916783, (float) $h['faktor_cakupan_k'], 'aplikasi k');
        $this->assertSamaHitungUlang(0.3964864016503854, (float) $budget['u_persen'], 'aplikasi U %');
        $this->assertSamaHitungUlang(0.019447658000951402, (float) $budget['u_kn'], 'aplikasi U kN (sebelum lantai CMC)');

        // Arah selisihnya: aplikasi lebih kecil di uc, U%, dan U kN; veff aplikasi lebih kecil.
        $this->assertLessThan(self::nilai($ag['uc_AC18']), (float) $h['ketidakpastian_gabungan']);
        $this->assertLessThan(self::nilai($ag['U_persen_AC21']), (float) $budget['u_persen']);
        $this->assertLessThan(self::nilai($ag['U_kn_AH21']), (float) $budget['u_kn']);
    }

    // ------------------------------------------------------------------ CMC dan angka cetak

    /**
     * Pita yang dipilih aplikasi untuk sesi ini (Tarik, 500 kgf) sama dengan yang dipilih
     * rumus master `PERHITUNGAN U95%!AC22` (cabang Pull, K4 <= 5 -> `DATABASE!S8`), dan
     * nilainya MENGUNCI U di kedua sisi (lantai CMC menang).
     */
    public function test_cmc_sesi_ini_sama_dengan_ac22_dan_mengunci_u_di_kedua_sisi(): void
    {
        $cmc = self::wb()['excel']['cmc'];
        $budget = $this->jejak()['gaya_budget'];

        $this->assertSame('Pull', self::wb()['masukan']['tipe_beban_Z22']['nilai']);
        $this->assertStringContainsString('IF(AND(AA7="Pull",K4<=5),DATABASE!S8', str_replace("\n", '', $cmc['AC22_rumus']));

        $this->assertSamaExcel($cmc['AC22_cmc_kn'], (float) $budget['cmc_kn'], 'CMC kN (PERHITUNGAN U95%!AC22)');
        $this->assertSamaExcel($cmc['AC23'], (float) $budget['u95_kn'], 'U95 kN = MAX(U, CMC) (PERHITUNGAN U95%!AC23)');
        $this->assertTrue($budget['lantai_cmc_menang'], 'aplikasi: CMC menang atas U hitung');
        $this->assertGreaterThan(
            self::nilai(self::wb()['excel']['budget']['agregat']['U_kn_AH21']),
            self::nilai($cmc['AC22_cmc_kn']),
            'master: CMC menang atas U hitung (AH21)',
        );
    }

    /**
     * ANGKA CETAK U. U (kgf) = hasil aplikasi dikonversi balik lewat hook cetak; nilainya
     * sama dengan `SERTIFIKAT!P35` karena lantai CMC menang di kedua sisi. Teks cetak
     * U dan k diadu di [MenegakkanParitasGaya] (k beda nilai karena B8-1, sama teks).
     */
    public function test_u_tercetak_sama_dengan_p35_dan_format_kolom_sama_dengan_sel_master(): void
    {
        $profil = $this->profil();
        $sesi = self::sesiCetak();
        $cetak = $profil->cetakDalamSatuanAlat($sesi);
        $cetakan = self::wb()['excel']['cetak'];

        foreach ($this->hitung() as $h) {
            $titik = $h['titik_ke'];
            $uCetak = $cetak['ubah']((float) $h['ketidakpastian_diperluas'], $titik);

            $this->assertUSamaExcel($cetakan['U_cetak_P35'], $uCetak, "titik {$titik} U kgf vs SERTIFIKAT!P35");
        }

        $this->assertSame(
            ['standar' => '0.0', 'uut' => '0.0', 'koreksi' => '0.00', 'sebaran' => '0.00', 'u95' => '0.0', 'k' => '0'],
            $profil->formatKolomSertifikat($sesi, 100.0, 'kgf'),
            'format kolom sertifikat UTM = format sel SERTIFIKAT!E22:V22, P35, T36',
        );
        $this->assertStringContainsString('AC23', $cetakan['U_cetak_rumus_P35'], 'P35 membaca AC23 (MAX(U, CMC)) dalam kN');
    }

    /** `titik_dicetak`: titik nol disembunyikan di master (`SERTIFIKAT!E21` baris tersembunyi). */
    public function test_titik_nol_tidak_dicetak_seperti_baris_tersembunyi_master(): void
    {
        $sesi = self::sesiCetak();

        $this->assertFalse($this->profil()->titikDicetakSertifikat($sesi, 0.0, 1), 'titik nol tidak dicetak');
        $this->assertTrue($this->profil()->titikDicetakSertifikat($sesi, 100.0, 2), 'titik 100 kgf dicetak');
    }

    // ------------------------------------------------------------------ MENUNGGU

    /**
     * M-3: sel status master berbasis `NOW()` tidak dipakai sebagai acuan. Yang dicek di
     * sini hanya bahwa fixture TIDAK memuat sel itu, supaya orang tidak menambahkannya
     * sebagai "bukti" yang bergeser tiap workbook dibuka.
     */
    public function test_m_3_fixture_tidak_memuat_sel_berbasis_now(): void
    {
        $json = (string) json_encode(self::wb()['masukan'] + self::wb()['excel']);

        foreach (['INPUT DATA!Z23', 'INPUT DATA!Z24', 'INPUT DATA!Z25', 'INPUT DATA!Z26', 'INPUT DATA!Z27', 'DATABASE!Z13', 'DATABASE!Z14', 'DATABASE!Z15'] as $sel) {
            $this->assertStringNotContainsString($sel, $json, "{$sel} berbasis NOW() — tidak boleh jadi acuan");
        }
    }
}
