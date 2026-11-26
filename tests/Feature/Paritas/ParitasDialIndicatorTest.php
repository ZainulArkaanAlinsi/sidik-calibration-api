<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\Calibration\Profiles\DialIndicatorProfile;
use App\Services\Calibration\TabelStandarDialIndicator;
use App\Services\GumCalculator;
use App\Support\DialIndicatorMentah;
use App\Support\TataLetakSertifikat;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Dial Indicator: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Fixture `tests/Fixtures/paritas/dial-indicator.json` dibangun
 * `docs/skrip/gen-fixture-paritas-batch5.py` dari nilai-cache workbook
 * `Master Olah Data_Dial Indicator.xlsm` (sel + nilai, tidak disunting tangan;
 * hash berkas dan catatan pembukaan ada di fixture, bagian `sumber`).
 * Pesan galat tiap banding menyebut sel workbook, jadi tes merah menunjuk
 * ke sel yang menyimpang (lihat [MenegakkanParitas]).
 *
 * Dua pintu masuk produksi yang diadu, sama seperti Micrometer:
 *
 *  - `DialIndicatorCalculator::hitungSesi()` / `budget()` (titik panggil terkecil);
 *  - `DialIndicatorProfile::hitungPerGrup()` (fungsi yang SAMA dengan preview,
 *    simpan, dan `HitungUlangSesi`).
 *
 * ## Acuan angka dan jebakan `NOW()`
 *
 * Komponen drift Excel (`PERHITUNGAN U95%!K10`) memakai `DATABASE!X11 = NOW()`,
 * jadi nilai-cache U95 tumbuh tiap workbook dihitung ulang. Acuan yang sah
 * hanya nilai-cache pada saat X11 itu tersimpan: aplikasi diberi tanggal sama
 * dengan X11 (termasuk pecahan detik), sehingga umur drift identik.
 *
 * ## Selisih yang SENGAJA ada di aplikasi (diberi nomor P-30 dan seterusnya)
 *
 * Penyimpangan ini sudah tertulis di `DialIndicatorCalculator`; tes di sini
 * tidak meratakannya dan tidak mengubah kode produksi agar cocok. Tiap selisih
 * dikunci sebagai tes tersendiri (`#[Group('menunggu-keputusan-tm')]`) berikut
 * nilai kedua sisi, ditambah satu tes penutup yang membuktikan bahwa KETIGA
 * selisih itu menjelaskan seluruh beda U95 aplikasi lawan Excel:
 *
 *  - P-30  derajat bebas pengulangan: aplikasi 9 (sepuluh bacaan Evaluation), Excel `Q5` = 4.
 *  - P-31  pembagi umur drift: aplikasi /365, Excel `K10` /12.
 *  - P-32  panjang untuk ci termal: aplikasi total nominal terbesar, Excel `C61`.
 *  - P-33  komponen ke-11 (histeresis): ada di aplikasi, tidak ada di Excel; bernilai 0 di data ini.
 *
 * ## Batas bukti (jangan dibaca lebih dari ini)
 *
 *  - Sepuluh bacaan blok Evaluation di workbook ini IDENTIK (25,01 x10), jadi STDEV
 *    dan komponen keterulangan bernilai nol di kedua sisi. Rumus keterulangan TIDAK
 *    terbukti oleh data ini; yang terbukti hanya bahwa kedua sisi sama-sama nol.
 *  - Kelima bacaan tiap titik identik, jadi simpangan baku titik nol di kedua sisi.
 *  - Satu sesi (satu pita, 0-25 mm); pita 50/100/300 hanya diadu pada nilai CMC-nya.
 *  - MENUNGGU: suhu yang tercetak di lembar `SERTIFIKAT!O12` = 20,59 sedangkan suhu
 *    yang dipakai hitungan `INPUT DATA!H21` = 20,75. Asal beda ini belum ditelusuri.
 */
#[Group('paritas')]
class ParitasDialIndicatorTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Sepuluh komponen: urutan sheet `PERHITUNGAN U95%` baris 5-14. */
    private const KOMPONEN = [
        'pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
        'drift_standar', 'wringing', 'tegak_lurus', 'meja_granit', 'selisih_suhu',
    ];

    /** Komponen yang `ci`-nya bergantung panjang sensitivitas (`C61` lawan total nominal terbesar). */
    private const TERMAL_BERGANTUNG_PANJANG = ['suhu_ruang', 'koefisien_muai', 'selisih_suhu'];

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('dial-indicator')['workbook'];
    }

    /**
     * @param  array{sel: mixed, nilai: list<float|int>}  $sel
     * @return list<float>
     */
    private static function daftar(array $sel): array
    {
        return array_map('floatval', $sel['nilai']);
    }

    /** Satu sel dari sel-daftar (`sel` dan `nilai` sama-sama berupa daftar). */
    private static function unsur(array $sel, int $i): array
    {
        return ['sel' => $sel['sel'][$i], 'nilai' => $sel['nilai'][$i]];
    }

    /** `DATABASE!X11` tersimpan, lengkap dengan pecahan detik (aplikasi memakainya). */
    private function x11(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::w()['excel']['drift']['x11_now_cache']['nilai']);
    }

    /**
     * Susun masukan persis seperti jalur produksi: blok `spesifikasi_alat` lewat
     * `DialIndicatorMentah::blokSesi`, pembacaan lewat `keMm`. Kapasitas dan
     * resolusi masuk sebagai nilai pasca-konversi workbook (`PERHITUNGAN!G8`, `G9`).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function masukan(DateTimeImmutable $tanggal): array
    {
        $w = self::w();
        $m = $w['masukan'];
        $dasar = $w['excel']['dasar'];

        $blok = DialIndicatorMentah::blokSesi([DialIndicatorMentah::KUNCI_SESI => [
            'satuan' => 'mm',
            'kapasitas_mm' => self::nilai($dasar['kapasitas_perhitungan_mm']),
            'resolusi_mm' => self::nilai($dasar['resolusi_perhitungan_mm']),
            'pra_evaluasi' => self::daftar($m['pra_evaluasi_mm']),
            'balok_pra_evaluasi' => self::daftar($m['balok_pra_evaluasi_mm']),
        ]]);

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'keping' => self::daftar($t['keping_mm']),
            'pembacaan' => array_map(
                static fn (float $x): float => DialIndicatorMentah::keMm($x, 'mm'),
                self::daftar($t['pembacaan_mm']),
            ),
        ], $m['titik']);

        return [$titik, $blok + [
            'tanggal_kalibrasi' => $tanggal,
            'suhu_ruang_rata_c' => DialIndicatorMentah::rataSuhuRuang(
                self::nilai($m['suhu_ruang_awal_c']),
                self::nilai($m['suhu_ruang_akhir_c']),
            ),
        ]];
    }

    /** @return array<string, mixed> */
    private function hitung(DateTimeImmutable $tanggal): array
    {
        [$titik, $konteks] = $this->masukan($tanggal);

        return (new DialIndicatorCalculator)->hitungSesi($titik, $konteks);
    }

    /**
     * ui, ci, vi tiap komponen dari sel Excel (urutan baris 5-14), sebagian bisa diganti.
     *
     * @param  array<string, array<string, float>>  $ganti  sumber => ['u'|'ci'|'vi' => nilai]
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponenExcel(array $ganti = []): array
    {
        $hasil = [];

        foreach (self::KOMPONEN as $sumber) {
            $x = self::w()['excel']['budget']['komponen'][$sumber];
            $hasil[$sumber] = ['u' => self::nilai($x['ui']), 'ci' => self::nilai($x['ci']), 'vi' => self::nilai($x['vi'])];

            foreach ($ganti[$sumber] ?? [] as $kunci => $nilai) {
                $hasil[$sumber][$kunci] = $nilai;
            }
        }

        return array_values($hasil);
    }

    /** @return array<string, mixed> */
    private function agregat(array $komponen): array
    {
        return (new GumCalculator)->agregasiBudget($komponen);
    }

    /** Komponen aplikasi pada tanggal X11, dikunci per nama. */
    private function budgetAplikasi(): Collection
    {
        return collect($this->hitung($this->x11())['budget'])->keyBy('sumber');
    }

    // ------------------------------------------------------------------ tahap masukan

    public function test_suhu_pra_evaluasi_dan_ketidakpastian_balok_cocok_excel(): void
    {
        $w = self::w();
        $dasar = $w['excel']['dasar'];
        [, $konteks] = $this->masukan($this->x11());
        $kalk = new DialIndicatorCalculator;
        $tabel = new TabelStandarDialIndicator;

        $this->assertCount(10, $konteks['pra_evaluasi'], 'sepuluh pembacaan blok Evaluation');
        $this->assertSamaExcel($w['masukan']['suhu_ruang_rata_c'], $konteks['suhu_ruang_rata_c'], 'suhu ruang rata-rata (°C)');
        $this->assertSamaExcel($dasar['pengulangan_stdev_mm'], $kalk->simpanganBaku($konteks['pra_evaluasi']), 'STDEV pra-evaluasi (mm; data identik, keduanya nol)');

        $this->assertCount(2, $konteks['balok_pra_evaluasi']);
        foreach ($konteks['balok_pra_evaluasi'] as $i => $nominal) {
            $this->assertSamaExcel(self::unsur($dasar['balok_evaluasi_nominal_mm'], $i), $nominal, "balok Evaluation ke-{$i} (mm)");
            $this->assertSamaExcel(self::unsur($dasar['balok_evaluasi_u_um'], $i), $tabel->ketidakpastianKeping($nominal), "u balok {$nominal} mm (µm)");
        }

        $this->assertSamaExcel(
            $dasar['u_standar_um'],
            sqrt(array_sum(array_map(static fn (float $n): float => $tabel->ketidakpastianKeping($n) ** 2, $konteks['balok_pra_evaluasi']))),
            'u standar balok (µm)',
        );
        $this->assertSamaExcel(
            $dasar['u_standar_mm'],
            sqrt(array_sum(array_map(static fn (float $n): float => $tabel->ketidakpastianKeping($n) ** 2, $konteks['balok_pra_evaluasi']))) / 1000,
            'u standar balok (mm)',
        );
    }

    public function test_konstanta_tabel_tanggal_standar_dan_pita_cmc_cocok_sel_workbook(): void
    {
        $w = self::w();
        $dasar = $w['excel']['dasar'];
        $tabel = new TabelStandarDialIndicator;
        $k = $tabel->konstanta();

        $this->assertSamaExcel($dasar['delta_alpha_per_c'], (float) $k['delta_alpha_per_c'], 'delta alpha');
        $this->assertSamaExcel($dasar['u_alpha_per_c'], (float) $k['delta_alpha_per_c'] * (float) $k['u_alpha_pengali'], 'u alpha');
        $this->assertSamaExcel($dasar['alpha_balok_per_c'], (float) $k['alpha_per_c'], 'alpha balok');
        $this->assertSamaExcel($dasar['alpha_uut_per_c'], (float) $k['alpha_per_c'], 'alpha UUT');

        // Umur drift dihitung terhadap tanggal kalibrasi balok standar yang tersimpan di tabel.
        $this->assertSame(
            substr($w['excel']['drift']['w13_tanggal_kalibrasi_balok']['nilai'], 0, 10),
            $tabel->standar()['tanggal_kalibrasi'],
            'tanggal kalibrasi balok standar tabel vs '.$w['excel']['drift']['w13_tanggal_kalibrasi_balok']['sel'],
        );

        // Lantai CMC keempat pita (`DATABASE!T5:T8`, µm) lawan pita tabel (mm).
        $cmc = $w['excel']['cmc']['u95_um_per_pita'];
        foreach ([25.0, 50.0, 100.0, 300.0] as $i => $kapasitas) {
            $pita = $tabel->pitaCmc($kapasitas);
            $this->assertNotNull($pita, "pita CMC untuk kapasitas {$kapasitas} mm");
            $this->assertSamaExcel(self::unsur($cmc, $i), (float) $pita['u95_mm'] * 1000, "CMC pita 0-{$kapasitas} mm (µm)");
        }
    }

    // ------------------------------------------------------------------ tahap titik

    public function test_titik_keping_total_nominal_rata_rata_standar_koreksi_dan_stdev_cocok_excel(): void
    {
        $w = self::w();
        $h = $this->hitung($this->x11());
        $tabel = new TabelStandarDialIndicator;
        $app = collect($h['titik'])->keyBy('titik_ke');

        $this->assertSame([], $h['ditolak'], 'sesi master tidak boleh melahirkan penolakan');
        $this->assertCount(count($w['excel']['titik']), $h['titik']);

        foreach ($w['excel']['titik'] as $t) {
            $ke = "titik {$t['titik_ke']}";
            $baris = $app[$t['titik_ke']] ?? null;
            $this->assertNotNull($baris, "{$ke} tidak terhitung di aplikasi");

            // Nilai terkoreksi tiap keping balok ukur (`PERHITUNGAN!F`), satu per satu.
            $nominal = self::daftar($t['keping_nominal_mm']);
            $this->assertCount((int) self::nilai($t['jumlah_keping']), $nominal, "{$ke}: jumlah keping");
            foreach ($nominal as $j => $n) {
                $this->assertSamaExcel(self::unsur($t['keping_terkoreksi_mm'], $j), (float) $tabel->nilaiKeping($n), "{$ke}: keping {$n} mm terkoreksi");
            }

            $this->assertSamaExcel($t['total_nominal_mm'], $baris['total_nominal'], "{$ke}: total nominal terkoreksi (mm)");
            $this->assertSamaExcel($t['standar_terkoreksi_mm'], $baris['standar_terkoreksi'], "{$ke}: standar terkoreksi (mm)");
            $this->assertSamaExcel($t['rata_rata_mm'], $baris['rata_rata'], "{$ke}: rata-rata pembacaan (mm)");
            $this->assertSamaExcel($t['koreksi_mm'], $baris['koreksi'], "{$ke}: koreksi (mm)");
            $this->assertSamaExcel($t['simpangan_baku_mm'], $baris['simpangan_baku'], "{$ke}: STDEV (mm)");

            $excelBacaan = self::daftar($t['pembacaan_mm']);
            $this->assertSame(count($excelBacaan), $baris['jumlah_pengulangan'], "{$ke}: jumlah pengulangan");
            foreach ($excelBacaan as $j => $x) {
                $this->assertSamaExcel(self::unsur($t['pembacaan_mm'], $j), $baris['pembacaan'][$j], "{$ke}: pembacaan ke-{$j} (mm)");
            }
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /**
     * Sepuluh komponen dengan DASAR yang sama dengan Excel (panjang `C61`, keping
     * terbanyak `B61`, theta `Q31`, delta theta `X31`, bacaan per titik 5): ui,
     * ci, uici, dan vi. Tiga hal sengaja TIDAK diadu di sini karena merupakan
     * selisih bernomor: vi pengulangan (P-30), ui drift (P-31). Panjang
     * (P-32) dihilangkan dengan memberi `budget()` panjang Excel; aplikasi
     * memilih panjangnya sendiri di `hitungSesi()`, dan itu diadu di tes P-32.
     */
    public function test_sepuluh_komponen_budget_cocok_excel_pada_dasar_yang_sama(): void
    {
        $w = self::w();
        $e = $w['excel'];
        $dasar = $e['dasar'];
        [, $konteks] = $this->masukan($this->x11());
        $bacaan = count($w['masukan']['titik'][0]['pembacaan_mm']['nilai']);

        // Pembagi keterulangan master = akar jumlah bacaan per titik (N5 = sqrt(5)).
        $this->assertSamaExcel($e['budget']['komponen']['pengulangan']['pembagi'], sqrt((float) $bacaan), 'pembagi pengulangan = akar bacaan per titik');

        $ditolak = [];
        $budget = collect((new DialIndicatorCalculator)->budget(
            [...$konteks, 'bacaan_per_titik' => $bacaan, 'histeresis_mm' => 0.0],
            self::nilai($dasar['panjang_sensitivitas_mm']),
            (int) self::nilai($dasar['jumlah_keping_maks']),
            self::nilai($dasar['theta_c']),
            self::nilai($dasar['delta_theta_c']),
            $ditolak,
        ))->keyBy('sumber');

        $this->assertSame([], $ditolak);

        foreach (self::KOMPONEN as $sumber) {
            $x = $e['budget']['komponen'][$sumber];
            $b = $budget[$sumber];

            $this->assertSamaExcel($x['ci'], $b['ci'], "{$sumber}: ci");

            if ($sumber !== 'pengulangan') {
                $this->assertSamaExcel($x['vi'], $b['vi'], "{$sumber}: vi");
            }

            if ($sumber === 'drift_standar') {
                continue;
            }

            $this->assertSamaExcel($x['ui'], $b['u'], "{$sumber}: ui = u/pembagi");
            $this->assertSamaExcel($x['uici'], $b['u'] * $b['ci'], "{$sumber}: ui*ci");
        }
    }

    /** Umur drift aplikasi pada X11 sama dengan (X11 - W13) hari yang tersimpan di fixture. */
    public function test_umur_standar_pada_x11_cocok_hitung_ulang_cache_excel(): void
    {
        $drift = self::w()['excel']['drift'];

        $this->assertSamaHitungUlang(
            (float) $drift['umur_hari_dari_cache'],
            (new DialIndicatorCalculator)->umurStandarHari($this->x11()),
            'umur standar (hari) = '.$drift['x11_now_cache']['sel'].' - '.$drift['w13_tanggal_kalibrasi_balok']['sel'],
        );
    }

    // ------------------------------------------------------------------ tahap agregat

    /**
     * uc, veff, k, U95, dan lantai CMC: agregasi aplikasi (`GumCalculator`) diberi
     * ui, ci, vi persis dari sel Excel. Terpisah dari selisih komponen: yang diadu
     * di sini hanya rantai agregasi `PERHITUNGAN U95%!AA15:AA21`.
     */
    public function test_agregasi_uc_veff_k_u95_dan_lantai_cmc_cocok_excel_dari_komponen_excel(): void
    {
        $e = self::w()['excel'];
        $ag = $e['budget']['agregat'];
        $a = $this->agregat($this->komponenExcel());
        $pita = (new TabelStandarDialIndicator)->pitaCmc(self::nilai($e['dasar']['kapasitas_perhitungan_mm']));

        $this->assertNotNull($pita, 'pita CMC kapasitas sesi');
        $this->assertSamaExcel($ag['jumlah_uici_kuadrat'], $a['ketidakpastian_gabungan'] ** 2, 'jumlah (ui*ci)^2');
        $this->assertSamaExcel($ag['uc'], $a['ketidakpastian_gabungan'], 'uc');
        $this->assertSamaExcel($ag['veff'], (float) $a['derajat_kebebasan_efektif'], 'v_eff');
        $this->assertKSamaExcel($ag['k'], $a['faktor_cakupan_k'], 'k');
        $this->assertUSamaExcel($ag['u_hitung'], $a['ketidakpastian_diperluas'], 'U95 sebelum lantai CMC (mm)');
        $this->assertSamaExcel($ag['cmc'], (float) $pita['u95_mm'], 'CMC pita (mm)');
        $this->assertUSamaExcel($ag['u_cetak'], max($a['ketidakpastian_diperluas'], (float) $pita['u95_mm']), 'U95 sertifikat = max(U95; CMC) (mm)');
    }

    // ------------------------------------------------------------------ selisih bernomor (menunggu keputusan TM)

    /**
     * SELISIH P-30. Derajat bebas komponen keterulangan: aplikasi = banyak bacaan
     * Evaluation - 1 = 9 (`DialIndicatorCalculator::budget`); Excel `Q5` = 4.
     * Pada data ini komponennya nol di kedua sisi, jadi v_eff dan k TIDAK berubah
     * (dikunci di bawah); pada sesi dengan sebaran nyata, pengaruhnya belum terbukti.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p30_vi_pengulangan_aplikasi_9_excel_4(): void
    {
        $x = self::w()['excel']['budget']['komponen']['pengulangan'];
        $b = $this->budgetAplikasi()['pengulangan'];

        $this->assertSame(4.0, self::nilai($x['vi']), 'Excel '.$x['vi']['sel']);
        $this->assertSame(9.0, $b['vi'], 'aplikasi: 10 bacaan Evaluation - 1');

        // Pengaruh pada agregat, dengan ui dan ci Excel: tidak ada pada data ini.
        $dengan4 = $this->agregat($this->komponenExcel(['pengulangan' => ['vi' => 4.0]]));
        $dengan9 = $this->agregat($this->komponenExcel(['pengulangan' => ['vi' => 9.0]]));
        $this->assertSamaHitungUlang((float) $dengan4['derajat_kebebasan_efektif'], (float) $dengan9['derajat_kebebasan_efektif'], 'v_eff vi=4 vs vi=9');
        $this->assertSame($dengan4['faktor_cakupan_k'], $dengan9['faktor_cakupan_k'], 'k vi=4 vs vi=9');
    }

    /**
     * SELISIH P-31. Umur drift: aplikasi membagi (X11 - W13) hari dengan 365 (komponen
     * bersatuan per tahun); master `K10` membaginya 12. Komponen drift aplikasi
     * = K10 x 12/365 (paket keputusan 16 Sep 2026, paraf Manajer Teknis menyusul).
     * Akibat pada U95 dengan komponen lain tetap dari Excel: tercetak 0,0086 lawan 0,0088.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p31_drift_pembagi_365_aplikasi_12_excel(): void
    {
        $e = self::w()['excel'];
        $x = $e['budget']['komponen']['drift_standar'];
        $k10 = self::nilai($e['drift']['k10_cache']);
        $b = $this->budgetAplikasi()['drift_standar'];

        $this->assertSame(12, $e['drift']['pembagi_master'], 'pembagi master pada K10');
        $this->assertSamaHitungUlang($k10 * 12.0 / 365.0 / sqrt(3.0), $b['u'], 'drift ui aplikasi = K10 x 12/365 / sqrt(3)');
        $this->assertSamaHitungUlang(365.0 / 12.0, self::nilai($x['ui']) / $b['u'], 'rasio ui drift Excel/aplikasi = 365/12');

        $hanyaDrift = $this->agregat($this->komponenExcel(['drift_standar' => ['u' => $b['u']]]));
        $this->assertLessThan(self::nilai($e['budget']['agregat']['u_hitung']), $hanyaDrift['ketidakpastian_diperluas'], 'U lebih kecil dari Excel (arah tidak aman)');
        $this->assertSame($e['cetak']['u95']['teks'], '0.0088', 'cetak Excel '.$e['cetak']['u95']['sel']);
        $this->assertSame('0.0086', self::keTitik(TataLetakSertifikat::formatAngka($hanyaDrift['ketidakpastian_diperluas'], '0.0000', false)));
    }

    /**
     * SELISIH P-32. Panjang untuk ci termal: Excel `C61` = 21 (satu nilai panjang);
     * aplikasi memakai total nominal terbesar sesi (titik 10: 24,50011 mm). Arah
     * aman: ci termal aplikasi lebih besar, sebanding persis dengan rasio panjang.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p32_panjang_sensitivitas_total_nominal_terbesar_lawan_c61(): void
    {
        $e = self::w()['excel'];
        $c61 = self::nilai($e['dasar']['panjang_sensitivitas_mm']);
        $h = $this->hitung($this->x11());
        $budget = collect($h['budget'])->keyBy('sumber');

        $terbesar = max(array_map(static fn (array $t): float => self::nilai($t['total_nominal_mm']), $e['titik']));
        $this->assertSamaHitungUlang($terbesar, $h['l_maks_mm'], 'panjang aplikasi = total nominal terbesar (mm)');
        $this->assertGreaterThan($c61, $h['l_maks_mm'], 'panjang aplikasi lebih besar dari Excel '.$e['dasar']['panjang_sensitivitas_mm']['sel']);

        $ganti = [];
        foreach (self::TERMAL_BERGANTUNG_PANJANG as $sumber) {
            $ciExcel = self::nilai($e['budget']['komponen'][$sumber]['ci']);
            $this->assertSamaHitungUlang($ciExcel * $h['l_maks_mm'] / $c61, $budget[$sumber]['ci'], "{$sumber}: ci = ci Excel x panjang aplikasi / C61");
            $ganti[$sumber] = ['ci' => $budget[$sumber]['ci']];
        }

        $hanyaPanjang = $this->agregat($this->komponenExcel($ganti));
        $this->assertGreaterThan(
            self::nilai($e['budget']['agregat']['u_hitung']),
            $hanyaPanjang['ketidakpastian_diperluas'],
            'U dengan ci termal aplikasi lebih besar dari Excel (arah aman)',
        );
    }

    /**
     * SELISIH P-33. Aplikasi menambah komponen ke-11 `histeresis` (syarat butir 6
     * paket keputusan 16 Sep 2026); Excel hanya sepuluh. Lima bacaan per titik
     * (ganjil) tidak berpasangan UP/DOWN, jadi nilainya 0 dan agregat tidak berubah.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p33_komponen_histeresis_ke_11_bernilai_nol_di_data_ini(): void
    {
        $e = self::w()['excel'];
        $budget = $this->hitung($this->x11())['budget'];

        $this->assertCount(10, $e['budget']['komponen'], 'Excel: sepuluh komponen');
        $this->assertCount(11, $budget, 'aplikasi: sepuluh komponen master + histeresis');
        $this->assertSame('histeresis', $budget[10]['sumber']);
        $this->assertSame(0.0, $budget[10]['u'], 'histeresis 0 pada lima bacaan per titik');

        $tanpa = $this->agregat(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            array_slice($budget, 0, 10),
        ));
        $dengan = $this->agregat(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            $budget,
        ));
        $this->assertSame($tanpa['ketidakpastian_gabungan'], $dengan['ketidakpastian_gabungan']);
        $this->assertSame($tanpa['faktor_cakupan_k'], $dengan['faktor_cakupan_k']);
    }

    /**
     * Rantai penuh `hitungSesi()` pada X11: U95 aplikasi di bawah Excel, di atas
     * lantai CMC, dan selisihnya HABIS dijelaskan P-30, P-31, P-32 (+P-33 nol):
     * agregasi dari komponen Excel yang hanya diganti pada tiga hal itu sama
     * dengan hasil aplikasi. Kalau tes ini merah sementara tiga tes di atas hijau,
     * ada selisih keempat yang belum bernomor.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_p34_rantai_penuh_u95_aplikasi_dijelaskan_oleh_p30_p31_p32(): void
    {
        $e = self::w()['excel'];
        $ag = $e['budget']['agregat'];
        $h = $this->hitung($this->x11());
        $budget = collect($h['budget'])->keyBy('sumber');
        $c61 = self::nilai($e['dasar']['panjang_sensitivitas_mm']);

        $ganti = ['drift_standar' => ['u' => $budget['drift_standar']['u']], 'pengulangan' => ['vi' => 9.0]];
        foreach (self::TERMAL_BERGANTUNG_PANJANG as $sumber) {
            $ganti[$sumber] = ['ci' => self::nilai($e['budget']['komponen'][$sumber]['ci']) * $h['l_maks_mm'] / $c61];
        }
        $penjelasan = $this->agregat($this->komponenExcel($ganti));

        $this->assertSamaHitungUlang($penjelasan['ketidakpastian_gabungan'], $h['ketidakpastian_gabungan'], 'uc aplikasi = uc dari komponen Excel + P-30/31/32');
        $this->assertSamaHitungUlang((float) $penjelasan['derajat_kebebasan_efektif'], (float) $h['derajat_kebebasan_efektif'], 'v_eff aplikasi');
        $this->assertSamaHitungUlang($penjelasan['faktor_cakupan_k'], $h['faktor_cakupan_k'], 'k aplikasi', 0.0, self::TOL_K);
        $this->assertSamaHitungUlang($penjelasan['ketidakpastian_diperluas'], $h['ketidakpastian_diperluas'], 'U95 aplikasi (mm)', self::TOL_K_REL);

        // Arah dan batas: di bawah Excel, di atas lantai CMC (jadi lantai tidak menutupi selisih).
        $this->assertLessThan(self::nilai($ag['u_hitung']), $h['ketidakpastian_diperluas'], 'U95 aplikasi < Excel '.$ag['u_hitung']['sel']);
        $this->assertGreaterThan(self::nilai($ag['cmc']), $h['ketidakpastian_diperluas'], 'U95 aplikasi di atas CMC: lantai tidak aktif');
        $this->assertSamaHitungUlang($h['ketidakpastian_diperluas'], $h['u95_sertifikat'], 'U95 sertifikat = U95 (lantai tidak aktif)', self::TOL_K_REL);
        $this->assertTrue($h['boleh_terbit'], 'sesi master yang sah boleh terbit');

        // Yang tercetak di sertifikat.
        $formatU = (new DialIndicatorProfile)->formatKolomSertifikat(new CalibrationSession, 0.0)['u95'];
        $this->assertSame($e['cetak']['u95']['format'], $formatU, 'format sel '.$e['cetak']['u95']['sel']);
        $this->assertSame('0.0088', $e['cetak']['u95']['teks'], 'cetak Excel '.$e['cetak']['u95']['sel']);
        $this->assertSame('0.0086', self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'], $formatU, false)), 'cetak aplikasi');
    }

    // ------------------------------------------------------------------ jalur profil (preview = simpan = hitung ulang)

    /**
     * `DialIndicatorProfile::hitungPerGrup()` — fungsi yang sama dengan preview,
     * simpan, dan `HitungUlangSesi` — untuk kolom yang tidak bergantung umur drift
     * diadu ke Excel; untuk kolom ketidakpastian diadu ke `hitungSesi()` pada
     * tanggal yang sama (tanggal profil hanya sehari penuh: umur drift beda dari X11,
     * jadi Excel tidak dijadikan acuan U95 di sini).
     */
    public function test_jalur_profil_hitung_per_grup_cocok_excel_dan_hitung_sesi(): void
    {
        $w = self::w();
        $profil = new DialIndicatorProfile;
        $hasil = $profil->hitungPerGrup($this->titikProfil(), $this->alat());
        $acuan = $this->hitung(new DateTimeImmutable($this->x11()->format('Y-m-d')));

        $this->assertFalse($profil->punyaToleransi(), 'Dial Indicator tidak divonis PASS/FAIL (master berhenti di Correction + U95)');
        $this->assertSame([], $hasil['belum_dihitung'], 'tidak boleh ada titik yang ditahan');
        $this->assertCount(count($w['excel']['titik']), $hasil['hitungan']);

        foreach ($hasil['hitungan'] as $baris) {
            $t = collect($w['excel']['titik'])->firstWhere('titik_ke', $baris['titik_ke']);
            $ke = "titik {$baris['titik_ke']}";

            $this->assertSamaExcel($t['standar_terkoreksi_mm'], $baris['titik_ukur'], "{$ke}: kolom titik_ukur = standar terkoreksi (mm)");
            $this->assertSamaExcel($t['rata_rata_mm'], $baris['rata_rata'], "{$ke}: kolom rata_rata (mm)");
            $this->assertSamaExcel($t['koreksi_mm'], $baris['koreksi'], "{$ke}: kolom koreksi (mm)");
            $this->assertSamaHitungUlang(-self::nilai($t['koreksi_mm']), $baris['error'], "{$ke}: error = -koreksi");
            $this->assertSamaExcel($t['simpangan_baku_mm'], $baris['standar_deviasi'], "{$ke}: STDEV (mm)");
            $this->assertSame(5, $baris['jumlah_pengulangan'], "{$ke}: jumlah pengulangan");

            $this->assertSamaHitungUlang($acuan['ketidakpastian_gabungan'], $baris['ketidakpastian_gabungan'], "{$ke}: uc = hitungSesi (mm)");
            $this->assertSamaHitungUlang($acuan['faktor_cakupan_k'], $baris['faktor_cakupan_k'], "{$ke}: k = hitungSesi", 0.0, self::TOL_K);
            $this->assertSamaHitungUlang($acuan['u95_sertifikat'], $baris['ketidakpastian_diperluas'], "{$ke}: U95 sertifikat = hitungSesi (mm)", self::TOL_K_REL);
            $this->assertNull($baris['toleransi'], "{$ke}: tanpa toleransi");
            $this->assertNull($baris['keputusan'], "{$ke}: tanpa vonis");
        }
    }

    private function alat(): Equipment
    {
        $alat = new Equipment;
        $alat->range_max = 25.0;

        return $alat;
    }

    /**
     * Titik dalam bentuk yang disusun jalur simpan untuk `hitungPerGrup()`: keping
     * (`di_balok`), pembacaan (`di_pembacaan`, mm), blok `spesifikasi_alat`.
     * Tanggal kalibrasi = tanggal X11 (hari penuh, format `Y-m-d` seperti jalur produksi).
     *
     * @return list<array<string, mixed>>
     */
    private function titikProfil(): array
    {
        [$titik, $konteksSesi] = $this->masukan($this->x11());

        return array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => 0.0,
            'pembacaan' => [],
            'standard' => null,
            'suhu_larutan' => null,
            'konteks' => [
                DialIndicatorMentah::PERAN_BALOK => $t['keping'],
                DialIndicatorMentah::PERAN_PEMBACAAN => $t['pembacaan'],
                'spesifikasi_alat' => [DialIndicatorMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => $konteksSesi['kapasitas_mm'],
                    'resolusi_mm' => $konteksSesi['resolusi_mm'],
                    'pra_evaluasi' => $konteksSesi['pra_evaluasi'],
                    'balok_pra_evaluasi' => $konteksSesi['balok_pra_evaluasi'],
                ]],
                'tanggal_kalibrasi' => $konteksSesi['tanggal_kalibrasi']->format('Y-m-d'),
                'suhu_ruang_rata' => $konteksSesi['suhu_ruang_rata_c'],
            ],
        ], $titik);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik: teks persis sama. Format per kolom = format sel
     * workbook (`SERTIFIKAT!D18:L27`, `J28`, `O29`) dan teks dihasilkan renderer
     * produksi (`TataLetakSertifikat::formatAngka`) dengan format dari profil.
     *
     * Baris yang di Excel tercetak `-0.00` (negatif yang membulat ke nol) dibanding
     * TANPA tanda: aplikasi sengaja tidak mencetak minus
     * (`DialIndicatorProfile::tandaNolDicetak`, PDF master G0-b P4), sedangkan
     * fixture bukan render Excel asli. Dicatat MENUNGGU; jumlahnya dikunci di
     * [test_baris_nol_bertanda_minus_dicatat]. U95 yang tercetak ada di tes P-34.
     */
    public function test_angka_cetak_titik_dan_k_sama_dengan_sertifikat_excel(): void
    {
        $e = self::w()['excel'];
        $h = $this->hitung($this->x11());
        $app = collect($h['titik'])->keyBy('titik_ke');

        $sesi = new CalibrationSession;
        $sesi->setRelation('equipment', $this->alat());
        $profil = new DialIndicatorProfile;
        $tandaNol = $profil->tandaNolDicetak();

        $this->assertCount(10, $e['cetak']['titik']);

        foreach ($e['cetak']['titik'] as $c) {
            $format = $profil->formatKolomSertifikat($sesi, self::nilai($c['standar']));
            $ke = "titik {$c['titik_ke']}";

            foreach (['standar' => 'standar_terkoreksi', 'uut' => 'rata_rata', 'koreksi' => 'koreksi'] as $kolom => $kunci) {
                $this->assertSame($c[$kolom]['format'], $format[$kolom] ?? null, "{$ke} {$kolom}: format sel {$c[$kolom]['sel']}");

                $teksApp = self::keTitik(TataLetakSertifikat::formatAngka($app[$c['titik_ke']][$kunci], $format[$kolom], $tandaNol));
                $teksExcel = $c[$kolom]['nol_bertanda_minus'] ? ltrim($c[$kolom]['teks'], '-') : $c[$kolom]['teks'];

                $this->assertSame($teksExcel, $teksApp, "{$ke} {$kolom}: teks cetak vs {$c[$kolom]['sel']} ({$c[$kolom]['nilai']})");
            }
        }

        $formatAkhir = $profil->formatKolomSertifikat($sesi, 0.0);
        $k = $e['cetak']['k'];
        $this->assertSame($k['format'], $formatAkhir['k'] ?? null, "format sel {$k['sel']}");
        $this->assertSame(
            $k['teks'],
            self::keTitik(TataLetakSertifikat::formatAngka($h['faktor_cakupan_k'], $formatAkhir['k'], $tandaNol)),
            "teks cetak k vs {$k['sel']}",
        );
    }

    /** Kunci jumlah baris `-0.00` (fixture) yang dibanding tanpa tanda: berubah = perbarui dokumen paritas. */
    public function test_baris_nol_bertanda_minus_dicatat(): void
    {
        $baris = array_values(array_map(
            static fn (array $c): int => $c['titik_ke'],
            array_filter(
                self::w()['excel']['cetak']['titik'],
                static fn (array $c): bool => $c['koreksi']['nol_bertanda_minus'],
            ),
        ));

        $this->assertSame([1, 4], $baris, 'baris koreksi membulat-ke-nol berubah: perbarui docs/paritas-batch5.md bagian MENUNGGU tanda minus');
    }
}
