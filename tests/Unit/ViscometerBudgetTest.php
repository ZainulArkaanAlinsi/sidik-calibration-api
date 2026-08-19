<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Services\Calibration\Profiles\ViscometerProfile;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Budget ketidakpastian Viscometer, diadu ke `Master Olah Data_Viscometer`
 * (sheet `PERHITUNGAN U95%`). Angka acuan DISALIN dari workbook, bukan dari
 * keluaran kode — kalau engine-nya bener, dia reproduksi persis.
 *
 * 4 komponen per titik:
 *   1. Sertifikat kalibrator : u = U95%_std / 2                       (vi 200)
 *   2. Daya baca alat        : u = (resolusi/2)/√3                    (vi 1e6)
 *   3. Temperature           : u = UTemp/√3 ; ci = (UTemp/400)·nominal (vi 50)
 *   4. Pengulangan (Type A)  : u = stdev/√n                           (vi n−1)
 *
 *   UTemperature = √((0,72/2)² + (0,06/2)²) = 0,36124783736376886
 *
 * Dua hal yang bikin alat ini beda dari Turbidimeter, dan dua-duanya dijaga di
 * sini karena gampang banget kegeser tanpa ketahuan:
 *
 *  - `vi` komponen suhu **50**, bukan 200. Turbidimeter & Chlorine pakai 200
 *    buat rumus yang sama persis; master Viscometer pakai 50.
 *  - `ci` dikali nilai NOMINAL @25 °C (99,65 / 1018 / 59003), bukan titik ukur
 *    yang sudah tergeser suhu (93,88 / 910,29 / 61898,12).
 */
class ViscometerBudgetTest extends TestCase
{
    use RefreshDatabase;

    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
    }

    private function uTemperature(): float
    {
        return sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);
    }

    /**
     * Komponen budget seperti yang disusun master.
     *
     * `$pembagiTypeA` & `$viTypeA` sengaja bisa disetel: master mematoknya `√5`
     * dan `4` bahkan di titik yang cuma punya empat pembacaan sah — lihat
     * [test_titik_60000_reproduksi_master_kalau_disuapin_pembagi_master].
     *
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(
        float $uStd95,
        float $resolusi,
        float $nominal,
        float $stdev,
        float $pembagiTypeA,
        float $viTypeA,
    ): array {
        $sqrt3 = sqrt(3);
        $uTemp = $this->uTemperature();

        return [
            ['u' => $uStd95 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => ($resolusi / 2) / $sqrt3, 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $uTemp / $sqrt3, 'ci' => ($uTemp / 400) * $nominal, 'vi' => 50],
            ['u' => $stdev / $pembagiTypeA, 'ci' => 1.0, 'vi' => $viTypeA],
        ];
    }

    public function test_titik_100_reproduksi_master(): void
    {
        // stdev [97,3 96,9 96,8 95,9 96,7] = 0,5118593556827863
        $r = $this->gum->agregasiBudget(
            $this->komponen(0.169405, 0.1, 99.65, 0.5118593556827863, sqrt(5), 4),
        );

        $this->assertEqualsWithDelta(0.24649576970552553, $r['ketidakpastian_gabungan'], 1e-12);
        $this->assertEqualsWithDelta(5.376144439333908, $r['derajat_kebebasan_efektif'], 1e-9);
    }

    public function test_titik_1000_reproduksi_master(): void
    {
        // stdev [919,6 918,7 917,4 916,3 916,3] = 1,4673104647620117
        $r = $this->gum->agregasiBudget(
            $this->komponen(2.3414, 0.1, 1018.0, 1.4673104647620117, sqrt(5), 4),
        );

        $this->assertEqualsWithDelta(1.356001576327294, $r['ketidakpastian_gabungan'], 1e-11);
        $this->assertEqualsWithDelta(60.620109476367105, $r['derajat_kebebasan_efektif'], 1e-8);
    }

    /**
     * Titik 60000 cP DENGAN pembagi master (√5, vi 4).
     *
     * Master ngerata-rata & ngitung STDEV dari EMPAT angka — sel pengulangan
     * ke-5 isinya teks `631.74.2` dan Excel melewatinya — tapi pembaginya tetap
     * dipatok `√5` dengan `vi = 4`. Dua hal itu nggak bisa dua-duanya benar.
     *
     * Tes ini nyuapin pembagi master apa adanya, dan hasilnya cocok persis. Itu
     * buktinya mesin GUM kita nggak beda dari masternya — yang beda cuma `n`
     * yang dipakai, dan itu keputusan data (lihat
     * [test_titik_60000_dengan_n_konsisten_beda_dari_master]).
     */
    public function test_titik_60000_reproduksi_master_kalau_disuapin_pembagi_master(): void
    {
        // stdev [63181,3 63079,8 63172,1 63174,2] = 48,19436343252727
        $r = $this->gum->agregasiBudget(
            $this->komponen(135.7069, 0.1, 59003.0, 48.19436343252727, sqrt(5), 4),
        );

        $this->assertEqualsWithDelta(72.05656247619179, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta(168.2349490679152, $r['derajat_kebebasan_efektif'], 1e-7);
    }

    /**
     * Titik 60000 cP seperti yang BENERAN diseed: empat pembacaan, pembagi √4,
     * `vi = 3`.
     *
     * Angkanya sengaja beda dari master, dan bedanya diassert eksplisit supaya
     * nggak ada yang ngira ini kebetulan lolos toleransi. Kalau lab menjawab
     * berapa angka sebenarnya di sel `631.74.2`, tes inilah yang bakal merah
     * duluan.
     */
    public function test_titik_60000_dengan_n_konsisten_beda_dari_master(): void
    {
        $r = $this->gum->agregasiBudget(
            $this->komponen(135.7069, 0.1, 59003.0, 48.19436343252727, sqrt(4), 3),
        );

        $this->assertEqualsWithDelta(72.85796476171055, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertGreaterThan(72.05656247619179, $r['ketidakpastian_gabungan']);
    }

    /**
     * Faktor cakupan titik 100 cP: **2,5706 — bukan 2 kayak master.**
     *
     * `veff`-nya 5,376, dipotong ke bawah jadi 5 (GUM G.4.1), dan
     * `t(0,975 ; 5) = 2,5706`. Master nyetak `k = 2`, yang nggak konsisten sama
     * master pH lab sendiri (2,77645 buat veff 4,92) — jadi yang nyimpang sel
     * viscometer-nya, bukan mesin kita. Workbook viscometer juga lembar trial:
     * Certificate Number & Order Number-nya kosong, jadi nggak ada sertifikat
     * terbit yang perlu direproduksi.
     *
     * Diassert eksplisit — bukan dibiarin lolos toleransi longgar — karena ini
     * satu-satunya angka Viscometer yang SENGAJA beda dari master. Lihat
     * `docs/pertanyaan-lab-viscometer.md`.
     */
    public function test_faktor_cakupan_titik_100_ikut_tabel_t_bukan_dua(): void
    {
        $r = $this->gum->agregasiBudget(
            $this->komponen(0.169405, 0.1, 99.65, 0.5118593556827863, sqrt(5), 4),
        );

        $this->assertEqualsWithDelta(2.570581835636312, $r['faktor_cakupan_k'], 1e-6);
        $this->assertNotEqualsWithDelta(2.0, $r['faktor_cakupan_k'], 1e-3);
        // U master 0,49299153941105106; punya kita lebih besar, bukan lebih kecil.
        $this->assertEqualsWithDelta(0.6336375481662154, $r['ketidakpastian_diperluas'], 1e-8);
    }

    public function test_profil_ngasih_koefisien_suhu_dari_nilai_nominal(): void
    {
        $profil = new ViscometerProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => $this->uTemperature()]);
        // Larutan 1000 cP — nominalnya 1018 @25 °C.
        $standard = $this->standarLarutan(2);
        $equipment = new Equipment(['satuan' => 'cP', 'resolusi' => 0.1]);

        // Titik ukur yang masuk SUDAH tergeser suhu (910,29), tapi `ci` harus
        // dihitung dari nominalnya (1018) — itu yang cocok sama master.
        $komponen = $profil->komponenBudget(
            $kemampuan, $equipment, $standard, 910.2887323943662, 1.4673104647620117 / sqrt(5), 5,
        );

        $suhu = collect($komponen)->firstWhere('sumber', 'ketidakpastian_temperature');
        $this->assertEqualsWithDelta(0.9193757460907918, $suhu['ci'], 1e-12);
        $this->assertSame(50, $suhu['vi']);

        $std = collect($komponen)->firstWhere('sumber', 'ketidakpastian_standar');
        $this->assertEqualsWithDelta(2.3414 / 2, $std['u'], 1e-12);
    }

    /**
     * Nilai acuan = interpolasi linier tabel sertifikat larutan pada suhu
     * terukur — bukan nominal botol, dan bukan trendline kubik yang ikut
     * tercetak di master (yang ngasih 97,16 cP di 26,52 °C, meleset 3,5 %).
     */
    public function test_nilai_acuan_diinterpolasi_dari_tabel_suhu(): void
    {
        $harapan = [
            [1, 26.52, 93.87566510172147],
            [2, 27.3, 910.2887323943662],
            [3, 24.6, 61898.119999999995],
        ];

        foreach ($harapan as [$ke, $suhu, $nilai]) {
            $this->assertEqualsWithDelta(
                $nilai,
                $this->standarLarutan($ke)->nilaiPadaSuhu($suhu),
                1e-9,
                "larutan ke-{$ke} pada {$suhu} °C",
            );
        }
    }

    /** Suhu di luar jangkauan tabel NGGAK diekstrapolasi — balik null. */
    public function test_suhu_di_luar_tabel_tidak_diekstrapolasi(): void
    {
        $this->assertNull($this->standarLarutan(1)->nilaiPadaSuhu(15.0));
        $this->assertNull($this->standarLarutan(1)->nilaiPadaSuhu(120.0));
    }

    /**
     * MPE = 1 % × Fullscale + 1 % × pembacaan, dengan
     * Fullscale = TK × SMC × 10000 / RPM. Diadu ke blok "MPE Check for
     * Viscometer" di sheet `SERTIFIKAT`.
     */
    public function test_fullscale_dan_mpe_reproduksi_master(): void
    {
        $profil = new ViscometerProfile;
        $alat = new Equipment(['satuan' => 'cP']);

        $kasus = [
            ['HA1', 63.0, 96.72, 317.46031746031747, 4.141803174603175],
            ['HA2', 62.0, 917.6600000000001, 1290.3225806451612, 22.079825806451613],
            ['HA7', 62.0, 63151.85, 129032.25806451612, 1921.8410806451611],
        ];

        foreach ($kasus as [$spindle, $rpm, $rataRata, $fullscale, $mpe]) {
            $this->assertEqualsWithDelta(
                $fullscale,
                $profil->fullscale('DV2THA', $spindle, $rpm),
                1e-9,
                "fullscale {$spindle}",
            );

            $this->assertEqualsWithDelta(
                $mpe,
                $profil->toleransiTitik(0.0, $rataRata, $alat, [
                    'tk' => 'DV2THA', 'spindle' => $spindle, 'rpm' => $rpm,
                ]),
                1e-9,
                "MPE {$spindle}",
            );
        }
    }

    /** TK kebaca dari nama badan MAUPUN nama layar — dua-duanya tercetak di kertas. */
    public function test_tk_kebaca_dari_nama_badan_maupun_layar(): void
    {
        $profil = new ViscometerProfile;

        $this->assertSame(2.0, $profil->tkModel('DV2THA'));
        $this->assertSame(2.0, $profil->tkModel('HA'));
        $this->assertSame(0.09373, $profil->tkModel('LV'));
        $this->assertNull($profil->tkModel('bukan model'));
    }

    /**
     * Spindle atau RPM kosong = MPE nggak dihitung, BUKAN MPE karangan.
     *
     * Balik `null` bikin `GumCalculator` jatuh ke `equipments.toleransi` — yang
     * buat Viscometer emang null — jadi vonisnya kosong. Kosong itu jawaban
     * yang benar; angka toleransi ngarang bakal jadi vonis PASS/FAIL palsu.
     */
    public function test_mpe_null_kalau_spindle_atau_rpm_kosong(): void
    {
        $profil = new ViscometerProfile;
        $alat = new Equipment(['satuan' => 'cP']);

        $this->assertNull($profil->toleransiTitik(0.0, 96.72, $alat, ['tk' => 'DV2THA', 'rpm' => 63.0]));
        $this->assertNull($profil->toleransiTitik(0.0, 96.72, $alat, ['tk' => 'DV2THA', 'spindle' => 'HA1']));
        $this->assertNull($profil->toleransiTitik(0.0, 96.72, $alat, ['spindle' => 'HA1', 'rpm' => 63.0]));
        // RPM nol bikin Fullscale bagi nol — ditolak, bukan tak hingga.
        $this->assertNull($profil->toleransiTitik(0.0, 96.72, $alat, [
            'tk' => 'DV2THA', 'spindle' => 'HA1', 'rpm' => 0.0,
        ]));
    }

    /**
     * Enam alat lain nggak kesenggol hook baru ini: `toleransiTitik()` bawaan
     * balik null, jadi mereka tetap pakai `equipments.toleransi`.
     */
    public function test_profil_lain_tetap_pakai_toleransi_alat(): void
    {
        $this->assertNull(
            (new TurbidimeterProfile)
                ->toleransiTitik(1000.0, 1001.0, new Equipment(['toleransi' => 24])),
        );
    }

    /**
     * Jalur penuh: CMC kepasang lewat RENTANG (bukan titik tunggal), U95
     * dilantai ke CMC, dan vonisnya lahir dari MPE — bukan dari
     * `equipments.toleransi` yang emang null.
     */
    public function test_hitung_titik_penuh_pakai_cmc_rentang_dan_vonis_mpe(): void
    {
        $equipment = $this->alatDenganKemampuan();

        $hasil = $this->gum->hitungTitik(
            1,
            99.65,
            [97.3, 96.9, 96.8, 95.9, 96.7],
            $equipment,
            $this->standarLarutan(1),
            26.52,
            25.25,
            ['tk' => 'DV2THA', 'spindle' => 'HA1', 'rpm' => 63.0],
        );

        // Titik ukurnya tergeser ke nilai pada suhu terukur.
        $this->assertEqualsWithDelta(93.87566510172147, $hasil['titik_ukur'], 1e-9);
        $this->assertEqualsWithDelta(96.72, $hasil['rata_rata'], 1e-9);
        $this->assertEqualsWithDelta(-2.8443348982785324, $hasil['koreksi'], 1e-9);
        $this->assertEqualsWithDelta(0.24649576970552553, $hasil['ketidakpastian_gabungan'], 1e-10);
        // U hitung 0,4930 > CMC 0,2 → yang dilaporkan hasil hitungnya.
        //
        // `0.49299153941105106` COCOK PERSIS sama `PERHITUNGAN U95%` baris 24.
        // Sebelum `ViscometerProfile::faktorCakupanTetap()` ada, angkanya
        // 0,6336: `uc` sama persis kayak master, tapi `k`-nya t-student 2,5706
        // (v_eff 5,376) sementara sel `k` masternya `2`. Lihat method itu soal
        // kenapa viscometer ngunci k dan lima alat lain nggak.
        $this->assertEqualsWithDelta(0.49299153941105106, $hasil['ketidakpastian_diperluas'], 1e-8);
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-12);
        $this->assertEqualsWithDelta(4.141803174603175, $hasil['toleransi'], 1e-9);
        // Guarded acceptance: |error| 2,844 + U 0,634 = 3,478 <= MPE 4,142.
        $this->assertSame('PASS', $hasil['keputusan']);
        $this->assertSame(ViscometerProfile::KODE_METODE, $hasil['metode']);
    }

    /**
     * Budget titik 100 cP diadu KOMPONEN PER KOMPONEN ke lembar lab.
     *
     * ## Kenapa selengkap ini, padahal `U95`-nya udah dites di atas
     *
     * 19 Agt 2026 lab menjalankan ulang workbook-nya sendiri dan titik ini
     * keluar `37,87` cP, sementara app — dan `SERTIFIKAT.csv` yang jadi acuan
     * di repo ini — nulis `0,49299154`. Beda 77 kali lipat, dan dari angka JADI
     * doang nggak ada yang bisa mutusin siapa yang salah.
     *
     * Yang bisa mutusin cuma budget-nya dibongkar: empat komponen, `uc`,
     * `v_eff`, `k`. Sekali itu dibandingkan, jawabannya nggak lagi soal
     * pendapat — `v_eff = 5,376144439` itu angka TURUNAN (Welch-Satterthwaite
     * dari keempat `uici` beserta `vi`-nya), dan dia nggak bisa cocok sampai
     * sembilan desimal kalau ada satu komponen pun yang beda.
     *
     * Dan `37,87` nggak bisa lahir dari budget ini. Disapu satu per satu, biar
     * keluar segitu salah satu ini mesti benar: `U95% Standar` 37,867 (master
     * 0,169405), resolusi 65,587 cP (master 0,1), UTemperature 11,47 °C
     * (master 0,361), atau STDEV pembacaan 42,34 cP pada rata-rata 96,72.
     * Nggak ada satu pun yang bisa datang dari sesi ini.
     *
     * Pemeriksa terakhir yang paling gampang dilihat: MPE titik ini 4,1418 cP.
     * `U95` 37,87 berarti ketidakpastiannya SEMBILAN KALI lebih lebar dari
     * batas keberterimaannya sendiri — titik itu nggak akan pernah bisa lulus,
     * dan sertifikat kayak gitu nggak pernah terbit.
     *
     * Angka acuannya `PERHITUNGAN U95%.csv` baris 18-24, kolom `uici`.
     */
    public function test_budget_titik_100_cocok_komponen_per_komponen(): void
    {
        $hasil = $this->gum->hitungTitik(
            1,
            99.65,
            [97.3, 96.9, 96.8, 95.9, 96.7],
            $this->alatDenganKemampuan(),
            $this->standarLarutan(1),
            26.52,
            25.25,
            ['tk' => 'DV2THA', 'spindle' => 'HA1', 'rpm' => 63.0],
        );

        $uici = [];

        foreach ($hasil['type_b_components'] as $k) {
            $uici[$k['sumber']] = (float) $k['nilai'];
        }

        // Keempat baris budget, urut kayak di lembar lab.
        $this->assertEqualsWithDelta(0.08470250000000001, $uici['ketidakpastian_standar'], 1e-12);
        $this->assertEqualsWithDelta(0.02886751345948129, $uici['resolusi_alat'], 1e-12);
        $this->assertEqualsWithDelta(0.018770126348448452, $uici['ketidakpastian_temperature'], 1e-9);
        $this->assertEqualsWithDelta(0.22891046284519068, $uici['pengulangan_pembacaan'], 1e-12);

        // Turunannya. `v_eff` yang paling nggak bisa cocok karena kebetulan.
        $this->assertEqualsWithDelta(0.24649576970552553, $hasil['ketidakpastian_gabungan'], 1e-10);
        $this->assertEqualsWithDelta(5.376144439333908, $hasil['derajat_kebebasan_efektif'], 1e-8);
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-12);
        $this->assertEqualsWithDelta(0.49299153941105106, $hasil['ketidakpastian_diperluas'], 1e-10);

        // U95 wajib jauh DI BAWAH MPE. Penjaga kewarasan yang langsung merah
        // kalau salah satu komponen meledak kayak kasus `37,87`.
        $this->assertEqualsWithDelta(4.141803174603175, $hasil['toleransi'], 1e-9);
        $this->assertLessThan($hasil['toleransi'], $hasil['ketidakpastian_diperluas']);
    }

    /**
     * Titik ketiga jatuh di 61898,12 cP — DI ATAS batas lingkup akreditasi
     * 58021 cP. Dua hal yang harus terjadi bareng, dan gampang cuma kejadian
     * salah satunya:
     *
     *  1. **Nggak ada lantai CMC.** Lab nggak boleh mendem `U95` ke angka
     *     kemampuan yang nggak diakreditasi buat rentang itu.
     *  2. **Budgetnya tetap EMPAT komponen.** Ini yang gampang jebol: kalau
     *     baris kemampuan "di luar lingkup" nggak diseed, `hitungTitik()`
     *     nggak nemu kemampuan apa pun dan jatuh ke jalur cadangan dua
     *     komponen yang MEMBUANG pengaruh suhu — `uc` mengecil jadi 72,0053
     *     dan sertifikatnya ngeklaim lebih bagus dari yang bisa dibuktikan.
     *     Diam-diam, tanpa error.
     */
    public function test_titik_di_luar_lingkup_tetap_budget_penuh_tanpa_lantai_cmc(): void
    {
        $equipment = $this->alatDenganKemampuan();

        $hasil = $this->gum->hitungTitik(
            3,
            59003.0,
            // Empat pembacaan, bukan lima — sel ke-5 master isinya `631.74.2`.
            [63181.3, 63079.8, 63172.1, 63174.2],
            $equipment,
            $this->standarLarutan(3),
            24.6,
            25.25,
            ['tk' => 'DV2THA', 'spindle' => 'HA7', 'rpm' => 62.0],
        );

        $this->assertEqualsWithDelta(61898.119999999995, $hasil['titik_ukur'], 1e-8);
        $this->assertEqualsWithDelta(63151.85, $hasil['rata_rata'], 1e-9);

        // Empat komponen + satu baris jejak `perbandingan_cmc`.
        $sumber = array_column($hasil['type_b_components'], 'sumber');
        $this->assertContains('ketidakpastian_temperature', $sumber);
        $this->assertSame(
            ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'pengulangan_pembacaan', 'perbandingan_cmc'],
            $sumber,
        );

        // `uc` jalur penuh. Jalur cadangan ngasih 72,0053 — beda yang harus
        // bikin test ini merah, bukan lewat.
        $this->assertEqualsWithDelta(72.85796479, $hasil['ketidakpastian_gabungan'], 1e-7);
        $this->assertEqualsWithDelta(128.8499002, $hasil['derajat_kebebasan_efektif'], 1e-6);

        // Tanpa lantai: yang dilaporkan hasil hitung apa adanya, bukan 140 cP.
        //
        // `145,7159` = `uc` x 2. Master nulis `142,3405` di titik INI — dan itu
        // dua selisih yang berdiri sendiri, dua-duanya udah ditelusuri:
        //
        //  1. **`k`.** Sel `k` master titik ini `1,9754` (t-student buat v_eff
        //     168), sementara titik 100 & 1000 cP-nya `2`. Workbook-nya sendiri
        //     campur. Yang dipakai di sini `2`, ngikutin kalimat sertifikat
        //     masternya (`Coverage Factor ( k ) = 2`) dan keempat baris CMC
        //     viscometer (`faktor_cakupan = 2`) — sertifikat yang nyetak k=2
        //     tapi ngitung pakai 1,9754 itu bantah diri sendiri.
        //  2. **`uc`.** Master 72,0566, di sini 72,8580. Bedanya CUMA di
        //     komponen pengulangan: STDEV-nya sama persis (48,19436343252727,
        //     dari empat pembacaan yang kepakai), tapi master ngebagi √5
        //     sementara di sini √4. Sel ke-5 master isinya `631.74.2` — teks,
        //     jadi AVERAGE & STDEV Excel ngelewatin dia, TAPI sel pembagi &
        //     `vi`-nya kepatok 5 dan 4. Type A yang bener `s/√n` dengan `n`
        //     yang beneran dirata-rata, yaitu 4.
        $this->assertEqualsWithDelta(145.71592952342112, $hasil['ketidakpastian_diperluas'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-12);
        $this->assertSame(0.0, (float) $hasil['type_b_components'][4]['nilai']);

        $this->assertEqualsWithDelta(1921.8410806451611, $hasil['toleransi'], 1e-8);
        $this->assertSame('PASS', $hasil['keputusan']);
    }

    /**
     * Alat Viscometer lengkap dengan EMPAT baris kemampuan, sama persis kayak
     * `ViscometerCapabilitySeeder`: tiga rentang terakreditasi (batas atasnya
     * ikut lampiran KAN — 102 / 1028 / 58021 cP) plus satu rentang di luar
     * lingkup yang `ketidakpastian_terbaik`-nya 0 (= nggak ada klaim).
     */
    private function alatDenganKemampuan(): Equipment
    {
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::create([
            'organization_id' => $org->id, 'kode' => 'instrumen-analitik', 'nama' => 'Instrumen Analitik',
        ]);

        $rentang = [
            [51.1, 102.0, 0.2],
            [419.5, 1028.0, 2.1],
            [19259.0, 58021.0, 140.0],
            [58021.0, 95192.0, 0.0],
        ];

        foreach ($rentang as [$min, $maks, $cmc]) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Viscometer',
                'parameter' => "viskositas (cP)-{$min}",
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => 'cP',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'cP',
                'faktor_cakupan' => 2,
                'metode' => ViscometerProfile::KODE_METODE,
                'u_temperature' => $this->uTemperature(),
            ]);
        }

        $equipment = new Equipment([
            'nama_alat' => 'Viscometer',
            'nama_alat_kemampuan' => 'Viscometer',
            'satuan' => 'cP',
            'resolusi' => 0.1,
            // SENGAJA null — batasnya lahir dari MPE per titik.
            'toleransi' => null,
        ]);
        $equipment->equipment_category_id = $kategori->id;

        return $equipment;
    }

    /**
     * Larutan standar ke-N lengkap dengan tabel sertifikat suhunya. Angkanya
     * dari `Tabel Pengaruh Temperature.csv`.
     */
    private function standarLarutan(int $ke): Standard
    {
        $tabel = [
            1 => [
                'u' => 0.169405,
                'baris' => [
                    [20.0, 134.0, 0.17], [25.0, 99.65, 0.17], [37.78, 51.1, 0.15], [40.0, 45.97, 0.15],
                    [50.0, 29.75, 0.13], [60.0, 20.32, 0.13], [80.0, 10.75, 0.13], [98.89, 6.638, 0.08],
                    [100.0, 6.47, 0.08],
                ],
            ],
            2 => [
                'u' => 2.3414,
                'baris' => [
                    [20.0, 1504.0, 0.23], [25.0, 1018.0, 0.23], [37.78, 419.5, 0.19], [40.0, 364.6, 0.19],
                    [50.0, 203.2, 0.19], [60.0, 121.3, 0.17], [80.0, 51.27, 0.15], [98.89, 26.8, 0.13],
                    [100.0, 25.9, 0.13],
                ],
            ],
            3 => [
                'u' => 135.7069,
                'baris' => [
                    [20.0, 95192.0, 0.23], [25.0, 59003.0, 0.23], [37.78, 19259.0, 0.23], [40.0, 16096.0, 0.23],
                    [50.0, 7489.0, 0.22], [60.0, 3732.0, 0.22], [80.0, 1117.0, 0.2], [98.89, 432.7, 0.17],
                    [100.0, 411.3, 0.17],
                ],
            ],
        ][$ke];

        return new Standard([
            'nama' => "Viscosity Standard Solution {$ke}",
            'ketidakpastian' => $tabel['u'],
            'satuan_ketidakpastian' => 'cP',
            'faktor_cakupan' => 2,
            'koefisien_suhu' => [
                'tabel' => array_map(
                    static fn (array $b): array => ['suhu' => $b[0], 'nilai' => $b[1], 'u_persen' => $b[2]],
                    $tabel['baris'],
                ),
            ],
        ]);
    }
}
