<?php

namespace Tests\Unit;

use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\TabelStandarMicrometer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adu mesin hitung Micrometer ke empat workbook master ber-password yang turun
 * dari lab — sel demi sel, bukan cuma U95 akhirnya.
 *
 * Fixture `tests/Fixtures/micrometer-master.json` memuat masukan mentah
 * (pra-evaluasi, balok ukur, suhu, kapasitas, resolusi) DAN nilai yang
 * diharapkan untuk tiap komponen budget plus `uc`/`veff`/`k`/`U95` — disalin
 * dari sel workbook (`data_only`) oleh `docs/skrip/gen-fixture-micrometer.py`,
 * bukan dari keluaran PHP.
 *
 * ## Kenapa tiap KOMPONEN diadu, bukan cuma U95-nya
 *
 * U95 akhir bisa cocok sementara isinya salah: dua komponen yang saling
 * menutupi menghasilkan `uc` yang sama. Di alat ini bukan kekhawatiran
 * teoretis — komponen "Perubahan suhu" dan "Koefisien muai thermal" identik
 * menurut konstruksi, jadi salah satunya bisa nol tanpa menggeser U95 sama
 * sekali.
 *
 * ## Umur drift diberi SAMA dengan master, bukan tanggal sesi
 *
 * `DATABASE!X11` master berisi `=NOW()`, jadi komponen drift-nya tergantung
 * kapan berkasnya terakhir dibuka — keempat workbook disimpan selang dua menit
 * dan umur driftnya beda (695,4212 vs 695,4225 hari). Di produksi umur itu
 * dihitung dari tanggal kalibrasi SESI supaya bisa diulang; di sini test
 * sengaja memberi saat yang sama dengan master, karena yang diuji **rumusnya
 * reproduksi**, bukan pilihan tanggalnya.
 *
 * ## Satu titik yang SENGAJA beda dari master
 *
 * Panjang untuk koefisien sensitivitas. Master memakai `PERHITUNGAN!F61` —
 * nilai balok ukur PERTAMA di tumpukan titik terakhir, bukan total nominalnya.
 * Bentuk yang benar terbukti dari master ITU SENDIRI: di tiga dari empat
 * workbook titik terakhirnya cuma satu keping, dan di ketiganya `F61` sama
 * persis dengan total nominal. Cuma 0-25 mm yang menumpuk (6 + 19 = 25 mm),
 * dan di situ master memakai 6 mm.
 *
 * Arahnya ditegakkan `test_panjang_ci_lebih_besar_dari_master_saat_bertumpuk`:
 * hitungan kita wajib lebih BESAR, bukan sekadar berbeda.
 */
class MicrometerMasterTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/micrometer-master.json')),
            true,
        );
    }

    /** @return array<string, array{string}> */
    public static function workbook(): array
    {
        return [
            '0-25 mm' => ['025'],
            '25-50 mm' => ['2550'],
            '50-75 mm' => ['5075'],
            '75-100 mm' => ['75100'],
        ];
    }

    /**
     * Susun argumen `hitungSesi()` dari fixture, dengan umur drift dipatok ke
     * saat master dihitung supaya komponen drift bisa diadu.
     *
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function masukan(string $kode): array
    {
        $wb = self::fixture()['workbook'][$kode];

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'nominal' => $t['nominal_mm'],
            'pembacaan' => $t['pembacaan_mm'],
        ], $wb['titik']);

        return [$titik, [
            'kapasitas_mm' => $wb['masukan']['kapasitas_mm'],
            'resolusi_mm' => $wb['masukan']['resolusi_mm'],
            // Keduanya sama, dan sama dengan rata-rata suhu ruangan — dijaga
            // `test_suhu_balok_dan_uut_sama_dengan_rata_rata_suhu_ruangan`.
            'suhu_ruang_rata_c' => $wb['masukan']['suhu_balok_c'],
            'pra_evaluasi' => $wb['masukan']['pra_evaluasi_mm'],
            'balok_pra_evaluasi' => $wb['masukan']['balok_pra_evaluasi_mm'],
            'tanggal_kalibrasi' => new DateTimeImmutable($wb['masukan']['saat_master_dihitung']),
        ]];
    }

    /**
     * Sembilan komponen budget cocok master di keempat workbook.
     *
     * Yang diadu nilai `u` SESUDAH dibagi pembagi — itu yang masuk agregasi.
     * Nilai `k` mentah master dikalikan pembagi yang sama di sini, supaya kalau
     * pembaginya sendiri salah, test-nya merah.
     */
    #[DataProvider('workbook')]
    public function test_sembilan_komponen_budget_cocok_master(string $kode): void
    {
        [$titik, $konteks] = $this->masukan($kode);
        $m = self::fixture()['workbook'][$kode]['master'];

        $hasil = (new MicrometerCalculator)->hitungSesi($titik, $konteks);
        $budget = collect($hasil['budget'])->keyBy('sumber');

        $akar3 = sqrt(3.0);
        $harapan = [
            'pengulangan' => [$m['k_repeatability'], sqrt(count($konteks['pra_evaluasi']))],
            'resolusi_uut' => [$m['k_resolusi'], $akar3],
            'ketidakpastian_standar' => [$m['k_balok'], 2.0],
            'suhu_ruang' => [$m['k_suhu'], $akar3],
            'koefisien_muai' => [$m['k_muai'], $akar3],
            'drift_standar' => [$m['k_drift'], $akar3],
            'wringing' => [$m['k_wringing'], $akar3],
            'geometri' => [$m['k_geometri'], $akar3],
            'selisih_suhu' => [$m['k_selisih_suhu'], $akar3],
        ];

        foreach ($harapan as $sumber => [$kMaster, $pembagi]) {
            $this->assertEqualsWithDelta(
                $kMaster / $pembagi,
                $budget[$sumber]['u'],
                5e-6 * max(1.0, abs($kMaster / $pembagi)),
                "komponen '{$sumber}' meleset dari master {$kode}",
            );
        }
    }

    /**
     * `uc`, `veff`, `k`, dan `U95` cocok master — untuk tiga workbook yang
     * titik terakhirnya SATU keping, jadi panjang `ci` kita dan master sama.
     *
     * 0-25 mm sengaja tidak ikut: di situ kita memakai total nominal 25 mm
     * sementara master memakai 6 mm, dan bedanya diuji terpisah.
     */
    #[DataProvider('workbookKepingTunggal')]
    public function test_uc_veff_k_u95_cocok_master(string $kode): void
    {
        [$titik, $konteks] = $this->masukan($kode);
        $m = self::fixture()['workbook'][$kode]['master'];

        $hasil = (new MicrometerCalculator)->hitungSesi($titik, $konteks);

        $this->assertEqualsWithDelta($m['uc'], $hasil['ketidakpastian_gabungan'], 5e-6, 'uc');
        $this->assertEqualsWithDelta($m['veff'], $hasil['derajat_kebebasan_efektif'], 5e-4, 'veff');
        $this->assertEqualsWithDelta($m['k'], $hasil['faktor_cakupan_k'], 5e-6, 'k');
        $this->assertEqualsWithDelta($m['u95_um'], $hasil['ketidakpastian_diperluas'], 5e-6, 'U95');
    }

    /** @return array<string, array{string}> */
    public static function workbookKepingTunggal(): array
    {
        return [
            '25-50 mm' => ['2550'],
            '50-75 mm' => ['5075'],
            '75-100 mm' => ['75100'],
        ];
    }

    /**
     * Di tiga workbook berkeping tunggal, `F61` master SAMA PERSIS dengan total
     * nominal titik terakhir — itu yang membuktikan maksud rumusnya total
     * nominal, bukan keping pertama.
     */
    #[DataProvider('workbookKepingTunggal')]
    public function test_panjang_ci_master_sama_dengan_total_nominal_saat_keping_tunggal(string $kode): void
    {
        $wb = self::fixture()['workbook'][$kode];

        $this->assertEqualsWithDelta(
            $wb['titik'][count($wb['titik']) - 1]['total_nominal_master_mm'],
            $wb['master']['panjang_ci_master_mm'],
            1e-9,
            "F61 master {$kode} seharusnya sama dengan total nominal titik terakhir",
        );
    }

    /**
     * Di 0-25 mm titik terakhirnya BERTUMPUK (6 + 19 mm), dan di situ master
     * memakai keping pertama. Hitungan kita wajib lebih BESAR — arah yang aman.
     */
    public function test_panjang_ci_lebih_besar_dari_master_saat_bertumpuk(): void
    {
        $wb = self::fixture()['workbook']['025'];
        $totalKita = $wb['titik'][count($wb['titik']) - 1]['total_nominal_master_mm'];

        $this->assertGreaterThan(
            $wb['master']['panjang_ci_master_mm'],
            $totalKita,
            'total nominal titik terakhir 0-25 mm harus lebih besar dari F61 master',
        );

        [$titik, $konteks] = $this->masukan('025');
        $budget = collect((new MicrometerCalculator)->hitungSesi($titik, $konteks)['budget'])
            ->keyBy('sumber');

        $this->assertGreaterThan(
            $wb['master']['ci_suhu'],
            $budget['suhu_ruang']['ci'],
            'ci komponen suhu wajib lebih besar dari master, bukan sekadar berbeda',
        );
    }

    /**
     * Titik-titiknya sendiri: total nominal, rata-rata pembacaan, dan koreksi
     * cocok master baris demi baris.
     */
    #[DataProvider('workbook')]
    public function test_tiap_titik_cocok_master(string $kode): void
    {
        [$titik, $konteks] = $this->masukan($kode);
        $wb = self::fixture()['workbook'][$kode];

        $hasil = (new MicrometerCalculator)->hitungSesi($titik, $konteks);
        $dihitung = collect($hasil['titik'])->keyBy('titik_ke');

        foreach ($wb['titik'] as $t) {
            // Titik tanpa pembacaan di master (titik nol 0-25 mm) memang tidak
            // punya rata-rata maupun koreksi — master mencetak selnya kosong.
            if ($t['total_nominal_master_mm'] === null || $t['rata_rata_master_mm'] === null) {
                continue;
            }

            $kita = $dihitung[$t['titik_ke']] ?? null;
            $this->assertNotNull($kita, "titik {$t['titik_ke']} ({$kode}) tidak terhitung");

            $this->assertEqualsWithDelta(
                $t['total_nominal_master_mm'], $kita['total_nominal'], 5e-6,
                "total nominal titik {$t['titik_ke']} ({$kode})",
            );
            $this->assertEqualsWithDelta(
                $t['rata_rata_master_mm'], $kita['rata_rata'], 5e-6,
                "rata-rata titik {$t['titik_ke']} ({$kode})",
            );
            $this->assertEqualsWithDelta(
                $t['koreksi_master_mm'], $kita['koreksi'], 5e-6,
                "koreksi titik {$t['titik_ke']} ({$kode})",
            );
        }
    }

    /**
     * Lantai CMC. Master 0-25 mm menerbitkan U95 0,735 µm padahal pita
     * terakreditasinya 0,83 µm — lookup-nya gagal jadi teks `"cek range"`, lalu
     * `MAX()` mengabaikan teks itu. Kita menolak menerbitkan.
     */
    public function test_kapasitas_di_luar_pita_cmc_diblokir_bukan_diterbitkan(): void
    {
        [$titik, $konteks] = $this->masukan('025');

        // 635 mm — kapasitas 0-25 mm master sesudah dikali 25,4 karena
        // satuannya tersetel `inch`.
        $this->assertSame(635.0, $konteks['kapasitas_mm']);

        $hasil = (new MicrometerCalculator)->hitungSesi($titik, $konteks);

        $this->assertNull($hasil['pita_cmc']);
        // `u95_sertifikat` nol di sini menandai "tidak terbit", dan yang
        // MENEGAKKANNYA `MicrometerProfile::hitungPerGrup()`: tanpa pita, dia
        // tidak melahirkan satu pun baris hitungan, jadi angka nol ini tidak
        // pernah sampai ke sertifikat. Lihat
        // `MicrometerSesiTest::test_kapasitas_di_luar_pita_cmc_diblokir`.
        $this->assertSame(0.0, $hasil['u95_sertifikat'], 'U95 tidak boleh terbit tanpa lantai CMC');
        $this->assertNotEmpty($hasil['ditolak']);

        $alasan = implode(' | ', array_column($hasil['ditolak'], 'alasan'));
        $this->assertStringContainsString('di luar keempat pita CMC', $alasan);

        // Budget-nya tetap terhitung — yang diblokir penerbitannya, bukan
        // perhitungannya. Admin butuh melihat komponennya untuk tahu apa yang
        // salah.
        $this->assertCount(9, $hasil['budget']);
        $this->assertGreaterThan(0.0, $hasil['ketidakpastian_diperluas']);
    }

    /**
     * Kapasitas yang benar (25 mm, bukan 635) mendarat di pita A dan U95-nya
     * terangkat ke lantai 0,83 µm — bukti bahwa master menerbitkan angka di
     * bawah lantainya sendiri.
     */
    /**
     * Resolusi yang belum diisi MEMBLOKIR penerbitan, bukan diterbitkan lebih
     * kecil lalu ditutupi lantai CMC.
     *
     * Kotak resolusi boleh kosong di lembar (`semua_kolom_opsional`), dan yang
     * kosong terbaca 0 — komponen resolusi jadi `(0 × 1000 / 2) / √3` alias
     * nol. Yang bikin ini paling licin dari ketiga gerbang: lantai CMC yang
     * seharusnya jadi penjaga malah MENYAMARKAN komponen yang hilang.
     *
     *   resolusi 0,001 mm -> uc 0,4439 -> U95 0,8722 -> terbit 0,8722
     *   resolusi kosong   -> uc 0,3372 -> U95 0,6638 -> terbit 0,8700 (lantai)
     *
     * Selisih yang tercetak 0,25 %, dan tidak ada satu pun error di jalurnya.
     */
    /**
     * Kedua komponen termal menyumbang NOL ke budget — dan itu temuan, bukan
     * fisika.
     *
     * Budget bekerja dalam µm, tapi `ci` kedua komponen termal dihitung dengan
     * L dalam MILIMETER (`ci_suhu = α × L`, `ci_muai = L × Δϴ`). Akibatnya
     * sumbangan keduanya seribu kali lebih kecil daripada rumus yang sama di
     * satuan yang konsisten, dan `uc` seluruhnya berdiri di atas enam komponen
     * lain.
     *
     * Ditiru dari master — dan test ini yang MEMBUKTIKAN kita menirunya, bukan
     * memperbaikinya diam-diam. Kalau suatu saat satuannya dikonsistenkan, test
     * ini merah dan yang membacanya diarahkan ke
     * `docs/analisis-pertanyaan-lab-micrometer.md` §11: U95 sesi 25-50 mm naik
     * 0,872 → 0,978 µm, yaitu DI ATAS pita CMC 0,87 µm yang diakui lampiran
     * akreditasi. Jadi yang perlu ditinjau bukan cuma angkanya, tapi apakah
     * CMC-nya sendiri tercapai.
     *
     * Belum diubah karena `u` kedua komponen juga belum benar (master memakai
     * besaran itu sendiri sebagai ketidakpastiannya). Membetulkan satuan tanpa
     * membetulkan `u` menukar satu kesalahan dengan kesalahan yang lebih besar.
     */
    public function test_komponen_termal_menyumbang_nol_karena_satuan_ci(): void
    {
        $kalk = new MicrometerCalculator;

        $hasil = $kalk->hitungSesi(
            [[
                'titik_ke' => 1,
                'nominal' => [6.0, 19.0],
                'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001],
            ]],
            [
                'kapasitas_mm' => 50.0,
                'resolusi_mm' => 0.001,
                'tanggal_kalibrasi' => new DateTimeImmutable('2025-05-02'),
                'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.0, 50.0, 50.0],
                'balok_pra_evaluasi' => [50.0],
                'suhu_ruang_rata_c' => 20.55,
            ],
        );

        $sumbangan = [];
        foreach ($hasil['budget'] as $b) {
            $sumbangan[$b['sumber']] = $b['u'] * $b['ci'];
        }

        $uc = (float) $hasil['ketidakpastian_gabungan'];

        foreach (['suhu_ruang', 'koefisien_muai'] as $termal) {
            $porsi = ($sumbangan[$termal] ** 2) / ($uc ** 2);

            $this->assertLessThan(
                1e-6,
                $porsi,
                "Komponen `{$termal}` sekarang menyumbang nyata ke uc. Kalau satuan `ci`-nya "
                .'baru dikonsistenkan ke µm, baca docs/analisis-pertanyaan-lab-micrometer.md §11 '
                .'lebih dulu: U95 naik ke ~0,978 µm, DI ATAS pita CMC 0,87 µm.',
            );
        }

        // `uc` berdiri utuh di atas enam komponen non-termal.
        $tanpaTermal = 0.0;
        foreach ($sumbangan as $nama => $nilai) {
            if (! in_array($nama, ['suhu_ruang', 'koefisien_muai', 'selisih_suhu'], true)) {
                $tanpaTermal += $nilai ** 2;
            }
        }

        // Bukan nol MUTLAK — sumbangannya 7,9e-5 µm, jadi bedanya ~1,4e-8 pada
        // uc. Ambangnya ditulis longgar sedikit dari itu, bukan dipaksa nol:
        // yang diklaim "tidak menyumbang secara berarti", dan angka yang jujur
        // lebih berguna daripada nol yang dibulatkan.
        $this->assertEqualsWithDelta(
            sqrt($tanpaTermal),
            $uc,
            1e-7,
            'uc tanpa termal harus praktis sama dengan uc penuh.',
        );
    }

    public function test_resolusi_kosong_memblokir_penerbitan(): void
    {
        $kalk = new MicrometerCalculator;

        $titik = [[
            'titik_ke' => 1,
            'nominal' => [6.0, 19.0],
            'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001],
        ]];
        $konteks = [
            'kapasitas_mm' => 50.0,
            'tanggal_kalibrasi' => new DateTimeImmutable('2025-05-02'),
            'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.0, 50.0, 50.0],
            'balok_pra_evaluasi' => [50.0],
            'suhu_ruang_rata_c' => 20.55,
        ];

        $isi = $kalk->hitungSesi($titik, [...$konteks, 'resolusi_mm' => 0.001]);
        $this->assertTrue($isi['boleh_terbit'], 'Resolusi terisi harus boleh terbit.');

        $kosong = $kalk->hitungSesi($titik, [...$konteks, 'resolusi_mm' => 0.0]);

        $this->assertFalse(
            $kosong['boleh_terbit'],
            'Resolusi kosong tetap terbit — U95-nya lebih kecil dan lantai CMC menyamarkannya.',
        );

        // Dan bedanya memang tersamarkan kalau gerbangnya tidak ada: yang
        // terbit sama-sama ~0,87 walau `uc`-nya beda jauh.
        $this->assertGreaterThan(
            $kosong['ketidakpastian_gabungan'],
            $isi['ketidakpastian_gabungan'],
            'Komponen resolusi harusnya menaikkan uc.',
        );
        $this->assertEqualsWithDelta(0.87, $kosong['u95_sertifikat'], 1e-9, 'Lantai CMC yang menyamarkan.');

        $alasan = implode(' ', array_column($kosong['ditolak'], 'alasan'));
        $this->assertStringContainsString('Resolusi alat belum diisi', $alasan);
    }

    public function test_u95_terangkat_ke_lantai_cmc_saat_kapasitas_benar(): void
    {
        [$titik, $konteks] = $this->masukan('025');
        $konteks['kapasitas_mm'] = 25.0;
        $konteks['resolusi_mm'] = 0.001;

        $hasil = (new MicrometerCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame('A', $hasil['pita_cmc']['kode']);
        $this->assertSame(0.83, $hasil['pita_cmc']['u95_um']);
        $this->assertGreaterThanOrEqual(0.83, $hasil['u95_sertifikat']);
    }

    /**
     * Suhu balok ukur dan suhu UUT SAMA, dan sama dengan rata-rata suhu
     * ruangan — di keempat workbook master.
     *
     * Ini yang membuat kertas lembar kerja (`SIDIK-FM-CAL-0522`) tidak punya
     * kotak untuk keduanya, dan yang membuat komponen budget ke-9 ("selisih
     * suhu mikrometer dengan balok ukur") selalu nol. Kalau identitas ini
     * runtuh, dua keputusan itu ikut runtuh — jadi dia dijaga di sini, bukan
     * cuma ditulis di komentar.
     */
    #[DataProvider('workbook')]
    public function test_suhu_balok_dan_uut_sama_dengan_rata_rata_suhu_ruangan(string $kode): void
    {
        $m = self::fixture()['workbook'][$kode]['masukan'];
        $sesi = json_decode(
            (string) file_get_contents(base_path('database/data/sesi-master-micrometer.json')),
            true,
        )['sesi'][$kode]['_sesi'];

        $rata = ((float) $sesi['suhu_awal'] + (float) $sesi['suhu_akhir']) / 2;

        $this->assertEqualsWithDelta($m['suhu_balok_c'], $m['suhu_uut_c'], 1e-12, 'suhu balok vs UUT');
        $this->assertEqualsWithDelta($rata, (float) $m['suhu_balok_c'], 1e-12, 'suhu balok vs rata-rata ruangan');
        $this->assertEqualsWithDelta($rata, (float) $m['suhu_uut_c'], 1e-12, 'suhu UUT vs rata-rata ruangan');
    }

    /**
     * Sebelas nominal PRA-CETAK tiap varian cocok dengan total nominal yang
     * benar-benar dihitung master.
     *
     * Deretnya bukan aritmetika, dan itu bukan salah cetak: nominal ketiga
     * varian B (31,0) dan C (51,0) melanggar pola +2,55 yang diikuti A dan D,
     * tapi keduanya cocok dengan master (30,99997 dan 51,00025). Tumpukan balok
     * yang tersedia yang menentukan.
     */
    #[DataProvider('workbook')]
    public function test_nominal_pracetak_cocok_total_nominal_master(string $kode): void
    {
        $wb = self::fixture()['workbook'][$kode];
        $kapasitas = $wb['masukan']['kapasitas_mm'];

        // Varian 0-25 mm kapasitasnya cacat di master (635 mm, satuan `inch`),
        // jadi dipilih lewat nominal titik terakhirnya.
        $varian = (new TabelStandarMicrometer)->pitaCmc(
            $kapasitas > 100.0 ? 25.0 : (float) $kapasitas,
        );

        $this->assertNotNull($varian, "varian {$kode} tidak ketemu");
        $this->assertCount(11, $varian['titik']);

        foreach ($varian['titik'] as $i => $titik) {
            $master = $wb['titik'][$i]['total_nominal_master_mm'];

            if ($master === null) {
                continue;
            }

            $this->assertEqualsWithDelta(
                $master,
                (float) $titik['nominal_cetak_mm'],
                0.06,
                "nominal pra-cetak titik {$i} varian {$kode}",
            );
        }
    }

    /**
     * Tabel balok ukur memuat 32 keping dan TIDAK memuat baris kosong yang di
     * master terbaca nol — kalau ikut tersalin, nominal 0 mendapat balok ukur
     * dan total nominal satu titik bergeser diam-diam.
     */
    public function test_tabel_balok_ukur_tanpa_baris_nol_palsu(): void
    {
        $tabel = new TabelStandarMicrometer;

        $this->assertCount(32, $tabel->balokUkur());

        foreach ($tabel->balokUkur() as $nominal => $nilai) {
            $this->assertGreaterThan(0.0, (float) $nominal);
            $this->assertGreaterThan(0.0, (float) $nilai);
        }
    }

    /** Slot balok ukur kosong tidak boleh memungut 0,12 µm dari cabang `<= 10`. */
    public function test_slot_balok_kosong_menyumbang_nol(): void
    {
        $kalk = new MicrometerCalculator;

        $this->assertSame(0.0, $kalk->ketidakpastianTumpukan([null, null]));
        $this->assertSame(0.0, $kalk->ketidakpastianTumpukan([]));

        // Satu keping 20 mm -> 0,14 µm (cabang `<= 21`), bukan 0,12.
        $this->assertEqualsWithDelta(0.14, $kalk->ketidakpastianTumpukan([20.0, null]), 1e-12);
    }

    /** Umur drift negatif ditolak, bukan mengurangi ketidakpastian. */
    public function test_umur_standar_negatif_ditolak(): void
    {
        $kalk = new MicrometerCalculator;

        $this->assertNull($kalk->umurStandarHari(new DateTimeImmutable('2023-01-01')));
        $this->assertEqualsWithDelta(
            366.0,
            $kalk->umurStandarHari(new DateTimeImmutable('2025-01-24')),
            1e-6,
        );
    }
}
