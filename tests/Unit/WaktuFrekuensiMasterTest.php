<?php

namespace Tests\Unit;

use App\Services\Calibration\PutaranCalculator;
use App\Services\Calibration\TabelStandarPutaran;
use App\Services\Calibration\TabelStandarWaktu;
use App\Services\Calibration\WaktuCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adu ketiga mesin hitung kelompok "Waktu dan Frekuensi" ke workbook master
 * ber-password yang turun dari lab — sel demi sel, bukan cuma angka akhirnya.
 *
 * Fixture `tests/Fixtures/waktu-frekuensi-master.json` memuat pembacaan mentah
 * 33 titik rpm + 10 titik waktu, DAN nilai yang diharapkan untuk tiap kolom
 * turunan plus tiap komponen budget — disalin dari sel workbook (`data_only`),
 * bukan dari keluaran PHP.
 *
 * ## Kenapa tiap KOMPONEN diadu, bukan cuma U95-nya
 *
 * U95 akhir bisa cocok sementara isinya salah: dua komponen yang saling
 * menutupi menghasilkan `uc` yang sama. Di sini itu bukan kekhawatiran teoretis
 * — blok 5 Tachometer punya U95 yang "wajar" (1,69 rpm) justru karena DUA
 * komponennya nol.
 *
 * ## Empat titik yang SENGAJA beda dari master
 *
 * Semuanya kerusakan salin-tempel yang perilaku benarnya tidak ambigu — blok
 * tetangga di berkas yang sama, atau workbook saudaranya, melakukannya dengan
 * benar. Keempatnya membuat U95 master terbit lebih KECIL, jadi ditiru bukan
 * pilihan yang masuk akal. Yang dilakukan: dihitung BENAR, selisihnya ditulis
 * di sini dan di `docs/pertanyaan-lab-waktu-frekuensi.md`, dan ARAHNYA
 * ditegakkan test — hitungan kita wajib lebih besar, bukan sekadar berbeda.
 *
 *  1. Tachometer blok 5 — pita sertifikat, `u_drift`, dan pengulangan
 *     (`test_blok_rusak_tachometer_dihitung_lebih_besar`)
 *  2. Centrifuge blok 1 — pengulangan satu sel alih-alih `MAX`
 *  3. Drift kalibrator — kolom `K` cuma berrumus di 5 dari 15 baris berdata
 *  4. `uHRTB` Timer — `MAX(N21:N22)`, dua dari empat operator
 *
 * Nomor 3 & 4 tidak bisa "dimatikan sebagian": begitu tabel drift dilengkapi,
 * SEMUA blok bergeser. Makanya `test_blok_*_cocok_master` menghitung dengan
 * tabel versi-master (`lengkap: false`) supaya yang diuji rumusnya, sementara
 * `test_drift_lengkap_lebih_besar` yang menegakkan arah pelengkapannya.
 */
class WaktuFrekuensiMasterTest extends TestCase
{
    /** Toleransi yang diminta playbook alat baru. */
    private const TOL = 5e-6;

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/waktu-frekuensi-master.json')),
            true,
        );
    }

    // ---------------------------------------------------------------- rpm

    /** @return iterable<string, array{string}> */
    public static function alatRpm(): iterable
    {
        yield 'tachometer' => ['tachometer'];
        yield 'centrifuge' => ['centrifuge'];
    }

    /**
     * Tiap kolom turunan tiap titik: rata-rata, nominal sertifikat yang dipilih,
     * koreksi standar, nilai terkoreksi, koreksi, simpangan baku.
     *
     * `nominal_standar` yang ikut diadu itu inti pembuktiannya: sel `Indexed
     * Value` di master TIDAK punya rumus (teknisi mengetiknya), dan yang diuji
     * di sini adalah bahwa aturan "nominal terdekat, seri ke atas" memulangkan
     * angka yang sama untuk ke-33 titik.
     */
    #[DataProvider('alatRpm')]
    public function test_tiap_kolom_turunan_titik_cocok_master(string $alat): void
    {
        $tabel = new TabelStandarPutaran;
        $kalk = new PutaranCalculator($tabel);
        $diperiksa = 0;

        foreach (self::fixture()[$alat]['titik'] as $t) {
            $hasil = $kalk->hitungBlok(
                [['titik_ke' => $t['titik_ke'], 'titik_ukur' => $t['set_point'], 'pembacaan' => $t['pembacaan']]],
                ['resolusi_uut' => 1.0, 'cmc' => null, 'satuan' => 'rpm'],
            );

            $this->assertCount(1, $hasil['titik'], "Titik {$t['titik_ke']} {$alat} nggak kehitung.");
            $kita = $hasil['titik'][0];

            foreach ($t['harap'] as $kolom => $harap) {
                $this->assertEqualsWithDelta(
                    $harap, $kita[$kolom], self::TOL,
                    "{$alat} titik {$t['titik_ke']} (baris {$t['baris']} kolom {$t['kolom']}) kolom `{$kolom}`",
                );
                $diperiksa++;
            }
        }

        $this->assertSame(count(self::fixture()[$alat]['titik']) * 6, $diperiksa);
    }

    /**
     * Tiap komponen budget tiap blok, lalu `uc`, `veff`, `k`, `U`.
     *
     * Blok yang ditandai `rusak` di fixture dilewati di sini dan diuji sendiri
     * — lihat `test_blok_rusak_*`.
     */
    #[DataProvider('alatRpm')]
    public function test_tiap_komponen_budget_blok_cocok_master(string $alat): void
    {
        $data = self::fixture()[$alat];
        $blokDiuji = 0;

        foreach ($data['blok'] as $b) {
            if ($b['rusak'] ?? false) {
                continue;
            }

            $hasil = $this->hitungBlokMasterism($data, $b);
            $h = $b['harap'];

            $this->assertEqualsWithDelta($h['ui_sertifikat'], $hasil['budget'][0]['u'], self::TOL,
                "{$alat} blok r{$b['baris']}: ui sertifikat kalibrator");
            $this->assertEqualsWithDelta($h['ui_resolusi_standar'], $hasil['budget'][1]['u'], self::TOL,
                "{$alat} blok r{$b['baris']}: ui daya baca standar");
            $this->assertEqualsWithDelta($h['ui_resolusi_uut'], $hasil['budget'][2]['u'], self::TOL,
                "{$alat} blok r{$b['baris']}: ui daya baca UUT");
            $this->assertEqualsWithDelta($h['ui_drift'], $hasil['budget'][3]['u'], self::TOL,
                "{$alat} blok r{$b['baris']}: ui drift standar");
            $this->assertEqualsWithDelta($h['ui_pengulangan'], $hasil['budget'][4]['u'], self::TOL,
                "{$alat} blok r{$b['baris']}: ui pengulangan");

            $this->assertEqualsWithDelta($h['uc'], $hasil['ketidakpastian_gabungan'], self::TOL,
                "{$alat} blok r{$b['baris']}: uc");
            $this->assertEqualsWithDelta($h['veff'], $hasil['derajat_kebebasan_efektif'], 1e-3,
                "{$alat} blok r{$b['baris']}: veff");
            $this->assertEqualsWithDelta($h['k'], $hasil['faktor_cakupan_k'], self::TOL,
                "{$alat} blok r{$b['baris']}: k (TINV memotong derajat kebebasan ke integer)");
            $this->assertEqualsWithDelta($h['U'], $hasil['ketidakpastian_diperluas'], self::TOL,
                "{$alat} blok r{$b['baris']}: U");

            $blokDiuji++;
        }

        $this->assertGreaterThanOrEqual(4, $blokDiuji, "Terlalu sedikit blok {$alat} yang diuji.");
    }

    /**
     * Lantai CMC: `U95 = MAX(U; CMC)` — master menutup tiap blok dengan itu.
     */
    #[DataProvider('alatRpm')]
    public function test_lantai_cmc_dipakai_seperti_master(string $alat): void
    {
        $data = self::fixture()[$alat];

        foreach ($data['blok'] as $b) {
            if ($b['rusak'] ?? false) {
                continue;
            }

            $hasil = $this->hitungBlokMasterism($data, $b, (float) $b['harap']['cmc']);

            $this->assertEqualsWithDelta(
                $b['harap']['u95_sertifikat'], $hasil['u95_sertifikat'], self::TOL,
                "{$alat} blok r{$b['baris']}: U95 sertifikat (lantai CMC {$b['harap']['cmc']})",
            );
        }
    }

    /**
     * Blok 5 Tachometer: rusak di tiga tempat, dan hitungan kita wajib LEBIH
     * BESAR — bukan sekadar berbeda.
     *
     * Master: U = 1,6949 rpm dengan `u_drift` = 0, pengulangan = 0, dan pita
     * sertifikat 1,6 alih-alih 3,1.
     */
    public function test_blok_rusak_tachometer_dihitung_lebih_besar(): void
    {
        $data = self::fixture()['tachometer'];
        $b = collect($data['blok'])->firstWhere('rusak', true);

        $this->assertNotNull($b, 'Fixture Tachometer kehilangan penanda blok rusak.');

        $hasil = $this->hitungBlokMasterism($data, $b);

        // Ketiga komponen yang di master nol / meleset, di sini punya angka.
        $this->assertEqualsWithDelta(3.1 / 2, $hasil['budget'][0]['u'], self::TOL,
            'Pita sertifikat blok 5 harus F18 (3,1), bukan F15 (1,6) — titik tertingginya 15000 rpm.');
        $this->assertGreaterThan(0.0, $hasil['budget'][3]['u'],
            "u_drift blok 5 nggak boleh nol — master membacanya dari '[1]Drift Std Kalibrator'!K54 yang kosong.");
        $this->assertGreaterThan(0.0, $hasil['budget'][4]['u'],
            'Pengulangan blok 5 nggak boleh nol — master menunjuk baris 113 yang kosong.');

        $this->assertGreaterThan(
            (float) $b['harap']['U'], $hasil['ketidakpastian_diperluas'],
            'Hitungan yang benar wajib LEBIH BESAR dari master di blok yang rusak, bukan lebih kecil.',
        );
    }

    /**
     * Blok 1 Centrifuge: pengulangan dari satu sel, bukan `MAX` sebaris.
     *
     * Bentuk yang benar terbukti dari master itu sendiri — workbook Tachometer
     * memakai `MAX(G34:L34)` di blok yang datanya sama persis.
     */
    public function test_blok_rusak_centrifuge_dihitung_lebih_besar(): void
    {
        $data = self::fixture()['centrifuge'];
        $b = collect($data['blok'])->firstWhere('rusak', true);

        $this->assertNotNull($b, 'Fixture Centrifuge kehilangan penanda blok rusak.');

        $hasil = $this->hitungBlokMasterism($data, $b);
        $tacho = collect(self::fixture()['tachometer']['blok'])->firstWhere('baris', 9);

        $this->assertEqualsWithDelta(
            $tacho['harap']['ui_pengulangan'], $hasil['budget'][4]['u'], self::TOL,
            'Pengulangan blok 1 Centrifuge harus sama dengan blok 1 Tachometer — datanya identik, '
            .'dan Tachometer memakai MAX() yang benar.',
        );

        $this->assertGreaterThan(
            (float) $b['harap']['U'], $hasil['ketidakpastian_diperluas'],
            'Hitungan yang benar wajib LEBIH BESAR dari master di blok yang rusak.',
        );
    }

    /**
     * Melengkapi kolom `K` tabel drift menaikkan setengah-lebarnya 2,25 → 2,75
     * rpm. Yang ditegakkan ARAHNYA, dan bahwa versi master masih bisa dipanggil
     * — tanpa itu, test blok di atas tidak punya pembanding.
     */
    public function test_drift_lengkap_lebih_besar_dari_versi_master(): void
    {
        $tabel = new TabelStandarPutaran;

        $master = $tabel->driftSetengahLebar(lengkap: false);
        $lengkap = $tabel->driftSetengahLebar(lengkap: true);

        $this->assertEqualsWithDelta(2.25, $master, self::TOL, 'Versi master harus 4,5/2 = 2,25 rpm.');
        $this->assertGreaterThan($master, $lengkap, 'Tabel drift yang dilengkapi wajib lebih besar.');
        $this->assertEqualsWithDelta(2.75, $lengkap, self::TOL, 'Versi lengkap harus 5,5/2 = 2,75 rpm.');
    }

    /**
     * Aturan "nominal terdekat, seri ke ATAS" — diadu ke ketiga kasus seri yang
     * benar-benar muncul di kedua workbook rpm.
     */
    public function test_nominal_terdekat_rpm_memutus_seri_ke_atas(): void
    {
        $tabel = new TabelStandarPutaran;

        // Seri sungguhan: |80-60| = |80-100| = 20, dan master mengetik 100.
        $this->assertSame(100.0, $tabel->nominalTerdekat(80.0));
        // |150-100| = |150-200| = 50, master mengetik 200.
        $this->assertSame(200.0, $tabel->nominalTerdekat(150.0));
        // Bukan seri, dan hasilnya kelihatan janggal tapi benar: 500 memang
        // lebih dekat ke 1000 daripada 2000.
        $this->assertSame(500.0, $tabel->nominalTerdekat(1000.0));
        $this->assertSame(60.0, $tabel->nominalTerdekat(60.0));
    }

    /** Titik tanpa pembacaan diblokir dengan alasan, bukan lahir sebagai titik hantu. */
    public function test_titik_kosong_rpm_diblokir_dengan_alasan(): void
    {
        $hasil = (new PutaranCalculator)->hitungBlok(
            [
                ['titik_ke' => 1, 'titik_ukur' => 0.0, 'pembacaan' => [0, 0, 0, 0, 0]],
                ['titik_ke' => 2, 'titik_ukur' => 60.0, 'pembacaan' => []],
            ],
            ['resolusi_uut' => 1.0, 'cmc' => null, 'satuan' => 'rpm'],
        );

        $this->assertSame([], $hasil['titik'], 'Titik hantu nggak boleh melahirkan baris hasil.');
        $this->assertCount(2, $hasil['ditolak']);

        foreach ($hasil['ditolak'] as $tolak) {
            $this->assertNotSame('', trim($tolak['alasan']), 'Penolakan wajib menyebut alasan yang kebaca.');
        }
    }

    // -------------------------------------------------------------- waktu

    /**
     * Tiap kolom turunan tiap titik Timer yang BERISI — nominal sertifikat,
     * koreksi standar, standar terkoreksi, simpangan baku terbesar, koreksi.
     */
    public function test_tiap_kolom_turunan_timer_cocok_master(): void
    {
        $tabel = new TabelStandarWaktu;
        $diperiksa = 0;

        foreach (self::fixture()['timer']['titik'] as $t) {
            if ($t['titik_hantu']) {
                continue;
            }

            $h = $t['harap'];
            $sp = (float) $t['set_point_detik'];
            $nominal = $tabel->nominalTerdekat($sp);

            $this->assertEqualsWithDelta($h['nominal_standar_detik'], $nominal, self::TOL,
                "Timer titik {$t['titik_ke']}: nominal sertifikat yang dipilih");

            $koreksiStd = $tabel->koreksiMs($nominal);
            $this->assertEqualsWithDelta($h['koreksi_standar_ms'], $koreksiStd, self::TOL,
                "Timer titik {$t['titik_ke']}: koreksi standar");

            $rataStd = array_sum($t['standar_ms']) / count($t['standar_ms']);
            $rataUut = array_sum($t['uut_ms']) / count($t['uut_ms']);

            // Master menyimpan `STD CORRECTED` sebagai kolom MILIDETIK saja —
            // jam/menit/detiknya tinggal di tiga kolom sebelahnya. Jadi
            // pembandingnya nilai master PLUS offset jam/menit/detik, bukan
            // nilai master apa adanya.
            $this->assertEqualsWithDelta(
                $h['std_terkoreksi_ms'] + $t['offset_jms_standar_ms'],
                $rataStd + $koreksiStd,
                self::TOL,
                "Timer titik {$t['titik_ke']}: standar terkoreksi (kolom ms master + offset J/M/S)",
            );
            $this->assertEqualsWithDelta($h['koreksi_ms'], $rataStd + $koreksiStd - $rataUut, self::TOL,
                "Timer titik {$t['titik_ke']}: koreksi");
            $this->assertEqualsWithDelta(
                $h['stdev_maks_ms'],
                max($this->stdev($t['standar_ms']), $this->stdev($t['uut_ms'])),
                self::TOL,
                "Timer titik {$t['titik_ke']}: simpangan baku terbesar — total milidetik wajib "
                .'memulangkan angka yang sama dengan MAX delapan kolom master.',
            );

            $diperiksa++;
        }

        $this->assertSame(5, $diperiksa, 'Master Timer punya lima titik berisi.');
    }

    /**
     * Budget Set Point 1 — satu-satunya blok yang menghitung di master. Keenam
     * komponennya diadu satu per satu, lalu `uc`, `veff`, `k`, `U`, dan lantai
     * CMC.
     */
    public function test_budget_set_point_1_timer_cocok_master(): void
    {
        $data = self::fixture()['timer'];
        $b = $data['budget_set_point_1'];
        $h = $b['harap'];
        $titik = collect($data['titik'])->firstWhere('titik_ke', 1);

        $hasil = $this->hitungTimerMasterism($titik, (float) $b['resolusi_uut_detik'], (float) $h['cmc']);
        $budget = $hasil['budget'];

        $this->assertCount(6, $budget, 'Master Set Point 1 memakai ENAM komponen, bukan empat.');

        foreach ([
            0 => 'ui_sertifikat', 1 => 'ui_resolusi', 2 => 'ui_drift',
            3 => 'ui_pengulangan', 4 => 'ui_hrtb', 5 => 'ui_hrtsd',
        ] as $i => $kunci) {
            $this->assertEqualsWithDelta($h[$kunci], $budget[$i]['u'], self::TOL,
                "Timer SP1 komponen `{$kunci}`");
        }

        $r = $hasil['hasil'];
        $this->assertEqualsWithDelta($h['uc'], $r['ketidakpastian_gabungan'], self::TOL, 'Timer SP1: uc');
        $this->assertEqualsWithDelta($h['veff'], $r['derajat_kebebasan_efektif'], 1e-3, 'Timer SP1: veff');
        $this->assertEqualsWithDelta($h['k'], $r['faktor_cakupan_k'], self::TOL, 'Timer SP1: k');
        $this->assertEqualsWithDelta($h['U'], $r['ketidakpastian_diperluas'], self::TOL, 'Timer SP1: U');
        $this->assertEqualsWithDelta($h['u95_sertifikat'], $r['u95_sertifikat'], self::TOL,
            'Timer SP1: U95 sertifikat — lantai CMC 0,81 s menang atas U 0,38 s.');
    }

    /**
     * Kedua pelengkapan Timer (drift 298 → 322 ms, uHRTB dua → empat operator)
     * wajib menaikkan U, bukan menurunkannya.
     */
    public function test_pelengkapan_timer_menaikkan_u(): void
    {
        $data = self::fixture()['timer'];
        $b = $data['budget_set_point_1'];
        $titik = collect($data['titik'])->firstWhere('titik_ke', 1);
        $tabel = new TabelStandarWaktu;

        $this->assertGreaterThan(
            $tabel->driftMs(lengkap: false), $tabel->driftMs(lengkap: true),
            'Tabel drift stopwatch yang dilengkapi wajib lebih besar.',
        );
        $this->assertGreaterThan(
            $tabel->uHumanReactionRata(lengkap: false), $tabel->uHumanReactionRata(lengkap: true),
            'uHRTB dari keempat operator wajib lebih besar daripada dari dua operator terakhir.',
        );

        $lengkap = (new WaktuCalculator)->hitungTitik(
            [
                'titik_ke' => 1, 'titik_ukur' => (float) $titik['set_point_detik'],
                'standar' => $titik['standar_ms'], 'uut' => $titik['uut_ms'],
            ],
            ['resolusi_uut_detik' => (float) $b['resolusi_uut_detik'], 'cmc' => null],
        );

        $this->assertGreaterThan(
            (float) $b['harap']['U'], $lengkap['hasil']['ketidakpastian_diperluas'],
            'Hitungan yang benar wajib LEBIH BESAR dari master.',
        );
    }

    /**
     * Lima titik kosong di master melahirkan `CORRECTION = 30 ms` karena sel
     * kosong dibaca nol. Di sini kelimanya wajib diblokir.
     */
    public function test_titik_hantu_timer_diblokir(): void
    {
        $kalk = new WaktuCalculator;
        $hantu = 0;

        foreach (self::fixture()['timer']['titik'] as $t) {
            if (! $t['titik_hantu']) {
                continue;
            }

            $hasil = $kalk->hitungTitik(
                [
                    'titik_ke' => $t['titik_ke'], 'titik_ukur' => (float) $t['set_point_detik'],
                    'standar' => $t['standar_ms'], 'uut' => $t['uut_ms'],
                ],
                ['resolusi_uut_detik' => 0.001, 'cmc' => null],
            );

            $this->assertNull($hasil['hasil'],
                "Titik hantu {$t['titik_ke']} nggak boleh melahirkan hasil — di master dia "
                .'mencetak koreksi 30 ms yang kelihatan seperti titik sungguhan.');
            $this->assertNotSame('', trim((string) $hasil['alasan']));

            $hantu++;
        }

        $this->assertSame(5, $hantu, 'Master Timer punya lima titik hantu.');
    }

    /**
     * Seri nominal di kelompok WAKTU diputus ke BAWAH — arah yang berlawanan
     * dengan kelompok rpm, dan itu disengaja: master Timer mengetik 600 untuk
     * set point 900 detik yang jaraknya sama ke 600 dan 1200.
     */
    public function test_nominal_terdekat_waktu_memutus_seri_ke_bawah(): void
    {
        $tabel = new TabelStandarWaktu;

        $this->assertSame(600.0, $tabel->nominalTerdekat(900.0),
            'Set point 15 menit: master mengetik nominal 600 detik, bukan 1200.');
        $this->assertSame(60.0, $tabel->nominalTerdekat(60.0));
        $this->assertSame(300.0, $tabel->nominalTerdekat(300.0));
        $this->assertSame(1800.0, $tabel->nominalTerdekat(1800.0));
    }

    // ------------------------------------------------------------- bantu

    /**
     * Hitung satu blok rpm dengan tabel drift versi MASTER, supaya yang diuji
     * rumusnya — bukan pelengkapan tabel yang diuji terpisah.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function hitungBlokMasterism(array $data, array $b, ?float $cmc = null): array
    {
        $titik = collect($data['titik'])
            ->where('baris', $b['baris_ukur'])
            ->map(static fn (array $t): array => [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => $t['set_point'],
                'pembacaan' => $t['pembacaan'],
            ])
            ->values()
            ->all();

        return (new PutaranCalculator(new TabelStandarPutaranVersiMaster))->hitungBlok(
            $titik,
            [
                'resolusi_uut' => (float) $b['resolusi_uut'],
                'cmc' => $cmc,
                'satuan' => 'rpm',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $titik
     * @return array<string, mixed>
     */
    private function hitungTimerMasterism(array $titik, float $resolusi, ?float $cmc): array
    {
        return (new WaktuCalculator(new TabelStandarWaktuVersiMaster))->hitungTitik(
            [
                'titik_ke' => $titik['titik_ke'],
                'titik_ukur' => (float) $titik['set_point_detik'],
                'standar' => $titik['standar_ms'],
                'uut' => $titik['uut_ms'],
            ],
            ['resolusi_uut_detik' => $resolusi, 'cmc' => $cmc],
        );
    }

    /** @param  list<float>  $x */
    private function stdev(array $x): float
    {
        $n = count($x);
        $m = array_sum($x) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $m) ** 2, $x)) / ($n - 1));
    }
}

/**
 * Tabel putaran yang memakai drift versi MASTER (5 dari 15 baris), supaya blok
 * bisa diadu ke workbook tanpa pelengkapan tabel ikut campur.
 *
 * Subkelas sekali pakai, bukan saklar di kelas produksi: kode produksi tidak
 * boleh punya jalan untuk diam-diam memakai drift yang kurang lengkap.
 */
class TabelStandarPutaranVersiMaster extends TabelStandarPutaran
{
    public function driftSetengahLebar(bool $lengkap = true): float
    {
        return parent::driftSetengahLebar(lengkap: false);
    }
}

/** Padanannya buat kelompok waktu — drift & uHRTB versi master. */
class TabelStandarWaktuVersiMaster extends TabelStandarWaktu
{
    public function driftMs(bool $lengkap = true): float
    {
        return parent::driftMs(lengkap: false);
    }

    public function uHumanReactionRata(bool $lengkap = true): float
    {
        return parent::uHumanReactionRata(lengkap: false);
    }
}
