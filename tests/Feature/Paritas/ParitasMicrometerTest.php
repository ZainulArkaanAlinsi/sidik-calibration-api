<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Support\MicrometerMentah;
use App\Support\TataLetakSertifikat;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Micrometer: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Bukan duplikat `tests/Unit/MicrometerMasterTest.php`: tes itu mengadu u tiap
 * komponen dan uc/veff/k/U95 (tiga dari empat pita) dengan toleransi 5e-6. Di
 * sini TIAP TAHAP diadu dengan alamat sel di pesan galat dan toleransi bertulis
 * (lihat [MenegakkanParitas]): pra-evaluasi, u balok, titik (total nominal,
 * rata-rata, standar terkoreksi, koreksi, STDEV), tiap komponen budget (ui, ci,
 * vi, uici), uc, veff, k, U95, lantai CMC, dan ANGKA CETAK (teks persis) —
 * di KEEMPAT pita termasuk 0-25 mm, lewat dua pintu masuk produksi:
 *
 *  - `MicrometerCalculator::hitungSesi()` (titik panggil terkecil, tanpa HTTP);
 *  - `MicrometerProfile::hitungPerGrup()` (fungsi yang SAMA dengan yang dipanggil
 *    preview, simpan, dan `HitungUlangSesi`).
 *
 * ## Acuan angka — dan jebakan `NOW()`
 *
 * Komponen drift Excel (`PERHITUNGAN U95%!K10`) memakai `DATABASE!X11 =NOW()`:
 * nilai-cache U95 tumbuh tiap berkas dibuka. Maka dua acuan saja yang sah:
 *
 *  1. nilai-cache pada saat X11 tersimpan (`drift.x11_cache`) — aplikasi diberi
 *     `tanggal_kalibrasi` = X11 itu, jadi umur drift identik;
 *  2. hitung ulang Python dari rumus sel dengan TANGGAL TETAP (`tanggal_tetap`,
 *     2026-06-30) — melepaskan tes dari cache yang bergeser.
 *
 * ## Dua selisih yang SUDAH tercatat (bukan temuan baru; bukan diratakan)
 *
 *  - Panjang untuk `ci` termal: Excel `F61` (keping pertama tumpukan titik
 *    terakhir) lawan aplikasi total nominal terbesar (penyimpangan sengaja no. 3,
 *    `MicrometerCalculator`). Sama di tiga pita (keping tunggal); beda di 0-25 mm
 *    (6,00016 lawan 25,00027). Diadu terhadap rumus sel dengan F61 := panjang
 *    aplikasi, supaya terbukti ITU satu-satunya penyebab.
 *  - Workbook 0-25 mm TERSIMPAN dengan sesi cacat (satuan `inch`, kapasitas 25 ->
 *    635, resolusi 1e-5, pra-evaluasi seragam): Excel menerbitkan U95 di bawah
 *    lantai CMC, aplikasi menahan. Paritas 0-25 di sini hanya membuktikan RANTAI
 *    pada angka pasca-konversi yang sama, BUKAN bahwa sesi 0-25 mm yang benar
 *    menghasilkan angka yang sama (tidak ada sesi benar di workbook itu).
 */
#[Group('paritas')]
class ParitasMicrometerTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Kapasitas nominal pita (mm) — memilih varian aplikasi tanpa bergantung isian workbook yang cacat. */
    private const KAPASITAS_PITA = ['025' => 25.0, '2550' => 50.0, '5075' => 75.0, '75100' => 100.0];

    /** Sembilan komponen: urutan sheet `PERHITUNGAN U95%` baris 5-13. */
    private const KOMPONEN = [
        'pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
        'drift_standar', 'wringing', 'geometri', 'selisih_suhu',
    ];

    /** Komponen yang `ci`-nya bergantung panjang (F61 lawan total nominal terbesar). */
    private const TERMAL_BERGANTUNG_PANJANG = ['suhu_ruang', 'koefisien_muai', 'selisih_suhu'];

    /** @return array<string, array{string}> */
    public static function varian(): array
    {
        return ['0-25 mm' => ['025'], '25-50 mm' => ['2550'], '50-75 mm' => ['5075'], '75-100 mm' => ['75100']];
    }

    /** @return array<string, array{string}> */
    public static function varianNormal(): array
    {
        return ['25-50 mm' => ['2550'], '50-75 mm' => ['5075'], '75-100 mm' => ['75100']];
    }

    /** @return array<string, mixed> */
    private static function v(string $kode): array
    {
        return self::fixtureParitas('micrometer')['varian'][$kode];
    }

    private static function nonNull(array $deret): array
    {
        return array_values(array_filter($deret, static fn ($x): bool => $x !== null));
    }

    /**
     * Susun masukan persis seperti jalur produksi: blok `spesifikasi_alat` lewat
     * `MicrometerMentah::blokSesi` (konversi satuan), pembacaan lewat `keMm`.
     *
     * Kapasitas dan resolusi masuk sebagai nilai PASCA-KONVERSI workbook
     * (`PERHITUNGAN!G8`, `G9`): aplikasi tidak punya konversi kapasitas sendiri
     * (`kapasitas_mm` sudah mm), dan di 0-25 itulah yang membuat Excel melihat 635.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function masukan(string $kode, DateTimeImmutable $tanggal): array
    {
        $v = self::v($kode);
        $m = $v['masukan'];
        $satuan = $m['satuan']['teks'];

        $blok = MicrometerMentah::blokSesi(['micrometer' => [
            'satuan' => $satuan,
            'kapasitas_mm' => self::nilai($v['excel']['kapasitas_mm']),
            'resolusi_mm' => self::nilai($v['excel']['resolusi_mm']),
            'pra_evaluasi' => self::nonNull($m['pra_evaluasi_ketik']['nilai']),
        ]]);

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'nominal' => array_map('floatval', $t['keping_mm']['nilai']),
            'pembacaan' => array_map(
                static fn ($x): float => MicrometerMentah::keMm($x, $satuan),
                $t['pembacaan_ketik']['nilai'],
            ),
        ], $m['titik']);

        return [$titik, $blok + [
            'tanggal_kalibrasi' => $tanggal,
            'suhu_ruang_rata_c' => MicrometerMentah::rataSuhuRuang(
                self::nilai($m['suhu_awal_c']),
                self::nilai($m['suhu_akhir_c']),
            ),
            'balok_pra_evaluasi' => array_map('floatval', $m['balok_pra_evaluasi_mm']['nilai']),
        ]];
    }

    /**
     * `DATABASE!X11` tersimpan, dipotong ke detik penuh: aplikasi menghitung umur
     * dari `getTimestamp()` (detik), sedangkan X11 punya pecahan detik. Pemotongan
     * paling banyak 1 detik — dipakai sebagai batas turunan di
     * [test_cache_excel_pada_x11_tersimpan_sama_dalam_batas_satu_detik].
     */
    private function x11(string $kode): DateTimeImmutable
    {
        $x = new DateTimeImmutable(self::v($kode)['drift']['x11_cache']['nilai']);

        return $x->setTime((int) $x->format('H'), (int) $x->format('i'), (int) $x->format('s'));
    }

    private function tanggalTetap(string $kode): DateTimeImmutable
    {
        return new DateTimeImmutable(self::v($kode)['tanggal_tetap']['tanggal']);
    }

    /** @return array<string, mixed> */
    private function hitungPada(string $kode, DateTimeImmutable $tanggal): array
    {
        [$titik, $konteks] = $this->masukan($kode, $tanggal);

        return (new MicrometerCalculator)->hitungSesi($titik, $konteks);
    }

    /** Acuan sah: tanggal tetap (hitung ulang rumus sel). Untuk tahap yang tidak bergantung tanggal, tanggal apa pun sama. */
    private function hitungTetap(string $kode): array
    {
        return $this->hitungPada($kode, $this->tanggalTetap($kode));
    }

    /** Panjang yang dipakai aplikasi untuk ci termal: total nominal terbesar sesi. */
    private function panjangAplikasi(string $kode): float
    {
        return max(array_map(
            static fn (array $t): float => self::nilai($t['total_nominal_mm']),
            self::v($kode)['excel']['titik'],
        ));
    }

    // ------------------------------------------------------------------ tahap masukan

    #[DataProvider('varian')]
    public function test_pra_evaluasi_dan_ketidakpastian_balok_cocok_excel(string $kode): void
    {
        $v = self::v($kode);
        [, $konteks] = $this->masukan($kode, $this->tanggalTetap($kode));
        $kalk = new MicrometerCalculator;

        // `PERHITUNGAN!C25:M25` = pembacaan pra-evaluasi dalam mm (sel E25 teks, tak dihitung).
        $excelPra = self::nonNull($v['excel']['pra_evaluasi_mm']['nilai']);
        $this->assertCount(count($excelPra), $konteks['pra_evaluasi'], 'jumlah pembacaan pra-evaluasi');
        foreach ($konteks['pra_evaluasi'] as $i => $x) {
            $this->assertSamaHitungUlang($excelPra[$i], $x, "pra-evaluasi ke-{$i} (PERHITUNGAN!C25:M25, mm)");
        }

        $this->assertSamaExcel(
            $v['excel']['stdev_pra_evaluasi_mm'],
            $kalk->simpanganBaku($konteks['pra_evaluasi']),
            'STDEV pra-evaluasi (mm)',
        );

        // Balok pra-evaluasi: aplikasi mengambilnya dari tabel varian, Excel dari INPUT DATA!I29:N29.
        $varian = (new TabelStandarMicrometer)->pitaCmc(self::KAPASITAS_PITA[$kode]);
        $this->assertSame(
            array_map('floatval', $v['masukan']['balok_pra_evaluasi_mm']['nilai']),
            array_map('floatval', $varian['balok_pra_evaluasi_mm']),
            'balok pra-evaluasi tabel varian vs INPUT DATA!I29:N29',
        );

        // PERHITUNGAN!H23:M23 (u tiap keping, µm) dan O25 (akar jumlah kuadrat).
        $tabel = new TabelStandarMicrometer;
        foreach ($konteks['balok_pra_evaluasi'] as $i => $nominal) {
            $this->assertSamaExcel(
                ['sel' => $v['excel']['u_balok_pra_evaluasi_um']['sel'][$i], 'nilai' => $v['excel']['u_balok_pra_evaluasi_um']['nilai'][$i]],
                $tabel->ketidakpastianBalok($nominal),
                "u balok {$nominal} mm (µm)",
            );
        }
        $this->assertSamaExcel(
            $v['excel']['u_standar_balok_um'],
            $kalk->ketidakpastianTumpukan($konteks['balok_pra_evaluasi']),
            'Ustdmax (PERHITUNGAN!O25, µm)',
        );
    }

    #[DataProvider('varian')]
    public function test_tumpukan_keping_tabel_varian_sama_dengan_isian_workbook(string $kode): void
    {
        $varian = (new TabelStandarMicrometer)->pitaCmc(self::KAPASITAS_PITA[$kode]);

        foreach (self::v($kode)['masukan']['titik'] as $t) {
            $tumpukanApp = array_map('floatval', $varian['titik'][$t['titik_ke'] - 1]['tumpukan_mm']);
            $tumpukanExcel = array_map('floatval', $t['keping_mm']['nilai']);
            sort($tumpukanApp);
            sort($tumpukanExcel);

            $this->assertSame(
                $tumpukanExcel,
                $tumpukanApp,
                "tumpukan keping titik {$t['titik_ke']} ({$kode}): workbook ".implode(',', $t['keping_mm']['sel']),
            );
        }
    }

    // ------------------------------------------------------------------ tahap titik

    #[DataProvider('varian')]
    public function test_titik_total_nominal_rata_rata_standar_koreksi_dan_stdev_cocok_excel(string $kode): void
    {
        $hasil = $this->hitungTetap($kode);
        $app = collect($hasil['titik'])->keyBy('titik_ke');

        foreach (self::v($kode)['excel']['titik'] as $t) {
            // Titik nol 0-25 mm (nominal 0, tanpa pembacaan): Excel tidak menghitung rata-rata/koreksi.
            if ($t['rata_rata_mm']['nilai'] === null) {
                continue;
            }

            $h = $app[$t['titik_ke']] ?? null;
            $this->assertNotNull($h, "titik {$t['titik_ke']} tidak terhitung di aplikasi");
            $ke = "titik {$t['titik_ke']}";

            $this->assertSamaExcel($t['total_nominal_mm'], $h['total_nominal'], "{$ke} total nominal (mm)");
            $this->assertSamaExcel($t['standar_terkoreksi_mm'], $h['standar_terkoreksi'], "{$ke} standar terkoreksi (mm)");
            $this->assertSamaExcel($t['rata_rata_mm'], $h['rata_rata'], "{$ke} rata-rata pembacaan (mm)");
            $this->assertSamaExcel($t['koreksi_mm'], $h['koreksi'], "{$ke} koreksi (mm)");

            if ($t['stdev_tampilan_mm']['nilai'] !== null) {
                $this->assertSamaExcel($t['stdev_tampilan_mm'], $h['simpangan_baku'], "{$ke} STDEV (mm; tampilan)");
            }
        }
    }

    // ------------------------------------------------------------------ tahap budget

    /**
     * Sembilan komponen: ui, ci, vi, uici. Delapan komponen tak bergantung tanggal
     * dibandingkan ke nilai-cache; DRIFT ke hitung ulang rumus sel pada tanggal
     * tetap. Di 0-25 mm tiga komponen termal dibandingkan terhadap rumus sel dengan
     * F61 := panjang aplikasi (lihat docblock kelas), dan arah selisihnya dikunci.
     */
    #[DataProvider('varian')]
    public function test_sembilan_komponen_budget_cocok_excel(string $kode): void
    {
        $v = self::v($kode);
        $budget = collect($this->hitungTetap($kode)['budget'])->keyBy('sumber');
        $panjang = $this->panjangAplikasi($kode);
        $f61 = self::nilai($v['excel']['panjang_f61_mm']);
        $beda = abs($panjang - $f61) > 1e-12;
        $alpha = self::nilai($v['excel']['delta_alpha']);
        $dTheta = self::nilai($v['excel']['komponen']['suhu_ruang']['u_mentah']);
        $k10 = $v['tanggal_tetap']['dengan_panjang_aplikasi']['k10_um'];

        foreach (self::KOMPONEN as $sumber) {
            $x = $v['excel']['komponen'][$sumber];
            $b = $budget[$sumber];

            $this->assertSamaExcel($x['vi'], $b['vi'], "{$sumber}: vi");

            if ($sumber === 'drift_standar') {
                // Rumus sel K10 pada tanggal tetap (bukan cache: X11 = NOW()).
                $this->assertSamaHitungUlang($k10 / sqrt(3), $b['u'], "drift_standar: ui pada {$v['tanggal_tetap']['tanggal']} (rumus {$x['u_mentah']['sel']})");
                $this->assertSamaExcel($x['ci'], $b['ci'], 'drift_standar: ci');

                continue;
            }

            $this->assertSamaExcel($x['ui'], $b['u'], "{$sumber}: ui = u/pembagi");

            if ($beda && in_array($sumber, self::TERMAL_BERGANTUNG_PANJANG, true)) {
                // Rumus sel V8 = K9*F61, V9 = F61*K8, V13 = V8 dengan F61 := panjang aplikasi.
                $ciApp = $sumber === 'koefisien_muai' ? $panjang * $dTheta : $alpha * $panjang;
                $this->assertSamaHitungUlang($ciApp, $b['ci'], "{$sumber}: ci (rumus sel dengan F61 := {$panjang})");
                $this->assertGreaterThan(
                    self::nilai($x['ci']),
                    $b['ci'],
                    "{$sumber}: ci aplikasi HARUS lebih besar dari Excel ({$x['ci']['sel']}) saat panjangnya beda — arah aman.",
                );

                continue;
            }

            $this->assertSamaExcel($x['ci'], $b['ci'], "{$sumber}: ci");
            $this->assertSamaExcel($x['uici'], $b['u'] * $b['ci'], "{$sumber}: ui*ci");
        }
    }

    // ------------------------------------------------------------------ tahap agregat

    /**
     * uc, veff, k, U95, lantai CMC pada TANGGAL TETAP: acuan = rumus sel
     * `PERHITUNGAN U95%` dihitung ulang (Python) dengan X11 := tanggal itu.
     */
    #[DataProvider('varianNormal')]
    public function test_uc_veff_k_u95_dan_lantai_cmc_cocok_hitung_ulang_rumus_sel_pada_tanggal_tetap(string $kode): void
    {
        $v = self::v($kode);
        $ref = $v['tanggal_tetap']['dengan_panjang_aplikasi'];
        $a = $v['excel']['agregat'];
        $h = $this->hitungTetap($kode);
        $pada = $v['tanggal_tetap']['tanggal'];

        $this->assertSamaHitungUlang($ref['uc_um'], $h['ketidakpastian_gabungan'], "uc pada {$pada} (µm; rumus {$a['uc_um']['sel']})");
        $this->assertSamaHitungUlang($ref['veff'], (float) $h['derajat_kebebasan_efektif'], "v_eff pada {$pada} ({$a['veff']['sel']})");
        $this->assertSamaHitungUlang($ref['k'], $h['faktor_cakupan_k'], "k pada {$pada} ({$a['k']['sel']})", 0.0, self::TOL_K);
        $this->assertSamaHitungUlang($ref['u95_um'], $h['ketidakpastian_diperluas'], "U95 sebelum lantai CMC pada {$pada} (µm; {$a['u95_um']['sel']})", self::TOL_K_REL);

        // Lantai CMC: Excel X18 dari DATABASE!T5:T8, X19 = MAX(U95; CMC)/1000 (mm).
        $this->assertNotNull($h['pita_cmc'], 'pita CMC');
        $this->assertSamaExcel($a['cmc_um'], (float) $h['pita_cmc']['u95_um'], 'CMC pita (µm)');
        $this->assertSamaHitungUlang(
            $ref['u95_sertifikat_mm'],
            $h['u95_sertifikat'] / 1000,
            "U95 sertifikat = max(U95; CMC) pada {$pada} (mm; rumus {$a['u95_sertifikat_mm']['sel']})",
            self::TOL_K_REL,
        );
        $this->assertTrue($h['boleh_terbit'], 'sesi master yang sah harus boleh terbit');
    }

    /**
     * Silang-cek ke NILAI-CACHE pada X11 tersimpan. Aplikasi menghitung umur dari
     * detik penuh, X11 punya pecahan detik, jadi selisih sah paling besar satu
     * detik umur: 1 / (X11 - W13 dalam detik) relatif pada komponen drift; uc dan U
     * turunannya tidak mungkin berubah lebih besar dari itu (drift <= seluruh uc).
     * Cache hanya acuan untuk SAAT X11 itu — bukan acuan U95 umum.
     */
    #[DataProvider('varianNormal')]
    public function test_cache_excel_pada_x11_tersimpan_sama_dalam_batas_satu_detik(string $kode): void
    {
        $v = self::v($kode);
        $a = $v['excel']['agregat'];
        $h = $this->hitungPada($kode, $this->x11($kode));

        $umurDetik = (new DateTimeImmutable($v['drift']['x11_cache']['nilai']))->getTimestamp()
            - (new DateTimeImmutable($v['drift']['w13_kalibrasi_balok']['nilai']))->getTimestamp();
        $batas = 1.0 / $umurDetik; // ~1,7e-8

        $drift = collect($h['budget'])->firstWhere('sumber', 'drift_standar');
        $this->assertSamaExcel($v['excel']['komponen']['drift_standar']['ui'], $drift['u'], 'drift ui pada X11 tersimpan', rel: $batas);
        $this->assertSamaExcel($a['uc_um'], $h['ketidakpastian_gabungan'], 'uc pada X11 tersimpan (µm)', rel: $batas);
        $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], 'k pada X11 tersimpan');
        $this->assertSamaExcel($a['u95_um'], $h['ketidakpastian_diperluas'], 'U95 pada X11 tersimpan (µm)', rel: $batas);
        $this->assertSamaExcel($a['u95_sertifikat_mm'], $h['u95_sertifikat'] / 1000, 'U95 sertifikat pada X11 tersimpan (mm)', rel: $batas);
    }

    /**
     * 0-25 mm: rantai (uc, veff, k, U95) cocok rumus sel dengan F61 := panjang aplikasi,
     * selisih terhadap cache Excel asli DICATAT (bukan disembunyikan), dan aplikasi
     * MENAHAN penerbitan di tempat Excel menerbitkan 0,735 µm di bawah CMC 0,83.
     */
    public function test_025_rantai_cocok_dengan_panjang_aplikasi_dan_penerbitan_ditahan(): void
    {
        $v = self::v('025');
        $a = $v['excel']['agregat'];
        $ref = $v['tanggal_tetap']['dengan_panjang_aplikasi'];
        $h = $this->hitungTetap('025');

        $this->assertSamaHitungUlang($ref['uc_um'], $h['ketidakpastian_gabungan'], 'uc 0-25 (rumus sel, F61 := 25,00027; tanggal tetap)');
        $this->assertSamaHitungUlang($ref['veff'], (float) $h['derajat_kebebasan_efektif'], 'veff 0-25');
        $this->assertSamaHitungUlang($ref['k'], $h['faktor_cakupan_k'], 'k 0-25', 0.0, self::TOL_K);
        $this->assertSamaHitungUlang($ref['u95_um'], $h['ketidakpastian_diperluas'], 'U95 0-25', self::TOL_K_REL);

        // Selisih panjang-ci terhadap Excel asli ADA dan KECIL — dikunci dua arah, pada X11 tersimpan.
        $hx = $this->hitungPada('025', $this->x11('025'));
        $selisih = abs($hx['ketidakpastian_diperluas'] - self::nilai($a['u95_um'])) / self::nilai($a['u95_um']);
        $this->assertGreaterThan(1e-9, $selisih, 'selisih panjang-ci 0-25 hilang: perbarui docs/paritas-micrometer-ph.md');
        $this->assertLessThan(1e-6, $selisih, 'selisih panjang-ci 0-25 membesar: bukan lagi efek F61 saja');

        // Excel: X18 = teks "cek range" (kapasitas 635 di luar keempat pita) -> tanpa lantai CMC.
        $this->assertNull($a['cmc_um']['nilai'], 'Excel 0-25 seharusnya tanpa nilai CMC (cek range)');
        $this->assertNull($h['pita_cmc'], 'aplikasi: kapasitas 635 mm di luar semua pita');
        $this->assertFalse($h['boleh_terbit'], 'aplikasi menahan: tanpa pita CMC dan pra-evaluasi seragam');
        $this->assertSame(0.0, $h['u95_sertifikat']);
    }

    // ------------------------------------------------------------------ jalur profil (preview = simpan = hitung ulang)

    /**
     * `MicrometerProfile::hitungPerGrup()` — fungsi yang sama dengan preview,
     * simpan, dan `HitungUlangSesi` — menghasilkan angka yang sama dengan Excel,
     * dalam satuan KOLOM tabel (mm; budget µm dibagi 1000).
     */
    #[DataProvider('varianNormal')]
    public function test_jalur_profil_hitung_per_grup_cocok_excel(string $kode): void
    {
        $v = self::v($kode);
        $ref = $v['tanggal_tetap']['dengan_panjang_aplikasi'];
        $hasil = (new MicrometerProfile)->hitungPerGrup($this->titikProfil($kode), $this->alat($kode));

        $this->assertSame([], $hasil['belum_dihitung'], 'tidak boleh ada titik yang ditahan');
        $this->assertCount(count($v['excel']['titik']), $hasil['hitungan']);

        foreach ($hasil['hitungan'] as $baris) {
            $t = collect($v['excel']['titik'])->firstWhere('titik_ke', $baris['titik_ke']);
            $ke = "titik {$baris['titik_ke']}";

            $this->assertSamaExcel($t['total_nominal_mm'], $baris['titik_ukur'], "{$ke}: kolom titik_ukur (mm)");
            $this->assertSamaExcel($t['rata_rata_mm'], $baris['rata_rata'], "{$ke}: kolom rata_rata (mm)");
            $this->assertSamaExcel($t['koreksi_mm'], $baris['koreksi'], "{$ke}: kolom koreksi (mm)");
            $this->assertSamaHitungUlang($ref['uc_um'], $baris['ketidakpastian_gabungan'] * 1000, "{$ke}: uc (µm)");
            $this->assertSamaHitungUlang($ref['k'], $baris['faktor_cakupan_k'], "{$ke}: k", 0.0, self::TOL_K);
            $this->assertSamaHitungUlang($ref['u95_sertifikat_mm'], $baris['ketidakpastian_diperluas'], "{$ke}: U95 sertifikat (mm)", self::TOL_K_REL);
        }
    }

    /**
     * Sesi 0-25 mm APA ADANYA di workbook (satuan inch -> kapasitas 635 mm) lewat jalur
     * profil: tidak ada satu pun baris hitungan, dan alasannya terbaca. Excel
     * menerbitkan; aplikasi menahan (selisih sengaja, `MicrometerCalculator` no. 1).
     */
    public function test_jalur_profil_025_sesi_apa_adanya_ditahan_dengan_alasan(): void
    {
        $hasil = (new MicrometerProfile)->hitungPerGrup($this->titikProfil('025'), $this->alat('025'));

        $this->assertSame([], $hasil['hitungan'], 'aplikasi tidak boleh menerbitkan satu baris pun');
        $this->assertNotEmpty($hasil['belum_dihitung']);
        $alasan = implode(' | ', array_column($hasil['belum_dihitung'], 'alasan'));
        $this->assertStringContainsString('di luar keempat pita CMC', $alasan);
    }

    private function alat(string $kode): Equipment
    {
        $alat = new Equipment;
        $alat->range_max = self::KAPASITAS_PITA[$kode];

        return $alat;
    }

    /**
     * Titik dalam bentuk yang disusun `CalibrationController::susunBlokMicrometer`
     * untuk `hitungPerGrup()`: tumpukan, pembacaan (mm), blok `spesifikasi_alat`.
     *
     * @return list<array<string, mixed>>
     */
    private function titikProfil(string $kode): array
    {
        [$titik, $konteksSesi] = $this->masukan($kode, $this->tanggalTetap($kode));

        return array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => 0.0,
            'pembacaan' => [],
            'standard' => null,
            'suhu_larutan' => null,
            'konteks' => [
                MicrometerMentah::PERAN_BALOK => $t['nominal'],
                MicrometerMentah::PERAN_PEMBACAAN => $t['pembacaan'],
                'spesifikasi_alat' => ['micrometer' => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => $konteksSesi['kapasitas_mm'],
                    'resolusi_mm' => $konteksSesi['resolusi_mm'],
                    'pra_evaluasi' => $konteksSesi['pra_evaluasi'],
                ]],
                'tanggal_kalibrasi' => $konteksSesi['tanggal_kalibrasi']->format('Y-m-d'),
                'suhu_ruang_rata' => $konteksSesi['suhu_ruang_rata_c'],
            ],
        ], $titik);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK: teks persis sama. Format per kolom = format sel workbook
     * (`SERTIFIKAT!D18:L28`, `J29`, `P30`) dan teks dihasilkan renderer produksi
     * (`TataLetakSertifikat::formatAngka`) dengan format dari profil.
     *
     * Baris yang di Excel (emulasi) tercetak `-0.000` — nilai negatif yang membulat
     * ke nol — dibandingkan TANPA tanda: aplikasi sengaja tidak mencetak minus
     * (`MicrometerProfile::tandaNolDicetak`, bukti PDF master di G0-b P4) sedangkan
     * emulasi fixture tidak bisa membuktikan render Excel asli. Dicatat MENUNGGU
     * di docs/paritas-micrometer-ph.md; jumlah baris itu dikunci di
     * [test_baris_nol_bertanda_minus_dicatat].
     */
    #[DataProvider('varianNormal')]
    public function test_angka_cetak_sama_dengan_sertifikat_excel(string $kode): void
    {
        $v = self::v($kode);
        $h = $this->hitungTetap($kode);
        $app = collect($h['titik'])->keyBy('titik_ke');

        $sesi = new CalibrationSession;
        $sesi->setRelation('equipment', $this->alat($kode));
        $profil = new MicrometerProfile;
        $tandaNol = $profil->tandaNolDicetak();

        foreach ($v['excel']['cetak']['titik'] as $c) {
            $format = $profil->formatKolomSertifikat($sesi, self::nilai($c['standar']));
            $ke = "titik {$c['titik_ke']}";

            foreach (['standar' => 'standar_terkoreksi', 'uut' => 'rata_rata', 'koreksi' => 'koreksi'] as $kolom => $kunci) {
                $this->assertSame($c[$kolom]['format'], $format[$kolom] ?? null, "{$ke} {$kolom}: format sel {$c[$kolom]['sel']}");

                $teksApp = self::keTitik(TataLetakSertifikat::formatAngka($app[$c['titik_ke']][$kunci], $format[$kolom], $tandaNol));
                $teksExcel = $c[$kolom]['nol_bertanda_minus'] ? ltrim($c[$kolom]['teks'], '-') : $c[$kolom]['teks'];

                $this->assertSame($teksExcel, $teksApp, "{$ke} {$kolom}: teks cetak vs {$c[$kolom]['sel']} ({$c[$kolom]['nilai']})");
            }
        }

        // U95 dan k dicetak SATU kali. U95 pada tanggal tetap (cache X11 tumbuh).
        $formatU = $profil->formatKolomSertifikat($sesi, 0.0);
        $u = $v['excel']['cetak']['u95'];
        $this->assertSame($u['format'], $formatU['u95'] ?? null, "format sel {$u['sel']}");
        $this->assertSame(
            $v['tanggal_tetap']['dengan_panjang_aplikasi']['u95_cetak'],
            self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'] / 1000, $formatU['u95'], $tandaNol)),
            "teks cetak U95 pada tanggal tetap vs rumus {$u['sel']}",
        );

        $k = $v['excel']['cetak']['k'];
        $this->assertSame($k['format'], $formatU['k'] ?? null, "format sel {$k['sel']}");
        $this->assertSame(
            $k['teks'],
            self::keTitik(TataLetakSertifikat::formatAngka($h['faktor_cakupan_k'], $formatU['k'], $tandaNol)),
            "teks cetak k vs {$k['sel']}",
        );
    }

    /** Kunci jumlah baris `-0.000` (emulasi Excel) yang dibandingkan tanpa tanda — perubahan jumlah = perbarui dokumen paritas. */
    public function test_baris_nol_bertanda_minus_dicatat(): void
    {
        $jumlah = [];
        foreach (self::varianNormal() as [$kode]) {
            $jumlah[$kode] = count(array_filter(
                self::v($kode)['excel']['cetak']['titik'],
                static fn (array $c): bool => $c['koreksi']['nol_bertanda_minus'],
            ));
        }

        $this->assertSame(['2550' => 2, '5075' => 0, '75100' => 6], $jumlah, 'baris koreksi membulat-ke-nol berubah: perbarui docs/paritas-micrometer-ph.md bagian MENUNGGU tanda minus');
    }

    // ------------------------------------------------------------------ K-10 (keputusan default, 25 Nov 2026)

    /**
     * K-10, `KEPUTUSAN-DEFAULT.md` (default A, kategori [T], keyakinan rendah):
     * blok `Note :` kerataan/kesejajaran muka ukur (master `165:167!B31:G33`,
     * `G32 = INPUT DATA!W25`, `G33 = W30`) HANYA mencetak yang diisi teknisi.
     * Kosong = barisnya tidak dicetak, tidak pernah "Baik" karangan; kedua
     * kosong = tanpa blok. Tes ini mengunci perilaku kode yang sudah dipilih
     * default; tidak membuktikan isi master pada sesi yang kotaknya kosong.
     */
    #[Group('keputusan-default')]
    public function test_k10_kerataan_kesejajaran_tanpa_isian_tidak_dicetak(): void
    {
        $profil = new MicrometerProfile;

        $kosong = new CalibrationSession;
        $kosong->spesifikasi_alat = [MicrometerMentah::KUNCI_SESI => ['resolusi_mm' => 0.001]];
        $this->assertSame([], $profil->catatanSertifikat($kosong), 'kedua kotak kosong: tanpa blok Note');

        $satu = new CalibrationSession;
        $satu->spesifikasi_alat = [MicrometerMentah::KUNCI_SESI => ['kerataan_muka_ukur' => 'baik']];
        $blok = $profil->catatanSertifikat($satu);
        $this->assertCount(1, $blok);
        $this->assertSame([['Kerataan Muka Ukur dalam Kondisi', ':', 'Baik']], $blok[0]['tabel'], 'hanya baris yang diisi yang dicetak');

        $buruk = new CalibrationSession;
        $buruk->spesifikasi_alat = [MicrometerMentah::KUNCI_SESI => ['kesejajaran_muka_ukur' => 'Buruk', 'kerataan_muka_ukur' => '']];
        $this->assertSame([['Kesejajaran Muka Ukur dalam Kondisi', ':', 'Buruk']], $profil->catatanSertifikat($buruk)[0]['tabel']);
    }

    // ------------------------------------------------------------------ K-1 (keputusan default, 25 Nov 2026)

    /**
     * K-1, `konflik-excel-kertas.md`: titik ke-3 pita 50-75 mm — 51,0 (kertas 0522.C,
     * workbook master, aplikasi) lawan 55,6 (cetakan Excel job 0455-CAL-1026).
     *
     * STATUS (25 Nov 2026): KEPUTUSAN DEFAULT B DITERAPKAN, tanpa perubahan kode.
     * Sumber: `sidik-dokumentasi/2026-11-25/keputusan/KEPUTUSAN-DEFAULT.md`, butir
     * A1/K-1 (default B = titik 3 tetap 51,0; kategori [T]; keyakinan sedang),
     * diputuskan koordinator atas delegasi pemilik. Keputusan ini BELUM
     * dikonfirmasi Manajer Teknis; bila TM memilih 55,6, ubah tabel varian +
     * kertas Rev.2, dan tes ini merah menunjuk ke sini.
     *
     * Tes ini mengunci keadaan yang dipilih default B:
     *
     *  - aplikasi = kertas = workbook master = 51,0 (total terkoreksi 51,00025 mm);
     *  - tumpukan 55,6 (50,0+1,2+2,5+1,9) memang dikenal tabel keping (Excel menerimanya,
     *    nilai 55,60025) — tetapi aplikasi TIDAK merepresentasikannya: nominal
     *    titik dipatok varian kertas, kiriman HP hanya diperiksa terhadapnya.
     *
     * Catatan fixture: string `konflik_k1.status` ("menunggu keputusan Manajer
     * Teknis") ditulis `docs/skrip/gen-fixture-paritas-micrometer-ph.py` dan
     * mencatat keadaan 24 Nov. Fixture bangkitan tidak disunting tangan (generator
     * butuh workbook ber-sandi); assertion string itu dibiarkan apa adanya.
     */
    #[Group('keputusan-default')]
    public function test_k1_titik_ketiga_50_75_aplikasi_sama_dengan_kertas_dan_master_keputusan_default_51_0(): void
    {
        $v = self::v('5075');
        $k1 = $v['konflik_k1'];

        // Aplikasi == master workbook (51,0).
        $varian = (new TabelStandarMicrometer)->pitaCmc(75.0);
        $this->assertSame(51.0, (float) $varian['titik'][2]['nominal_cetak_mm'], 'nominal cetak titik 3 (kertas 0522.C: 51.0)');
        $this->assertSame(
            array_map('floatval', $k1['master']['keping_mm']),
            array_map('floatval', $varian['titik'][2]['tumpukan_mm']),
            'tumpukan titik 3 aplikasi vs master '.implode(',', $k1['master']['sel_masukan']),
        );
        $kalk = new MicrometerCalculator;
        $this->assertSamaExcel(
            $k1['master']['total_nominal_mm'],
            (float) $kalk->totalNominal($varian['titik'][2]['tumpukan_mm']),
            'K-1 total nominal titik 3 (51,00025 mm)',
        );

        // Tumpukan 55,6 (cetakan job): tabel keping aplikasi == tabel Standar_GB Excel -> nilai sama.
        $this->assertSamaExcel(
            $k1['cetakan_job']['total_nominal_mm'],
            (float) $kalk->totalNominal($k1['cetakan_job']['keping_mm']),
            'K-1 total nominal tumpukan 55,6 (55,60025 mm)',
        );

        // ...tetapi aplikasi tidak punya jalan menerimanya: tidak ada titik bernominal 55,6 di varian 50-75.
        $nominalVarian = array_map(static fn (array $t): float => (float) $t['nominal_cetak_mm'], $varian['titik']);
        $this->assertNotContains(55.6, $nominalVarian, 'K-1: 55,6 masuk tabel varian — keputusan default B (51,0) berubah? perbarui docs/paritas-micrometer-ph.md');
        $this->assertSame('menunggu keputusan Manajer Teknis', $k1['status']);
    }
}
