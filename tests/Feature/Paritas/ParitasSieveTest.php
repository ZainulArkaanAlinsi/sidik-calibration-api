<?php

namespace Tests\Feature\Paritas;

use App\Services\Calibration\SieveCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Sieve Mesh: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Fixture `tests/Fixtures/paritas/sieve.json` dibangun
 * `docs/skrip/gen-fixture-paritas-batch8.py` (target `sieve`) dari nilai-cache workbook
 * `Master Olah Data_Sieve Mesh.xlsm`: tiap nilai menyebut sel sumbernya, dan pesan galat
 * tiap banding menyebut sel itu (lihat [MenegakkanParitas]). Tidak ada nama/alamat
 * pelanggan, nomor sertifikat, atau nomor order di fixture.
 *
 * Bedanya dengan `Tests\Unit\SieveMasterTest`: tes itu menjaga rekonsiliasi dengan
 * toleransi 5e-6 dari fixture sesi lama; berkas ini mengadu SETIAP sel hulu-ke-hilir
 * pada 1e-11 (relatif), termasuk sel sertifikat yang dicetak, dan mencatat apa yang
 * SENGAJA beda atau belum diputuskan sebagai SELISIH / MENUNGGU bernomor.
 *
 * Satu pintu masuk produksi yang diadu: `SieveCalculator::hitungSesi()`, mesin di
 * belakang `SieveProfile::hitungPerGrup()` (fungsi yang sama dengan pratinjau, simpan,
 * dan hitung ulang). Sesi contoh: ASTM E11 19 mm, tipe Inspection, standar Digital
 * Caliper, 15 opening, suhu ruang 20,1 / 20,3 °C.
 *
 * ## SAMA
 *
 * Nominal dan baris Tabel MPE; rata-rata, STDEV, enam salinan pengulangan (opening 1..6)
 * dan STDEV-nya; enam komponen budget (nilai asal, pembagi, vi, ui, ci, ui*ci) di KETIGA
 * parameter; uc, veff, k (diketik 2 di master), U; lantai CMC; U yang dicetak; suhu rata;
 * koreksi kawat; vonis master (versi tanpa U) dan vonis simpangan baku; minimum opening
 * tipe Inspection.
 *
 * ## SELISIH (diberi nomor B8-n; tes sendiri, `#[Group('menunggu-keputusan-tm')]`)
 *
 *  - B8-3  koreksi standar. Master `VLOOKUP(indeks; Koreksi_Caliper; 3; 0)` membaca kolom
 *          yang KOSONG (`STANDAR_KALIBRATOR!M`), jadi koreksinya selalu 0. Aplikasi membaca
 *          kolom 4 (`N`, Koreksi): warp dan weft +0,01 mm (indeks 21 mm), kawat 0,0.
 *          Kerusakan salin-tempel: dihitung benar, selisihnya ditulis
 *          (`SieveCalculator` docblock butir 1; `docs/pertanyaan-lab-sieve.md`).
 *  - B8-4  minimum opening tipe Calibration. Master `INPUT DATA!F16` memakai kolom 6
 *          (Inspection) untuk tipe Calibration; aplikasi memakai kolom 7 (Calibration).
 *          Sel F16 hanya ter-cache untuk Inspection; nilai master untuk Calibration
 *          diturunkan dari teks rumus + sel tabel (15), bukan dari sel cache.
 *
 * ## MENUNGGU (diberi nomor M-n; belum diputuskan)
 *
 *  - M-1   status standar `INPUT DATA!U25` / `Z19` berbasis `NOW()`: cache menunjukkan
 *          "EXPIRED" (sisa hari pecahan, -111,40...) karena workbook terakhir dihitung
 *          ulang pada hari lain. Tidak dipakai sebagai acuan; aplikasi menilai standar
 *          pada TANGGAL KALIBRASI sesi.
 *  - M-2   aturan vonis. Master memvonis tanpa U dan dari rata-rata tanpa koreksi standar;
 *          aplikasi memakai guarded acceptance (|deviasi| + U <= Y, keputusan 14 Jul).
 *          Pada sesi contoh keduanya PASS di ketiga parameter, jadi data tidak membedakan;
 *          perilaku di ambang dijaga `SieveMasterTest`, bukan berkas ini.
 *
 * ## Batas bukti
 *
 * Satu sesi (19 mm, Inspection, Digital Caliper). Cabang Digital Microscope, tipe
 * Compliance/Calibration pada sesi penuh, satuan inch/µm, dan opening di atas 50 tidak
 * dibuktikan di sini. Angka tercetak diadu lewat sel `SERTIFIKAT`, bukan lewat PDF.
 * Jalur hitung ulang (`HitungUlangSesi`) disimpulkan dari satu mesin `hitungSesi`.
 */
#[Group('paritas')]
class ParitasSieveTest extends TestCase
{
    use MenegakkanParitas;

    private const PARAMETER = ['warp', 'weft', 'kawat'];

    /** Urutan komponen budget aplikasi = baris master. */
    private const KOMPONEN = [
        'ketidakpastian_standar', 'resolusi_standar', 'geometri', 'selisih_suhu', 'koefisien_muai', 'pengulangan',
    ];

    /** @return array<string, mixed> */
    private static function wb(): array
    {
        return self::fixtureParitas('sieve')['workbook'];
    }

    /**
     * @param  array<string, mixed>  $ubahKonteks
     * @return array<string, mixed>
     */
    private function hitung(array $ubahKonteks = []): array
    {
        $m = self::wb()['masukan'];
        $opening = ['warp' => [], 'weft' => [], 'kawat' => []];

        foreach ($m['opening'] as $o) {
            foreach (self::PARAMETER as $p) {
                $opening[$p][(int) $o['no']] = (float) $o[$p];
            }
        }

        return (new SieveCalculator)->hitungSesi($opening, [
            'tipe' => strtolower($m['tipe_teks']['nilai']),
            'nominal' => self::nilai($m['nominal']),
            'satuan' => $m['satuan_teks']['nilai'],
            'standar_dipakai' => str_contains($m['standar_teks']['nilai'], 'Caliper') ? 'caliper' : 'mikroskop',
            'jumlah_opening_total' => null,
            'suhu_ruang_rata_c' => (self::nilai($m['suhu_awal']) + self::nilai($m['suhu_akhir'])) / 2,
            'tanggal_kalibrasi' => new DateTimeImmutable($m['tanggal_kalibrasi']['nilai']),
            ...$ubahKonteks,
        ]);
    }

    /** @return array<string, mixed> */
    private static function excel(string $parameter): array
    {
        return self::wb()['excel']['parameter'][$parameter];
    }

    // ------------------------------------------------------------------ masukan

    public function test_masukan_fixture_lengkap_dan_suhu_rata_sama_master(): void
    {
        $m = self::wb()['masukan'];

        $this->assertCount(15, $m['opening']);
        $this->assertSame('Inspection', $m['tipe_teks']['nilai']);
        $this->assertSame('mm', $m['satuan_teks']['nilai']);
        $this->assertSame('Digital Caliper', $m['standar_teks']['nilai']);

        $rata = (self::nilai($m['suhu_awal']) + self::nilai($m['suhu_akhir'])) / 2;
        $this->assertSamaExcel($m['suhu_rata_PERHITUNGAN_G17'], $rata, 'suhu rata ruang');
    }

    public function test_sesi_contoh_boleh_terbit_dan_tanpa_penolakan(): void
    {
        $h = $this->hitung();

        $this->assertSame([], $h['ditolak']);
        $this->assertTrue($h['boleh_terbit']);
        $this->assertCount(3, $h['parameter']);
    }

    // ------------------------------------------------------------------ nominal, MPE, minimum

    public function test_nominal_dan_baris_tabel_mpe_sama_master(): void
    {
        $e = self::wb()['excel'];
        $h = $this->hitung();

        $this->assertSamaExcel($e['nominal_PERHITUNGAN_E7'], (float) $h['nominal_mm'], 'nominal sieve (mm)');
        $this->assertSamaExcel($e['kawat_nominal_PERHITUNGAN_E8'], (float) $h['parameter']['kawat']['nominal_parameter_mm'], 'Ø kawat preferred (mm)');

        $mpe = $e['tabel_mpe'];
        $this->assertSamaExcel($mpe['kolom9_y'], (float) $h['mpe']['y_mm'], 'MPE Y');
        $this->assertSamaExcel($mpe['kolom10_stdev_maks'], (float) $h['mpe']['max_stdev_mm'], 'stdev maksimum');
        $this->assertSamaExcel($mpe['kolom11_kawat_preferred'], (float) $h['mpe']['kawat_preferred_mm'], 'Ø kawat preferred (tabel)');
        $this->assertSamaExcel($mpe['kolom12_kawat_maks'], (float) $h['mpe']['kawat_max_mm'], 'Ø kawat maksimum');
        $this->assertSamaExcel($mpe['kolom13_kawat_min'], (float) $h['mpe']['kawat_min_mm'], 'Ø kawat minimum');
    }

    public function test_minimum_opening_inspection_dan_compliance_sama_tabel_master(): void
    {
        $e = self::wb()['excel'];

        $this->assertSamaExcel($e['minimum_opening']['F16'], (float) $this->hitung()['minimum_opening'], 'minimum opening Inspection (F16 ter-cache)');

        // Compliance: sel F16 tidak ter-cache untuk tipe ini; diadu ke sel tabel yang dipakai rumus F16 (kolom 5).
        $this->assertSamaExcel(
            $e['tabel_mpe']['kolom5_compliance'],
            (float) $this->hitung(['tipe' => 'compliance'])['minimum_opening'],
            'minimum opening Compliance (kolom 5 Tabel_MPE)',
        );
    }

    // ------------------------------------------------------------------ data dasar

    public function test_rata_rata_stdev_dan_pengulangan_sama_master(): void
    {
        $h = $this->hitung();

        foreach (self::PARAMETER as $p) {
            $e = self::excel($p);
            $a = $h['parameter'][$p];

            $this->assertSamaExcel($e['rata'], (float) $a['rata_rata'], "{$p} rata-rata");
            $this->assertSamaExcel($e['stdev'], (float) $a['simpangan_baku'], "{$p} STDEV");
            $this->assertSamaExcel($e['pengulangan_stdev'], (float) $a['simpangan_baku_pengulangan'], "{$p} STDEV pengulangan");
            $this->assertCount(6, $a['pengulangan']);

            foreach ($e['pengulangan']['sel'] as $i => $sel) {
                $this->assertSamaExcel(
                    ['sel' => $sel, 'nilai' => $e['pengulangan']['nilai'][$i]],
                    (float) $a['pengulangan'][$i],
                    "{$p} pengulangan ke-".($i + 1),
                );
            }
        }
    }

    // ------------------------------------------------------------------ budget

    public function test_enam_komponen_budget_ketiga_parameter_sama_master(): void
    {
        $h = $this->hitung();

        foreach (self::PARAMETER as $p) {
            $komponen = self::excel($p)['budget']['komponen'];
            $budget = $h['parameter'][$p]['budget'];
            $this->assertCount(6, $budget, "{$p}: enam komponen");

            foreach ($komponen as $i => $m) {
                $a = $budget[$i];
                $baris = $m['baris'];
                $this->assertSame(self::KOMPONEN[$i], $a['sumber'], "{$p}: urutan komponen baris {$baris}");

                $this->assertSamaExcel($m['N'], (float) $a['nilai_asal'], "{$p} {$a['sumber']} nilai asal");
                $this->assertSamaExcel($m['Q'], (float) $a['pembagi'], "{$p} {$a['sumber']} pembagi");
                $this->assertSamaExcel($m['S'], (float) $a['vi'], "{$p} {$a['sumber']} vi");
                $this->assertSamaExcel($m['U'], (float) $a['u'], "{$p} {$a['sumber']} ui");
                $this->assertSamaExcel($m['X'], (float) $a['ci'], "{$p} {$a['sumber']} ci");
                $this->assertSamaExcel($m['Z'], (float) $a['u'] * (float) $a['ci'], "{$p} {$a['sumber']} ui*ci");
            }
        }
    }

    public function test_uc_veff_k_u_dan_u_cetak_sama_master(): void
    {
        $h = $this->hitung();

        foreach (self::PARAMETER as $p) {
            $b = self::excel($p)['budget'];
            $a = $h['parameter'][$p];

            $this->assertSamaExcel($b['uc'], (float) $a['ketidakpastian_gabungan'], "{$p} uc");
            $this->assertSamaExcel($b['veff'], (float) $a['derajat_kebebasan_efektif'], "{$p} veff");
            $this->assertSamaExcel($b['U'], (float) $a['ketidakpastian_diperluas'], "{$p} U = k uc");

            // k diketik 2 di master (bukan TINV): sel konstanta, bukan rumus.
            $this->assertFalse($b['k']['rumus'], "{$p}: sel k master adalah ketikan, bukan rumus");
            $this->assertSamaExcel(['sel' => $b['k']['sel'], 'nilai' => $b['k']['nilai']], (float) $a['faktor_cakupan_k'], "{$p} k");

            // U yang dicetak = max(U; CMC) dalam mm (satuan sieve mm, faktor 1).
            $this->assertSamaExcel($b['U_cetak'], (float) $a['u95_sertifikat'], "{$p} U95 cetak (PERHITUNGAN U95%)");
            $this->assertSamaExcel(
                self::excel($p)['sertifikat']['N_U95_tercetak'],
                (float) $a['u95_sertifikat'],
                "{$p} U95 cetak (SERTIFIKAT)",
            );
        }

        $this->assertSamaExcel(self::wb()['excel']['k_cetak_SERTIFIKAT_O23'], (float) $h['parameter']['warp']['faktor_cakupan_k'], 'k yang dicetak');
    }

    public function test_lantai_cmc_sama_master(): void
    {
        $h = $this->hitung();
        $master = self::wb()['excel']['cmc'];

        $this->assertSamaExcel($master['DATABASE_T6'], (float) $h['lantai_cmc']['u95_mm'], 'lantai CMC caliper > 2 mm');

        foreach (self::PARAMETER as $p) {
            $this->assertSamaExcel(self::excel($p)['budget']['cmc'], (float) $h['lantai_cmc']['u95_mm'], "{$p} CMC (sel master)");
        }
    }

    // ------------------------------------------------------------------ sertifikat

    public function test_kawat_terkoreksi_dan_koreksi_tercetak_sama_master(): void
    {
        $a = $this->hitung()['parameter']['kawat'];
        $s = self::excel('kawat')['sertifikat'];

        // Koreksi standar kawat di indeks 2,5 mm = 0 di kedua sisi, jadi tidak ada selisih B8-3 di sini.
        $this->assertSame(0.0, (float) $a['koreksi_standar']);
        $this->assertSamaExcel($s['H_nilai_tercetak'], (float) $a['terkoreksi'], 'kawat nilai tercetak (H22)');
        $this->assertSamaExcel($s['K_koreksi_tercetak'], (float) $a['deviasi'], 'kawat koreksi tercetak (K22 = H22 - E22)');
        $this->assertSamaExcel($s['G_stdev'], (float) $a['simpangan_baku'], 'kawat STDEV tercetak (G28)');
    }

    public function test_titik_koreksi_dipilih_sama_dengan_indeks_master(): void
    {
        $h = $this->hitung();

        foreach (self::PARAMETER as $p) {
            $k = self::excel($p)['koreksi'];
            $this->assertSamaExcel($k['nilai_standar'], (float) $h['parameter'][$p]['titik_koreksi']['nilai_standar_mm'], "{$p} titik koreksi");
            $this->assertSamaExcel($k['indeks'], (float) $h['parameter'][$p]['titik_koreksi']['nilai_standar_mm'], "{$p} indeks master (ketikan)");
            $this->assertFalse($k['indeks']['rumus'], "{$p}: indeks master diketik tangan, bukan hasil pencarian");
        }
    }

    public function test_vonis_master_dan_batas_sama_master(): void
    {
        $h = $this->hitung();

        foreach (['warp', 'weft'] as $p) {
            $s = self::excel($p)['sertifikat'];
            $a = $h['parameter'][$p];

            $this->assertSame('PASS', $s['AE_vonis']['nilai']);
            $this->assertTrue($a['vonis']['lulus_master'], "{$p}: vonis master (versi tanpa U) = {$s['AE_vonis']['sel']}");
            $this->assertSamaExcel($s['AD_batas_y'], (float) $a['vonis']['batas']['y_mm'], "{$p} batas Y");

            $this->assertSame('PASS', $s['stdev_AE_vonis']['nilai']);
            $this->assertTrue($a['stdev']['lulus'], "{$p}: vonis simpangan baku = {$s['stdev_AE_vonis']['sel']}");
            $this->assertSamaExcel($s['stdev_AD_batas'], (float) $a['stdev']['max_mm'], "{$p} batas simpangan baku");
            $this->assertSamaExcel($s['stdev_AB'], (float) $a['simpangan_baku'], "{$p} simpangan baku di baris vonis");
        }

        $s = self::excel('kawat')['sertifikat'];
        $a = $h['parameter']['kawat'];
        $this->assertSame('PASS', $s['AE_vonis']['nilai']);
        $this->assertTrue($a['vonis']['lulus_master'], 'kawat: vonis master '.$s['AE_vonis']['sel']);
        $this->assertSamaExcel($s['AD_min'], (float) $a['vonis']['batas']['min_mm'], 'kawat batas minimum');
        $this->assertSamaExcel($s['AD_maks'], (float) $a['vonis']['batas']['max_mm'], 'kawat batas maksimum');
    }

    /**
     * M-2: pada sesi contoh aturan guarded acceptance aplikasi dan vonis tanpa U master
     * sama-sama PASS di ketiga parameter. Selisih ATURANNYA nyata (docblock kelas) tapi
     * tidak teramati pada data ini, jadi tidak dihitung sebagai SELISIH.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_m2_aturan_vonis_beda_tapi_hasil_sama_pada_sesi_contoh(): void
    {
        $h = $this->hitung();

        foreach (self::PARAMETER as $p) {
            $a = $h['parameter'][$p];
            $this->assertSame('PASS', $a['keputusan'], "{$p}: keputusan guarded acceptance");
            $this->assertSame('PASS', self::excel($p)['sertifikat']['AE_vonis']['nilai'], "{$p}: vonis master");
        }
    }

    // ------------------------------------------------------------------ B8-3 koreksi standar

    /**
     * B8-3. Master membaca kolom 3 yang kosong (koreksi = 0); aplikasi membaca kolom 4.
     * Kedua sisi dikunci apa adanya; tidak ada yang disetel agar cocok.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_3_koreksi_standar_master_membaca_kolom_kosong_aplikasi_kolom_koreksi(): void
    {
        $h = $this->hitung();

        foreach (['warp', 'weft'] as $p) {
            $e = self::excel($p);
            $k = $e['koreksi'];
            $a = $h['parameter'][$p];

            $this->assertNull($k['kolom3_dibaca_master']['nilai'], "{$p}: master membaca sel kosong {$k['kolom3_dibaca_master']['sel']}");
            $this->assertSame('=K'.$k['baris_tabel'].'-L'.$k['baris_tabel'], $k['kolom4_rumus'], "{$p}: kolom 4 berumus nilai standar - penunjukan");
            $this->assertSamaExcel($e['rata'], (float) $e['koreksi']['terkoreksi_master']['nilai'], "{$p} master: terkoreksi = rata-rata (koreksi 0)");

            // Aplikasi: koreksi dari kolom 4; nilai tercetak = master + koreksi itu.
            $this->assertSamaExcel($k['kolom4_koreksi'], (float) $a['koreksi_standar'], "{$p} koreksi standar (kolom 4)");
            $this->assertEqualsWithDelta(0.01, (float) $a['koreksi_standar'], 1e-12);
            $this->assertSamaHitungUlang(
                self::nilai($k['terkoreksi_master']) + self::nilai($k['kolom4_koreksi']),
                (float) $a['terkoreksi'],
                "{$p} terkoreksi aplikasi = master + kolom 4",
            );

            // Akibat pada angka cetak: nilai tercetak dan koreksi tercetak bergeser sebesar koreksi itu.
            $s = $e['sertifikat'];
            $this->assertSamaHitungUlang(self::nilai($s['H_nilai_tercetak']) + 0.01, (float) $a['terkoreksi'], "{$p} H tercetak + 0,01");
            $this->assertSamaHitungUlang(self::nilai($s['K_koreksi_tercetak']) + 0.01, (float) $a['deviasi'], "{$p} K tercetak + 0,01");
            $this->assertGreaterThan(1e-3, abs((float) $a['deviasi'] - self::nilai($s['K_koreksi_tercetak'])), "{$p}: selisih nyata");

            // Budget tidak tersentuh selisih ini (tidak ada komponen yang memakai nilai terkoreksi).
            $this->assertSamaExcel($e['budget']['uc'], (float) $a['ketidakpastian_gabungan'], "{$p} uc tetap sama");
        }
    }

    // ------------------------------------------------------------------ B8-4 minimum opening Calibration

    /**
     * B8-4. Rumus F16 master memakai kolom 6 untuk tipe Inspection DAN Calibration;
     * aplikasi memakai kolom 7 untuk Calibration. Nilai master untuk Calibration
     * diturunkan dari teks rumus + sel tabel; sel F16 sendiri hanya ter-cache untuk Inspection.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b8_4_minimum_opening_calibration_master_kolom_inspection_aplikasi_kolom_calibration(): void
    {
        $e = self::wb()['excel'];
        $rumus = $e['minimum_opening']['rumus_F16'];

        $this->assertMatchesRegularExpression(
            '/Z14="Inspection",VLOOKUP\(Z16,Tabel_MPE,6,0\)/',
            $rumus,
            'F16: cabang Inspection memakai kolom 6',
        );
        $this->assertMatchesRegularExpression(
            '/Z14="Calibration",VLOOKUP\(Z16,Tabel_MPE,6,0\)/',
            $rumus,
            'F16: cabang Calibration JUGA memakai kolom 6 (salin-tempel)',
        );

        $masterCalibration = self::nilai($e['tabel_mpe']['kolom6_inspection']);
        $kolomCalibration = self::nilai($e['tabel_mpe']['kolom7_calibration']);
        $aplikasi = (float) $this->hitung(['tipe' => 'calibration'])['minimum_opening'];

        $this->assertSamaHitungUlang(15.0, $masterCalibration, 'master: Calibration terbaca 15 (kolom 6)');
        $this->assertSamaExcel($e['tabel_mpe']['kolom7_calibration'], $aplikasi, 'aplikasi: Calibration = kolom 7 Tabel_MPE');
        $this->assertGreaterThan($masterCalibration, $kolomCalibration, 'ini selisih nyata: 30 lawan 15');
        $this->assertSame(30.0, $aplikasi);
    }

    // ------------------------------------------------------------------ M-1 NOW()

    /**
     * M-1. Status standar master memakai `NOW()`: sisa hari pecahan, status "EXPIRED"
     * di cache. Aplikasi menilai pada tanggal kalibrasi, jadi sesi contoh tetap sah.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_m1_status_standar_master_bergantung_now_aplikasi_pada_tanggal_kalibrasi(): void
    {
        $st = self::wb()['excel']['status_standar'];

        $this->assertTrue($st['sisa_hari_Z19']['rumus']);
        $this->assertSame('=Y13-$X$11', $st['tabel_standar_DB_Z13_rumus'], 'sisa hari = jatuh tempo - sel waktu berjalan');
        $sisa = self::nilai($st['sisa_hari_Z19']);
        $this->assertNotSame(0.0, fmod($sisa, 1.0), 'sisa hari pecahan = bergantung pada jam hitung ulang (NOW)');
        $this->assertSame('EXPIRED', $st['status_U25']['teks'], 'cache workbook: status kedaluwarsa pada hari ia dihitung ulang');

        $this->assertTrue($this->hitung()['boleh_terbit'], 'aplikasi: sah pada tanggal kalibrasi sesi');
    }
}
