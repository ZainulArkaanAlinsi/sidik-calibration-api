<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\Profiles\HeightGaugeProfile;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Services\GumCalculator;
use App\Support\HeightGaugeMentah;
use App\Support\TataLetakSertifikat;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Height Gauge: aplikasi lawan workbook master Excel, tahap demi tahap.
 *
 * Fixture `tests/Fixtures/paritas/height-gauge.json` dibangun
 * `docs/skrip/gen-fixture-paritas-batch6.py` dari nilai-cache workbook
 * `Master olda Height Gauge 600 mm 2026.xlsm` (sel + nilai, tidak disunting
 * tangan; hash berkas dan catatan pembukaan ada di fixture, bagian `sumber`).
 * Pesan galat tiap banding menyebut sel workbook, jadi tes merah menunjuk ke sel
 * yang menyimpang (lihat [MenegakkanParitas]).
 *
 * Dua pintu masuk produksi yang diadu, sama seperti Micrometer dan Dial Indicator:
 *
 *  - `HeightGaugeCalculator::hitungSesi()` (titik panggil terkecil; `budget()` privat,
 *    jadi budget dibaca dari hasilnya);
 *  - `HeightGaugeProfile::hitungPerGrup()` (fungsi yang SAMA dengan preview, simpan,
 *    dan `HitungUlangSesi`).
 *
 * ## Acuan angka dan jebakan `NOW()`
 *
 * Komponen drift Excel (`PERHITUNGAN U95%!K10`) memakai `DATABASE!X11 = NOW()`,
 * jadi nilai-cache U95 tumbuh tiap workbook dihitung ulang. Acuan yang sah hanya
 * nilai-cache pada saat X11 itu tersimpan: aplikasi diberi tanggal sama dengan X11.
 * `umurStandarHari()` memakai `getTimestamp()` (detik bulat), jadi pecahan detik
 * X11 (0,48 s) tidak ikut: umur aplikasi = umur cache Excel - 0,48 / 86400 hari.
 * Ekspektasi tes memakai umur detik-bulat itu; toleransi TIDAK dilonggarkan.
 *
 * ## Selisih yang SENGAJA ada di aplikasi (diberi nomor B6-1 dan seterusnya)
 *
 * Penyimpangan ini sudah tertulis di `HeightGaugeCalculator`; tes di sini tidak
 * meratakannya dan tidak mengubah kode produksi agar cocok. Tiap selisih dikunci
 * sebagai tes tersendiri (`#[Group('menunggu-keputusan-tm')]`), berikut nilai
 * kedua sisi:
 *
 *  - B6-1  pembagi umur drift: aplikasi /365, Excel `K10` /12 (satu-satunya yang
 *          menggeser U95; tercetak "0.015" lawan Excel "0.016").
 *  - B6-2  paralelisme: aplikasi Max - Min = 0,002; Excel `J24` = STDEV(Max; Min)
 *          = 0,001414. Vonisnya sama ("Good").
 *  - B6-3  suku termal standar terkoreksi: Excel hanya di baris titik 1
 *          (`M35:T35`), aplikasi di sepuluh titik. Tanpa efek angka pada data ini
 *          (suku-suku bernilai nol), jadi ini beda struktur, bukan beda angka.
 *
 * Satu tes penutup membuktikan B6-1 menjelaskan SELURUH beda U95 aplikasi lawan
 * Excel (uc, v_eff, k, U).
 *
 * ## Butir MENUNGGU (bukan beda angka; butuh keputusan orang atau bukti yang belum ada)
 *
 * Tiap butir juga bertanda `#[Group('menunggu-keputusan-tm')]` dan mengunci keadaan sekarang:
 *
 *  - M-1  teks cetak `k` ("2" di aplikasi; sel Excel berformat General, teksnya tidak tersimpan).
 *  - M-2  jejak audit tidak menyebut selisih dari master: pembagi drift (B6-1) dan angka
 *         paralelisme master 0,001414 (B6-2); jejak hanya mencetak 0,002 (Max - Min).
 *  - M-3  pembagi muai sqrt(6) dan dua nilai alpha (1,2e-6 lawan 2e-6): aplikasi menyalin
 *         sel, tetapi mana yang mengikat belum dijawab lab (`docs/pertanyaan-lab-height-gauge.md`
 *         §2 dan §3). Nilainya sama dengan sel, tidak diberi label "sama" sebagai metode.
 *
 * ## Batas bukti (jangan dibaca lebih dari ini)
 *
 *  - Satu sesi, satu workbook, 10 titik masing-masing satu nominal Caliper Checker
 *    dan tiga pembacaan. Sesi dengan beberapa slot nominal per titik tidak terbukti.
 *  - Suhu standar = suhu UUT (`M35` = `N35`), jadi delta theta dan delta alpha titik
 *    bernilai nol: jalur suku termal TIDAK terbukti oleh data ini (hanya konstanta
 *    nol di kedua sisi). Empat suku deformasi `U35:X35` juga nol.
 *  - Teks cetak `k`: sel `SERTIFIKAT!O35` berformat General dan kolomnya sempit;
 *    cache workbook tidak menyimpan teks tampilan. Cetak "2" aplikasi bersandar pada
 *    PDF master, bukan pada workbook (lihat tes MENUNGGU `k`).
 *  - Tujuh dari sepuluh baris tabel hasil disembunyikan di lembar SERTIFIKAT (baris
 *    25:28 dan 30:32); yang tercetak Excel (titik 1, 6, 10) sama dengan
 *    `TITIK_DICETAK_SERTIFIKAT` aplikasi.
 *  - Teks cetak Excel di fixture (`SERTIFIKAT!D24:L33`, `J34`) adalah EMULASI generator
 *    (pembulatan setengah menjauhi nol pada 15 angka penting, lalu ke desimal format sel),
 *    bukan teks yang dibaca dari tampilan Excel: cache workbook tidak menyimpan teks tampilan.
 */
#[Group('paritas')]
class ParitasHeightGaugeTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Sembilan komponen: urutan sheet `PERHITUNGAN U95%` baris 5-13. */
    private const KOMPONEN = [
        'pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
        'drift_standar', 'geometri', 'meja_granit', 'selisih_suhu',
    ];

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('height-gauge')['workbook'];
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

    /** `DATABASE!X11` tersimpan, lengkap dengan pecahan detik. */
    private function x11(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::w()['excel']['drift']['x11_now_cache']['nilai']);
    }

    /** Pecahan detik `X11` dalam hari: bagian yang dipotong `umurStandarHari()`. */
    private function pecahanX11Hari(): float
    {
        return ((float) $this->x11()->format('u') / 1e6) / 86400.0;
    }

    /** Umur standar (hari) yang dipakai aplikasi pada X11: cache Excel dikurangi pecahan detik. */
    private function umurAplikasi(): float
    {
        return (float) self::w()['excel']['drift']['umur_hari_dari_cache'] - $this->pecahanX11Hari();
    }

    /**
     * Susun masukan persis seperti jalur produksi: blok `spesifikasi_alat` lewat
     * `HeightGaugeMentah::blokSesi`, pembacaan lewat `keMm`, suhu lewat `rataSuhuRuang`.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function masukan(DateTimeImmutable $tanggal): array
    {
        $w = self::w();
        $m = $w['masukan'];

        // Kapasitas dan resolusi yang DIKETIK di workbook (`INPUT DATA!E15`, `E16`); acuan
        // hasilnya sel `PERHITUNGAN!G8`, `G9`, jadi tes menguji jalur nyata, bukan sel yang sama.
        $blok = HeightGaugeMentah::blokSesi([HeightGaugeMentah::KUNCI_SESI => [
            'satuan' => 'mm',
            'kapasitas_mm' => self::nilai($m['kapasitas_mm']),
            'resolusi_mm' => self::nilai($m['resolusi_mm']),
            'paralelisme' => self::daftar($m['paralelisme_mm']),
            'pra_evaluasi' => self::daftar($m['evaluasi_mm']),
        ]]);

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'nominal' => [self::nilai($t['nominal_mm'])],
            'pembacaan' => array_map(
                static fn (float $x): float => HeightGaugeMentah::keMm($x, 'mm'),
                self::daftar($t['pembacaan_mm']),
            ),
        ], $m['titik']);

        return [$titik, $blok + [
            'tanggal_kalibrasi' => $tanggal,
            'suhu_ruang_rata_c' => HeightGaugeMentah::rataSuhuRuang(
                self::nilai($m['suhu_ruang_awal_c']),
                self::nilai($m['suhu_ruang_akhir_c']),
            ),
        ]];
    }

    /** @return array<string, mixed> */
    private function hitung(DateTimeImmutable $tanggal): array
    {
        [$titik, $konteks] = $this->masukan($tanggal);

        return (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);
    }

    /**
     * ui, ci, vi tiap komponen dari sel Excel (urutan baris 5-13), sebagian bisa diganti.
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

    /** Blok `spesifikasi_alat` sesi persis seperti yang disimpan jalur produksi. */
    private function spesifikasiAlat(): array
    {
        [, $k] = $this->masukan($this->x11());

        return [HeightGaugeMentah::KUNCI_SESI => [
            'satuan' => 'mm',
            'kapasitas_mm' => $k['kapasitas_mm'],
            'resolusi_mm' => $k['resolusi_mm'],
            'paralelisme' => $k['paralelisme'],
            'pra_evaluasi' => $k['pra_evaluasi'],
        ]];
    }

    private function sesi(): CalibrationSession
    {
        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = $this->spesifikasiAlat();

        return $sesi;
    }

    // ------------------------------------------------------------------ tahap masukan

    public function test_suhu_pra_evaluasi_kapasitas_dan_resolusi_cocok_excel(): void
    {
        $w = self::w();
        $dasar = $w['excel']['dasar'];
        [, $konteks] = $this->masukan($this->x11());
        $kalk = new HeightGaugeCalculator;

        $this->assertSamaExcel($w['masukan']['suhu_ruang_rata_c'], $konteks['suhu_ruang_rata_c'], 'suhu ruang rata-rata (°C)');
        $this->assertSamaExcel($dasar['suhu_rata_c'], $konteks['suhu_ruang_rata_c'], 'suhu ruang rata-rata di sheet perhitungan (°C)');
        $this->assertSamaExcel($dasar['kapasitas_perhitungan_mm'], $konteks['kapasitas_mm'], 'kapasitas (mm)');
        $this->assertSamaExcel($dasar['resolusi_perhitungan_mm'], $konteks['resolusi_mm'], 'resolusi (mm)');

        $this->assertCount(10, $konteks['pra_evaluasi'], 'sepuluh pembacaan blok Evaluation');
        foreach ($konteks['pra_evaluasi'] as $i => $x) {
            $this->assertSamaExcel(self::unsur($dasar['evaluasi_mm'], $i), $x, "pembacaan Evaluation ke-{$i} (mm)");
        }
        $this->assertSamaExcel($dasar['pengulangan_stdev_N30'], $kalk->simpanganBaku($konteks['pra_evaluasi']), 'STDEV blok Evaluation (mm)');

        foreach ($konteks['paralelisme'] as $i => $x) {
            $this->assertSamaExcel(self::unsur($dasar['paralelisme']['baca_mm'], $i), $x, "pembacaan paralelisme ke-{$i} (mm)");
        }

        // Panjang untuk koefisien sensitivitas: nominal terbesar sesi = PERHITUNGAN!C65.
        $nominalTerbesar = max(array_map(static fn (array $t): float => self::nilai($t['nominal_mm']), $w['masukan']['titik']));
        $this->assertSamaExcel($dasar['panjang_sensitivitas_mm_C65'], $nominalTerbesar, 'panjang sensitivitas = nominal terbesar sesi (mm)');
    }

    public function test_konstanta_tabel_standar_dan_caliper_checker_cocok_sel_workbook(): void
    {
        $w = self::w();
        $e = $w['excel'];
        $dasar = $e['dasar'];
        $tabel = new TabelStandarHeightGauge;
        $k = $tabel->konstanta();
        $komp = $e['budget']['komponen'];
        $termal = $e['titik'][0]['suku_termal'];

        // Dua nilai alpha dan pembagi muai sqrt(6) TIDAK diadu di sini: nilainya sama dengan sel,
        // tetapi mana yang mengikat belum dijawab lab (M-3, tes menunggu di bawah).
        $this->assertSamaExcel($komp['geometri']['u_mentah'], (float) $k['geometri_mm'], 'kesalahan geometri (mm)');
        $this->assertSamaExcel($komp['meja_granit']['u_mentah'], (float) $k['meja_granit_mm'], 'meja granit (mm)');
        $this->assertSamaExcel($komp['geometri']['vi'], (float) $k['vi_type_b_rect'], 'vi Type B rectangular');
        $this->assertSamaExcel($komp['meja_granit']['vi'], (float) $k['vi_type_b_normal'], 'vi Type B normal');
        $this->assertSamaExcel(
            $termal['theta_c_O35'],
            self::nilai($w['masukan']['suhu_ruang_rata_c']) - (float) $k['suhu_acuan_c'],
            'theta = suhu ruang - suhu acuan (°C)',
        );

        // Konstanta drift hanya tertulis di dalam rumus `K10`, bukan di sel sendiri.
        $this->assertStringContainsString(
            sprintf('(%s+%s*PERHITUNGAN!C65)', $k['drift_a_mm'], $k['drift_b_mm_per_mm']),
            $e['drift']['k10_rumus'],
            'konstanta drift tabel vs rumus '.$e['budget']['komponen']['drift_standar']['u_mentah']['sel'],
        );

        // Caliper Checker: ketidakpastian standar (µm) dan tanggal kalibrasi (titik nol umur drift).
        $this->assertSamaExcel($dasar['caliper_checker_u95_um_J19'], (float) $tabel->standar()['u95_um'], 'U95 Caliper Checker (µm)');
        $this->assertSamaExcel($komp['ketidakpastian_standar']['u_mentah'], (float) $tabel->standar()['u95_um'] / 1000.0, 'U95 Caliper Checker (mm)');
        $this->assertSamaExcel($komp['ketidakpastian_standar']['ui'], $tabel->ketidakpastianStandarMm(), 'u standar = U95 / 2 (mm)');
        $this->assertSame(
            substr($e['drift']['w13_tanggal_kalibrasi_standar']['nilai'], 0, 10),
            $tabel->standar()['tanggal_kalibrasi'],
            'tanggal kalibrasi standar tabel vs '.$e['drift']['w13_tanggal_kalibrasi_standar']['sel'],
        );

        // Nilai terkoreksi sepuluh nominal Caliper Checker (`Std_CaliperCek!F10:F19`).
        foreach (self::daftar($dasar['caliper_checker_nominal_mm']) as $i => $nominal) {
            $terkoreksi = $tabel->nilaiTerkoreksi($nominal);
            $this->assertNotNull($terkoreksi, "nominal {$nominal} mm tidak ada di tabel Caliper Checker");
            $this->assertSamaExcel(self::unsur($dasar['caliper_checker_terkoreksi_mm'], $i), $terkoreksi, "nilai terkoreksi Caliper Checker {$nominal} mm");
        }
    }

    // ------------------------------------------------------------------ tahap titik

    public function test_titik_total_nominal_rata_rata_standar_koreksi_dan_stdev_cocok_excel(): void
    {
        $w = self::w();
        $h = $this->hitung($this->x11());
        $kalk = new HeightGaugeCalculator;
        $app = collect($h['titik'])->keyBy('titik_ke');

        $this->assertSame([], $h['ditolak'], 'sesi master tidak boleh melahirkan penolakan');
        $this->assertCount(count($w['excel']['titik']), $h['titik']);

        foreach ($w['excel']['titik'] as $t) {
            $ke = "titik {$t['titik_ke']}";
            $baris = $app[$t['titik_ke']] ?? null;
            $this->assertNotNull($baris, "{$ke} tidak terhitung di aplikasi");

            $this->assertSamaExcel($t['nominal_mm'], $baris['nominal'][0], "{$ke}: nominal Caliper Checker (mm)");
            $this->assertSame(1, (int) self::nilai($t['jumlah_nominal']), "{$ke}: jumlah slot nominal Excel (fixture)");
            $this->assertCount(1, $baris['nominal'], "{$ke}: jumlah slot nominal aplikasi");

            $this->assertSamaExcel($t['standar_terkoreksi_sertifikat_mm'], (float) $kalk->totalNominal($baris['nominal']), "{$ke}: nilai terkoreksi Caliper Checker (mm)");
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
     * Sembilan komponen dari `hitungSesi()` (budget aplikasi memang privat) pada
     * tanggal X11: urutan, ui (= `u` aplikasi), ci, vi, dan ui*ci. Satu komponen
     * sengaja TIDAK diadu pada ui: drift (B6-1), yang punya tes tersendiri.
     */
    public function test_sembilan_komponen_budget_cocok_excel_kecuali_ui_drift(): void
    {
        $e = self::w()['excel'];
        $h = $this->hitung($this->x11());
        $budget = collect($h['budget']);

        $this->assertSame(self::KOMPONEN, $budget->pluck('sumber')->all(), 'urutan sembilan komponen = baris 5-13 sheet PERHITUNGAN U95%');
        $this->assertCount(9, $e['budget']['komponen'], 'Excel: sembilan komponen');

        // Pembagi keterulangan master = akar jumlah bacaan Evaluation (N5 = sqrt(10)).
        $this->assertSamaExcel($e['budget']['komponen']['pengulangan']['pembagi'], sqrt(10.0), 'pembagi pengulangan = akar bacaan Evaluation');
        $this->assertSamaExcel($e['budget']['komponen']['pengulangan']['u_mentah'], (new HeightGaugeCalculator)->simpanganBaku(self::daftar($e['dasar']['evaluasi_mm'])), 'u mentah pengulangan = STDEV Evaluation');

        foreach ($budget->keyBy('sumber') as $sumber => $b) {
            $x = $e['budget']['komponen'][$sumber];

            $this->assertSamaExcel($x['ci'], $b['ci'], "{$sumber}: ci");
            $this->assertSamaExcel($x['vi'], $b['vi'], "{$sumber}: vi");

            if ($sumber === 'drift_standar') {
                continue;
            }

            $this->assertSamaExcel($x['ui'], $b['u'], "{$sumber}: ui = u/pembagi");
            $this->assertSamaExcel($x['uici'], $b['u'] * $b['ci'], "{$sumber}: ui*ci");
        }
    }

    /** Umur aplikasi pada X11 = umur cache Excel dikurangi pecahan detik yang dipotong `getTimestamp()`. */
    public function test_umur_standar_pada_x11_cocok_hitung_ulang_cache_excel(): void
    {
        $drift = self::w()['excel']['drift'];
        $umur = (new HeightGaugeCalculator)->umurStandarHari($this->x11());

        $this->assertNotNull($umur);
        $this->assertGreaterThan(0.0, $this->pecahanX11Hari(), 'X11 = NOW() membawa pecahan detik');
        $this->assertSamaHitungUlang($this->umurAplikasi(), $umur, 'umur standar (hari) = '.$drift['x11_now_cache']['sel'].' - '.$drift['w13_tanggal_kalibrasi_standar']['sel'].', pecahan detik dipotong');
        $this->assertSamaHitungUlang($this->pecahanX11Hari(), (float) $drift['umur_hari_dari_cache'] - $umur, 'selisih umur Excel - aplikasi = pecahan detik X11 (hari)');
    }

    // ------------------------------------------------------------------ tahap agregat

    /**
     * uc, v_eff, k, U95: agregasi aplikasi (`GumCalculator`) diberi ui, ci, vi persis
     * dari sel Excel. Terpisah dari selisih komponen: yang diadu di sini hanya rantai
     * agregasi `PERHITUNGAN U95%!AA14:AA20`. Tanpa lantai CMC di kedua sisi
     * (`AA19` kosong, `AA20` = `AA18` / `T23`).
     */
    public function test_agregasi_uc_veff_k_dan_u95_cocok_excel_dari_komponen_excel(): void
    {
        $e = self::w()['excel'];
        $ag = $e['budget']['agregat'];
        $a = $this->agregat($this->komponenExcel());

        $this->assertSamaExcel($ag['jumlah_uici_kuadrat'], $a['ketidakpastian_gabungan'] ** 2, 'jumlah (ui*ci)^2');
        $this->assertSamaExcel($ag['uc'], $a['ketidakpastian_gabungan'], 'uc');
        $this->assertSamaExcel($ag['veff'], (float) $a['derajat_kebebasan_efektif'], 'v_eff');
        $this->assertKSamaExcel($ag['k'], $a['faktor_cakupan_k'], 'k');
        $this->assertUSamaExcel($ag['u_hitung'], $a['ketidakpastian_diperluas'], 'U95 (mm)');
        $this->assertSamaExcel($e['dasar']['pembagi_satuan_T23'], 1.0, 'pembagi satuan mm = 1');
        $this->assertSamaExcel($ag['u_hitung'], self::nilai($ag['u_cetak']), 'U95 dicetak = U95 hitung (tanpa lantai CMC)', 0.0, 0.0);
    }

    // ------------------------------------------------------------------ selisih bernomor (menunggu keputusan TM)

    /**
     * SELISIH B6-1. Umur drift: aplikasi membagi umur (hari) dengan 365; master
     * `K10` membaginya 12. Komponen drift aplikasi = `K10` x 12/365 (paket keputusan
     * 16 Sep 2026, paraf Manajer Teknis menyusul; catatan di `HeightGaugeCalculator::budget`).
     *
     * Akibatnya U95 aplikasi lebih KECIL dari Excel (arah tidak aman) dan tercetak
     * 0,015 lawan Excel 0,016 pada `SERTIFIKAT!J34`. Alat ini tidak punya lantai CMC
     * yang menahan. Aplikasi TIDAK menahan penerbitan karena selisih ini (lihat tes B6-4,
     * `boleh_terbit`); keputusan menahan atau menarik sertifikat ada pada pemilik dan
     * Manajer Teknis, bukan pada tes ini.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_b6_1_drift_pembagi_365_aplikasi_12_excel(): void
    {
        $e = self::w()['excel'];
        $x = $e['budget']['komponen']['drift_standar'];
        $k = (new TabelStandarHeightGauge)->konstanta();
        $b = $this->budgetAplikasi()['drift_standar'];

        $koefisien = ((float) $k['drift_a_mm'] + (float) $k['drift_b_mm_per_mm'] * self::nilai($e['dasar']['panjang_sensitivitas_mm_C65'])) / 1000.0;
        $umurExcel = (float) $e['drift']['umur_hari_dari_cache'];

        $this->assertSame(12, $e['drift']['pembagi_master'], 'pembagi master pada K10');
        $this->assertSame(365.0, HeightGaugeCalculator::PEMBAGI_UMUR_HARI, 'pembagi aplikasi');
        $this->assertSamaExcel($e['drift']['k10_cache'], $koefisien * $umurExcel / 12.0, 'K10 = (a + b L)/1000 x umur / 12 (hitung ulang)');
        $this->assertSamaExcel($x['ui'], $koefisien * $umurExcel / 12.0 / sqrt(3.0), 'ui drift Excel = K10 / sqrt 3');
        $this->assertSamaHitungUlang($koefisien * $this->umurAplikasi() / 365.0 / sqrt(3.0), $b['u'], 'ui drift aplikasi = (a + b L)/1000 x umur / 365 / sqrt 3');
        $this->assertSamaHitungUlang(
            365.0 / 12.0 * $umurExcel / $this->umurAplikasi(),
            self::nilai($x['ui']) / $b['u'],
            'rasio ui drift Excel / aplikasi = 365/12 x (umur Excel / umur aplikasi)',
        );

        // Akibat pada agregat dengan komponen lain tetap dari Excel.
        $hanyaDrift = $this->agregat($this->komponenExcel(['drift_standar' => ['u' => $b['u']]]));
        $this->assertLessThan(self::nilai($e['budget']['agregat']['u_hitung']), $hanyaDrift['ketidakpastian_diperluas'], 'U aplikasi lebih kecil dari Excel (arah tidak aman)');

        $formatU = (new HeightGaugeProfile)->formatKolomSertifikat(new CalibrationSession, 0.0)['u95'];
        $this->assertSame($e['cetak']['u95']['format'], $formatU, 'format sel '.$e['cetak']['u95']['sel']);
        $this->assertSame('0.016', $e['cetak']['u95']['teks'], 'cetak Excel '.$e['cetak']['u95']['sel']);
        $this->assertSame('0.015', self::keTitik(TataLetakSertifikat::formatAngka($hanyaDrift['ketidakpastian_diperluas'], $formatU, false)), 'cetak aplikasi');
    }

    /**
     * SELISIH B6-2. Paralelisme ujung scriber: aplikasi `Max - Min` = 0,002 mm; Excel
     * `J24 = STDEV(I23:I24)` = STDEV(Max; Min) = 0,001414 mm. Maks dan Min sama; vonis
     * sama ("Good", batas 0,01 mm dari rumus `K25`). Sertifikat mencetak vonisnya saja;
     * jejak audit mencetak 0,002 (Max - Min) tanpa menyebut angka master 0,001414 atau
     * alasan bedanya (M-2, tes `test_menunggu_jejak_audit_paralelisme_...`).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_b6_2_paralelisme_max_min_aplikasi_stdev_excel(): void
    {
        $p = self::w()['excel']['dasar']['paralelisme'];
        $hasil = (new HeightGaugeCalculator)->paralelisme(self::daftar(self::w()['masukan']['paralelisme_mm']));

        $this->assertNotNull($hasil);
        $this->assertSamaExcel($p['maks_I23'], $hasil['maks'], 'paralelisme Max (mm)');
        $this->assertSamaExcel($p['min_I24'], $hasil['min'], 'paralelisme Min (mm)');

        // Dua sisi dikunci apa adanya.
        $this->assertSamaHitungUlang(0.002, $hasil['hasil'], 'aplikasi: Max - Min (mm)');
        $this->assertSamaExcel($p['stdev_J24'], M_SQRT2 * 0.001, 'Excel: STDEV(Max; Min) = (Max - Min) / sqrt 2 (hitung ulang)');
        $this->assertNotEquals(self::nilai($p['stdev_J24']), $hasil['hasil'], 'angka paralelisme aplikasi tidak sama dengan Excel');

        // Vonis sama di kedua sisi, dan batasnya tertulis di rumus K25.
        $this->assertStringContainsString(sprintf('J24<=%s', $hasil['batas']), $p['vonis_K25_rumus']['nilai'], 'batas aplikasi vs rumus '.$p['vonis_K25_rumus']['sel']);
        $this->assertSame($p['vonis_K25']['nilai'], $hasil['lulus'] ? 'Good' : 'Not Good', 'vonis '.$p['vonis_K25']['sel']);
    }

    /**
     * SELISIH B6-3 (beda struktur, TANPA efek angka pada data ini). Suku termal
     * standar terkoreksi `Y = H + H x (S x T + O x R) - U - V - W - X`: di Excel sel
     * `M:T` hanya terisi di baris titik 1 (`M35:T35`), baris lain berisi `H` apa
     * adanya; aplikasi menghitung suku itu untuk sepuluh titik.
     *
     * Pada data ini T35 (delta theta) dan R35 (delta alpha) bernilai nol, jadi kedua
     * sisi menghasilkan `H`. Beda baru muncul pada sesi dengan suhu UUT yang diukur
     * terpisah (`suhu_uut_c`); itu hanya didemonstrasikan di sisi aplikasi, karena
     * workbook tidak punya sesi seperti itu.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_b6_3_suku_termal_excel_hanya_titik_1_aplikasi_sepuluh_titik(): void
    {
        $e = self::w()['excel'];
        $termal = $e['titik'][0]['suku_termal'];
        $h = $this->hitung($this->x11());

        $terisi = array_values(array_map(
            static fn (array $t): int => $t['titik_ke'],
            array_filter($e['titik'], static fn (array $t): bool => $t['suku_termal_terisi']['nilai'] === true),
        ));
        $this->assertSame([1], $terisi, 'Excel: suku termal M:T hanya terisi di titik 1');

        $this->assertSamaExcel($termal['delta_theta_c_T35'], 0.0, 'delta theta nol (suhu standar = suhu UUT)');
        $this->assertSamaExcel($termal['delta_alpha_per_c_R35'], 0.0, 'delta alpha nol (alpha standar = alpha UUT)');

        foreach ($e['titik'] as $t) {
            $ke = "titik {$t['titik_ke']}";
            $this->assertSamaExcel($t['total_nominal_mm'], self::nilai($t['standar_terkoreksi_mm']), "{$ke}: Excel Y = H (tanpa suku termal bernilai)", 0.0, 0.0);
        }
        foreach ($h['titik'] as $baris) {
            $this->assertSame($baris['total_nominal'], $baris['standar_terkoreksi'], "titik {$baris['titik_ke']}: aplikasi standar terkoreksi = total nominal (suku termal nol)");
        }

        // Demonstrasi sisi aplikasi: suhu UUT 0,5 °C di atas suhu standar menggeser SEMUA titik.
        [$titik, $konteks] = $this->masukan($this->x11());
        $geser = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks + ['suhu_uut_c' => $konteks['suhu_ruang_rata_c'] + 0.5]);
        foreach ($geser['titik'] as $baris) {
            $this->assertGreaterThan(0.0, abs($baris['standar_terkoreksi'] - $baris['total_nominal']), "titik {$baris['titik_ke']}: aplikasi menerapkan suku termal pada sesi bersuhu UUT beda");
        }
    }

    /**
     * Rantai penuh `hitungSesi()` pada X11: U95 aplikasi di bawah Excel dan selisihnya
     * HABIS dijelaskan B6-1: agregasi dari komponen Excel yang hanya diganti drift-nya
     * sama dengan hasil aplikasi (uc, v_eff, k, U). Kalau tes ini merah sementara tes
     * B6-1 hijau, ada selisih lain yang belum bernomor.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_selisih_b6_4_rantai_penuh_u95_dijelaskan_oleh_b6_1(): void
    {
        $e = self::w()['excel'];
        $ag = $e['budget']['agregat'];
        $h = $this->hitung($this->x11());
        $budget = collect($h['budget'])->keyBy('sumber');

        $penjelasan = $this->agregat($this->komponenExcel(['drift_standar' => ['u' => $budget['drift_standar']['u']]]));

        $this->assertSamaHitungUlang($penjelasan['ketidakpastian_gabungan'], $h['ketidakpastian_gabungan'], 'uc aplikasi = uc dari komponen Excel + B6-1');
        $this->assertSamaHitungUlang((float) $penjelasan['derajat_kebebasan_efektif'], (float) $h['derajat_kebebasan_efektif'], 'v_eff aplikasi');
        $this->assertSamaHitungUlang($penjelasan['faktor_cakupan_k'], $h['faktor_cakupan_k'], 'k aplikasi', 0.0, self::TOL_K);
        $this->assertSamaHitungUlang($penjelasan['ketidakpastian_diperluas'], $h['ketidakpastian_diperluas'], 'U95 aplikasi (mm)', self::TOL_K_REL);

        // Arah, dan akibatnya pada derajat bebas dan k: v_eff turun melewati satu bilangan bulat.
        $this->assertLessThan(self::nilai($ag['u_hitung']), $h['ketidakpastian_diperluas'], 'U95 aplikasi < Excel '.$ag['u_hitung']['sel']);
        $this->assertLessThan(self::nilai($ag['veff']), (float) $h['derajat_kebebasan_efektif'], 'v_eff aplikasi < Excel '.$ag['veff']['sel']);
        $this->assertSame(20, (int) floor(self::nilai($ag['veff'])), 'Excel: floor(v_eff)');
        $this->assertSame(19, (int) floor((float) $h['derajat_kebebasan_efektif']), 'aplikasi: floor(v_eff)');
        $this->assertGreaterThan(self::nilai($ag['k']), $h['faktor_cakupan_k'], 'k aplikasi > Excel (v_eff lebih kecil)');
        $this->assertSame($h['ketidakpastian_diperluas'], $h['u95_sertifikat'], 'tanpa lantai CMC: U95 sertifikat = U95');
        // Mengunci perilaku SEKARANG: aplikasi tidak menahan penerbitan karena B6-1 (keputusan
        // pemilik 16 Sep 2026, paraf Manajer Teknis menyusul). Ini BUKAN pernyataan bahwa B6-1
        // aman: U cetak dan v_eff sudah beda dari Excel di atas. Keputusan menahan atau
        // menarik sertifikat Height Gauge yang terbit ada pada pemilik dan TM.
        $this->assertTrue($h['boleh_terbit'], 'perilaku sekarang: sesi master boleh terbit walau B6-1 menggeser U cetak dan v_eff (keputusan pemilik 16 Sep 2026, paraf TM menyusul)');
        $this->assertSame([], $h['ditolak']);
    }

    /**
     * Jejak audit `type_b_components`: AGENTS.md butir 4 Olah data meminta penyimpangan
     * dari master tercatat di jejak sesi beserta sumbernya. Kelas kalkulator mengaku
     * "pembagi master tetap dicetak di jejak audit", tetapi baris jejak yang ada tidak
     * menyebut pembagi 12 / K10 sama sekali. Dikunci apa adanya: kalau catatannya
     * ditambahkan, tes ini merah dan docs/paritas-batch6.md diperbarui.
     *
     * M-2 bagian (a): pembagi drift (B6-1). Bagian (b), paralelisme (B6-2), ada di tes
     * berikutnya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_menunggu_jejak_audit_tidak_menyebut_pembagi_master_drift(): void
    {
        $hasil = (new HeightGaugeProfile)->hitungPerGrup($this->titikProfil(), $this->alat());
        $jejak = $hasil['hitungan'][0]['type_b_components'];

        $this->assertContains('drift_standar', array_column($jejak, 'sumber'));

        $teks = implode("\n", array_map(static fn (array $b): string => (string) ($b['keterangan'] ?? ''), $jejak));
        $this->assertStringNotContainsString('K10', $teks, 'jejak audit belum menyebut sel master K10');
        $this->assertStringNotContainsString('/12', $teks, 'jejak audit belum menyebut pembagi master 12');
        $this->assertStringNotContainsString('365', $teks, 'jejak audit belum menyebut pembagi aplikasi 365');
    }

    /**
     * M-2 bagian (b), B6-2. Baris `jejak_titik` mencetak paralelisme sesi dari aplikasi
     * (`Max - Min` = 0,002 mm, "Good"), tetapi tidak menyebut angka master 0,001414
     * (`STDEV(Max; Min)`, `PERHITUNGAN!J24`) maupun alasan bedanya. Orang yang membuka
     * jejak sesi karena itu tidak bisa tahu dari jejak saja bahwa angka paralelisme
     * beda dari master. Dikunci apa adanya: kalau catatannya ditambahkan, tes ini merah
     * dan docs/paritas-batch6.md diperbarui.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_menunggu_jejak_audit_paralelisme_hanya_max_min_tanpa_angka_master(): void
    {
        $p = self::w()['excel']['dasar']['paralelisme'];
        $hasil = (new HeightGaugeProfile)->hitungPerGrup($this->titikProfil(), $this->alat());
        $baris = collect($hasil['hitungan'][0]['type_b_components'])->firstWhere('sumber', 'jejak_titik');

        $this->assertNotNull($baris, 'jejak audit memuat baris jejak_titik');
        $keterangan = (string) $baris['keterangan'];

        // Yang tercetak: angka aplikasi (Max - Min) beserta vonisnya.
        $this->assertStringContainsString('paralelisme sesi 0.002 mm (Good)', $keterangan, 'jejak mencetak Max - Min aplikasi dan vonis');

        // Yang belum tercetak: angka master, nama rumus master, dan alasan beda.
        $this->assertNotEquals(self::nilai($p['stdev_J24']), 0.002, 'angka master memang beda dari angka aplikasi');
        $this->assertStringNotContainsString('0.0014', $keterangan, 'jejak audit belum menyebut angka master '.$p['stdev_J24']['sel']);
        $this->assertStringNotContainsString('STDEV', $keterangan, 'jejak audit belum menyebut rumus master STDEV(Max; Min)');
        $this->assertStringNotContainsString('J24', $keterangan, 'jejak audit belum menyebut sel master J24');
    }

    // ------------------------------------------------------------------ jalur profil (preview = simpan = hitung ulang)

    /**
     * `HeightGaugeProfile::hitungPerGrup()` — fungsi yang sama dengan preview, simpan,
     * dan `HitungUlangSesi`. Kolom yang tidak bergantung umur drift diadu ke Excel;
     * kolom ketidakpastian diadu ke `hitungSesi()` pada tanggal yang sama (profil
     * memakai tanggal `Y-m-d`, jadi umur drift beda dari X11 dan Excel tidak dipakai
     * sebagai acuan U95 di sini).
     */
    public function test_jalur_profil_hitung_per_grup_cocok_excel_dan_hitung_sesi(): void
    {
        $w = self::w();
        $profil = new HeightGaugeProfile;
        $hasil = $profil->hitungPerGrup($this->titikProfil(), $this->alat());
        $acuan = $this->hitung(new DateTimeImmutable($this->x11()->format('Y-m-d')));

        $this->assertFalse($profil->punyaToleransi(), 'Height Gauge tidak divonis PASS/FAIL (master berhenti di Correction + U95)');
        $this->assertSame([], $hasil['belum_dihitung'], 'tidak boleh ada titik yang ditahan');
        $this->assertCount(count($w['excel']['titik']), $hasil['hitungan']);

        foreach ($hasil['hitungan'] as $baris) {
            $t = collect($w['excel']['titik'])->firstWhere('titik_ke', $baris['titik_ke']);
            $ke = "titik {$baris['titik_ke']}";

            $this->assertSamaExcel($t['total_nominal_mm'], $baris['titik_ukur'], "{$ke}: kolom titik_ukur = total nominal terkoreksi (mm)");
            $this->assertSamaExcel($t['rata_rata_mm'], $baris['rata_rata'], "{$ke}: kolom rata_rata (mm)");
            $this->assertSamaExcel($t['koreksi_mm'], $baris['koreksi'], "{$ke}: kolom koreksi (mm)");
            $this->assertSamaHitungUlang(-self::nilai($t['koreksi_mm']), $baris['error'], "{$ke}: error = -koreksi");
            $this->assertSamaExcel($t['simpangan_baku_mm'], $baris['standar_deviasi'], "{$ke}: STDEV (mm)");
            $this->assertSame(3, $baris['jumlah_pengulangan'], "{$ke}: jumlah pengulangan");

            $this->assertSamaHitungUlang($acuan['ketidakpastian_gabungan'], $baris['ketidakpastian_gabungan'], "{$ke}: uc = hitungSesi (mm)");
            $this->assertSamaHitungUlang((float) $acuan['derajat_kebebasan_efektif'], (float) $baris['derajat_kebebasan_efektif'], "{$ke}: v_eff = hitungSesi");
            $this->assertSamaHitungUlang($acuan['faktor_cakupan_k'], $baris['faktor_cakupan_k'], "{$ke}: k = hitungSesi", 0.0, self::TOL_K);
            $this->assertSamaHitungUlang($acuan['u95_sertifikat'], $baris['ketidakpastian_diperluas'], "{$ke}: U95 sertifikat = hitungSesi (mm)", self::TOL_K_REL);
            $this->assertNull($baris['toleransi'], "{$ke}: tanpa toleransi");
            $this->assertNull($baris['keputusan'], "{$ke}: tanpa vonis");
        }
    }

    private function alat(): Equipment
    {
        return new Equipment;
    }

    /**
     * Titik dalam bentuk yang disusun jalur simpan untuk `hitungPerGrup()`: nominal
     * (`hg_nominal`), pembacaan (`hg_pembacaan`, mm), blok `spesifikasi_alat`. Tanggal
     * kalibrasi = tanggal X11 (hari penuh, format `Y-m-d` seperti jalur produksi).
     *
     * @return list<array<string, mixed>>
     */
    private function titikProfil(): array
    {
        [$titik, $konteksSesi] = $this->masukan($this->x11());

        return array_map(fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => 0.0,
            'pembacaan' => [],
            'standard' => null,
            'suhu_larutan' => null,
            'konteks' => [
                HeightGaugeMentah::PERAN_NOMINAL => $t['nominal'],
                HeightGaugeMentah::PERAN_PEMBACAAN => $t['pembacaan'],
                'spesifikasi_alat' => $this->spesifikasiAlat(),
                'tanggal_kalibrasi' => $konteksSesi['tanggal_kalibrasi']->format('Y-m-d'),
                'suhu_ruang_rata' => $konteksSesi['suhu_ruang_rata_c'],
            ],
        ], $titik);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Baris yang tercetak di sertifikat: Excel menyembunyikan baris `25:28` dan `30:32`
     * lembar SERTIFIKAT, jadi hanya titik 1, 6, 10 (baris 24, 29, 33) yang tampil.
     * Aplikasi mencetak daftar yang sama (`TITIK_DICETAK_SERTIFIKAT`).
     */
    public function test_titik_yang_dicetak_sama_dengan_baris_yang_tampil_di_sertifikat_excel(): void
    {
        $profil = new HeightGaugeProfile;
        $sesi = new CalibrationSession;
        $tampilExcel = [];
        $tampilAplikasi = [];

        foreach (self::w()['excel']['cetak']['titik'] as $c) {
            if ($c['tersembunyi']['nilai'] === false) {
                $tampilExcel[] = $c['titik_ke'];
            }
            if ($profil->titikDicetakSertifikat($sesi, self::nilai($c['standar']), $c['titik_ke'])) {
                $tampilAplikasi[] = $c['titik_ke'];
            }
        }

        $this->assertSame([1, 6, 10], $tampilExcel, 'baris SERTIFIKAT yang tidak disembunyikan');
        $this->assertSame($tampilExcel, $tampilAplikasi, 'titik tercetak aplikasi = baris tampil Excel');
    }

    /**
     * Angka CETAK per titik: teks persis sama. Format per kolom = format sel workbook
     * (`SERTIFIKAT!D24:L33`) dan teks dihasilkan renderer produksi
     * (`TataLetakSertifikat::formatAngka`) dengan format dari profil. Kesepuluh baris
     * diadu (yang disembunyikan Excel ikut, sebagai bukti pembulatan sama); yang
     * sungguh tercetak hanya titik 1, 6, 10 (lihat tes sebelumnya). Teks sisi Excel di
     * fixture adalah emulasi generator (`teks_excel()`, bukan tampilan Excel); kesetaraan
     * ini terbukti sejauh emulasi itu setia pada pembulatan Excel.
     */
    public function test_angka_cetak_titik_sama_dengan_sertifikat_excel(): void
    {
        $e = self::w()['excel'];
        $h = $this->hitung($this->x11());
        $app = collect($h['titik'])->keyBy('titik_ke');
        $profil = new HeightGaugeProfile;
        $sesi = new CalibrationSession;
        $tandaNol = $profil->tandaNolDicetak();

        $this->assertCount(10, $e['cetak']['titik']);
        $this->assertSame($e['cetak']['satuan']['nilai'], HeightGaugeProfile::SATUAN, 'satuan tercetak '.$e['cetak']['satuan']['sel']);

        foreach ($e['cetak']['titik'] as $c) {
            $format = $profil->formatKolomSertifikat($sesi, self::nilai($c['standar']));
            $ke = "titik {$c['titik_ke']}";

            foreach (['standar' => 'standar_terkoreksi', 'uut' => 'rata_rata', 'koreksi' => 'koreksi'] as $kolom => $kunci) {
                $this->assertSame($c[$kolom]['format'], $format[$kolom] ?? null, "{$ke} {$kolom}: format sel {$c[$kolom]['sel']}");
                $this->assertFalse($c[$kolom]['nol_bertanda_minus'], "{$ke} {$kolom}: tidak ada nol bertanda minus di sesi ini");

                $teksApp = self::keTitik(TataLetakSertifikat::formatAngka($app[$c['titik_ke']][$kunci], $format[$kolom], $tandaNol));

                $this->assertSame($c[$kolom]['teks'], $teksApp, "{$ke} {$kolom}: teks cetak vs {$c[$kolom]['sel']} ({$c[$kolom]['nilai']})");
            }
        }

        $this->assertSame('0.000', $profil->formatKolomSertifikat($sesi, 0.0)['u95'], 'format U95 = format sel '.$e['cetak']['u95']['sel']);
        $this->assertSame($e['cetak']['u95']['format'], $profil->formatKolomSertifikat($sesi, 0.0)['u95']);
    }

    /**
     * Vonis paralelisme yang dicetak di blok `Note :` memakai fungsi yang sama dengan
     * peringatan sesi; Excel mencetaknya di `SERTIFIKAT!H38` dan menghitungnya di `K25`.
     */
    public function test_vonis_paralelisme_tercetak_sama_dengan_sertifikat_excel(): void
    {
        $e = self::w()['excel'];
        $catatan = (new HeightGaugeProfile)->catatanSertifikat($this->sesi());

        $this->assertCount(1, $catatan);
        $this->assertSame('Note :', $catatan[0]['judul']);
        $this->assertSame([['Result of Paralelism :', $e['cetak']['vonis_paralelisme']['nilai']]], $catatan[0]['tabel'], 'vonis tercetak vs '.$e['cetak']['vonis_paralelisme']['sel']);
        $this->assertSame($e['dasar']['paralelisme']['vonis_K25']['nilai'], $e['cetak']['vonis_paralelisme']['nilai'], 'vonis sertifikat = vonis perhitungan');
    }

    /**
     * MENUNGGU. Teks cetak faktor cakupan `k`. Aplikasi mencetak "2" (format `0`,
     * bersandar pada PDF master `sertifikat.terang.pdf`: kolom `O` selebar 2,44
     * karakter menampilkan `2.0859...` sebagai `2`). Sel Excel `SERTIFIKAT!O35`
     * berformat `General` dan cache workbook tidak menyimpan teks tampilan, jadi
     * kesetaraan teks cetak `k` TIDAK terbukti dari workbook; hanya format sel dan
     * nilainya yang bisa diadu. Dikunci apa adanya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_menunggu_teks_cetak_k_aplikasi_2_excel_general_tidak_terbukti(): void
    {
        $e = self::w()['excel'];
        $k = $e['cetak']['k'];
        $profil = new HeightGaugeProfile;
        $format = $profil->formatKolomSertifikat(new CalibrationSession, 0.0)['k'];
        $h = $this->hitung($this->x11());

        $this->assertSame('General', $k['format'], 'format sel '.$k['sel']);
        $this->assertNull($k['teks'], 'teks tampilan sel tidak tersimpan di cache workbook');
        $this->assertSamaExcel($k, self::nilai($e['budget']['agregat']['k']), 'nilai sel k tercetak = k hitung', 0.0, 0.0);
        $this->assertSame('0', $format, 'format k aplikasi');
        $this->assertSame('2', self::keTitik(TataLetakSertifikat::formatAngka(self::nilai($k), $format, $profil->tandaNolDicetak())), 'cetak aplikasi dengan k Excel');
        $this->assertSame('2', self::keTitik(TataLetakSertifikat::formatAngka($h['faktor_cakupan_k'], $format, $profil->tandaNolDicetak())), 'cetak aplikasi dengan k aplikasi');
    }

    /**
     * MENUNGGU (M-3). Pembagi komponen koefisien muai dan dua nilai alpha. Aplikasi
     * menyalin master apa adanya, jadi NILAI aplikasi sama dengan sel; yang belum
     * terjawab adalah apakah nilai-nilai itu BENAR (`docs/pertanyaan-lab-height-gauge.md`
     * §2 dan §3). Tes ini mengunci keadaan sekarang dan tidak menyebutnya "sama" sebagai
     * metode:
     *
     *  - `PERHITUNGAN U95%!N9` = sqrt(6), padahal komponen rectangular lain (geometri `N11`)
     *    memakai sqrt(3);
     *  - alpha 1,2e-6 /C di `PERHITUNGAN!P35`/`Q35` (suku koreksi titik) lawan delta alpha
     *    2e-6 /C di `PERHITUNGAN!S24` = 2 x `R24` (budget komponen muai, ci suhu).
     *
     * Efek ke U pada data ini kecil: komponen muai menyumbang 1,5e-8 dari jumlah (ui*ci)^2
     * 5,64e-5 (`AA9` dari `AA14`), dan suku koreksi muai titik bernilai nol (B6-3).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_menunggu_m3_pembagi_muai_sqrt6_dan_dua_nilai_alpha_dikunci_apa_adanya(): void
    {
        $e = self::w()['excel'];
        $komp = $e['budget']['komponen'];
        $termal = $e['titik'][0]['suku_termal'];
        $k = (new TabelStandarHeightGauge)->konstanta();

        // Aplikasi = sel workbook (disalin dari master).
        $this->assertSamaExcel($termal['alpha_standar_per_c_P35'], (float) $k['alpha_per_c'], 'alpha standar');
        $this->assertSamaExcel($termal['alpha_uut_per_c_Q35'], (float) $k['alpha_per_c'], 'alpha UUT');
        $this->assertSamaExcel($e['dasar']['delta_alpha_per_c_S24'], (float) $k['delta_alpha_per_c'], 'delta alpha (komponen koefisien muai)');
        $this->assertSamaExcel($komp['koefisien_muai']['pembagi'], (float) $k['pembagi_muai'], 'pembagi koefisien muai (sqrt 6)');

        // Yang membuatnya pertanyaan lab: dua nilai berbeda untuk besaran sejenis, dan pembagi
        // muai bukan sqrt(3) seperti komponen rectangular lain di workbook yang sama.
        $this->assertSamaHitungUlang(sqrt(6.0), self::nilai($komp['koefisien_muai']['pembagi']), 'pembagi muai master = sqrt(6) '.$komp['koefisien_muai']['pembagi']['sel']);
        $this->assertSamaHitungUlang(sqrt(3.0), self::nilai($komp['geometri']['pembagi']), 'pembagi geometri master = sqrt(3) '.$komp['geometri']['pembagi']['sel']);
        $this->assertSamaHitungUlang(2.0 * self::nilai($e['dasar']['alpha_per_c_R24']), self::nilai($e['dasar']['delta_alpha_per_c_S24']), 'delta alpha master = 2 x '.$e['dasar']['alpha_per_c_R24']['sel']);
        $this->assertNotEquals(self::nilai($termal['alpha_standar_per_c_P35']), self::nilai($e['dasar']['delta_alpha_per_c_S24']), 'dua nilai koefisien muai berbeda di workbook yang sama');

        // Sumbangan komponen muai pada jumlah (ui*ci)^2: kecil pada data ini (diukur, bukan diasumsikan).
        $rasio = self::nilai($komp['koefisien_muai']['uici_kuadrat']) / self::nilai($e['budget']['agregat']['jumlah_uici_kuadrat']);
        $this->assertLessThan(1.0e-3, $rasio, 'komponen muai < 0,1 % dari jumlah (ui*ci)^2 pada sesi ini');
    }
}
