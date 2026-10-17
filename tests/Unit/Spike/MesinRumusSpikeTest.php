<?php

namespace Tests\Unit\Spike;

use App\Services\Calibration\Profiles\FixedVolumetricGlasswareProfile;
use App\Services\Calibration\Profiles\LabuUkurProfile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\Profiles\PipetVolumeProfile;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use App\Support\Angka;
use Composer\InstalledVersions;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Functions;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * SPIKE S0a — BOLEH DIHAPUS. Bukan test produksi, bukan penjaga regresi.
 *
 * Pertanyaan yang dijawab (rencana `06-rencana-rescope.md` S0 butir a): bisakah
 * mesin Calculation PhpSpreadsheet menghitung budget ketidakpastian Volumetric
 * Glassware (Fixed) SAMA dengan `VolumetricGlasswareCalculator` + `GumCalculator`?
 *
 * Caranya: tiga sesi Fixed dihitung PHP lewat `hitungSesi()` (jalur produksi,
 * tidak diubah sama sekali), lalu "lembar" INPUT + PERHITUNGAN + TABEL dibangun
 * secara program — sel input diisi angka, sel rumus diisi rumus Excel yang
 * meniru urutan operasi PHP — dievaluasi PhpSpreadsheet, dan diadu sel per sel.
 *
 * Laporan tabel paritas ditulis ke berkas bila env `SPIKE_LAPOR` berisi path;
 * tanpa itu test ini diam.
 *
 * Batas spike: CMC diambil dari tabel JSON (`TabelStandarVolumetric::cmc()`),
 * bukan pita DB `calibration_capabilities` seperti `VolumetricGlasswareProfile`
 * — aturan pencariannya sama (nominal terdekat, seri → yang pertama). Profil
 * Volumetric sendiri memulangkan `keputusan = null`; keputusan di sini memakai
 * aturan ILAC-G8 `GumCalculator::keputusan()` dengan toleransi alat, hanya
 * untuk menguji IF/ABS di mesin.
 */
class MesinRumusSpikeTest extends TestCase
{
    /** Gerbang paritas rencana S4: relatif 1e-12 + angka cetak identik. */
    private const TOLERANSI_RELATIF = 1e-12;

    /** Kunci yang nilainya bergantung pada TINV mesin (dibahas terpisah). */
    private const KUNCI_TERGANTUNG_K = ['k', 'U', 'U95'];

    /**
     * Varian lembar: cara STDEV & k ditulis.
     *  - dua_lintasan : STDEV ditulis eksplisit dua lintasan, k = TINV bawaan
     *  - stdev_bawaan : STDEV & TINV bawaan PhpSpreadsheet
     *  - fungsi_sidik : SIDIK.STDEV & SIDIK.KFAKTOR (fungsi kustom → kode PHP yang sama)
     */
    private const VARIAN = ['dua_lintasan', 'stdev_bawaan', 'fungsi_sidik'];

    protected function setUp(): void
    {
        parent::setUp();

        // `addFunction()` TIDAK bisa menimpa fungsi bawaan (TINV/STDEV) — cuma
        // nama baru. Nama fungsi PhpSpreadsheet: huruf, angka, titik (tanpa `_`).
        Calculation::addFunction('SIDIK.STDEV', ['category' => 'Sidik',
            'functionCall' => [self::class, 'sidikStdev'], 'argumentCount' => '1+']);
        Calculation::addFunction('SIDIK.KFAKTOR', ['category' => 'Sidik',
            'functionCall' => [self::class, 'sidikKFaktor'], 'argumentCount' => '1']);
    }

    protected function tearDown(): void
    {
        Calculation::removeFunction('SIDIK.STDEV');
        Calculation::removeFunction('SIDIK.KFAKTOR');

        parent::tearDown();
    }

    /** STDEV sampel dua lintasan — ekspresi sama dengan `VolumetricGlasswareCalculator::stdev()`. */
    public static function sidikStdev(mixed ...$args): float|string
    {
        $x = array_values(array_map('floatval', array_filter(
            Functions::flattenArray($args),
            static fn (mixed $v): bool => is_int($v) || is_float($v),
        )));
        $n = count($x);
        if ($n < 2) {
            return '#DIV/0!';
        }
        $rata = array_sum($x) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $x)) / ($n - 1));
    }

    /** k 95% — ekspresi sama dengan `GumCalculator::agregasiBudget()`. */
    public static function sidikKFaktor(mixed $veff): float|string
    {
        // Rujukan sel datang sebagai ARRAY, bukan angka. Tanpa flatten,
        // `(float) [..]` = 1 → k df=1 = 12,706 — salah diam-diam, tanpa error.
        $veff = Functions::flattenSingleValue($veff);
        if (! is_int($veff) && ! is_float($veff)) {
            return '#VALUE!';
        }

        return (new StudentTDistribution)->quantile(0.975, max(1.0, floor((float) $veff)));
    }

    /**
     * Tiga sesi Fixed: contoh master/seeder (Pipet Volume 1 mL), Labu Ukur
     * 100 mL kelas A (massa besar → uji pembatalan float), dan Picnometer
     * 50 mL dengan sebaran besar (veff kecil → k jauh dari 2).
     *
     * @return array<string, array{0: class-string<FixedVolumetricGlasswareProfile>, 1: array<string, mixed>, 2: array<string, mixed>, 3: float}>
     */
    public static function sesiFixed(): array
    {
        return [
            'master_pipet_volume_1ml' => [
                PipetVolumeProfile::class,
                ['titik_ke' => 1, 'nominal' => 1.0,
                    'kosong' => [0.0, 0.0, 0.0], 'isi' => [0.9998, 0.9997, 0.9996], 'suhu' => [27.0, 27.0, 27.0]],
                ['kelas' => 'B', 'toleransi_ml' => 0.008, 'resolusi_ml' => null, 'neraca' => 'Analytical Balance',
                    'suhu_awal' => 21.0, 'suhu_akhir' => 20.5, 'kelembaban_awal' => 48.0, 'kelembaban_akhir' => 47.0,
                    'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1],
                1.0,
            ],
            'labu_ukur_100ml_kelas_a' => [
                LabuUkurProfile::class,
                ['titik_ke' => 1, 'nominal' => 100.0,
                    'kosong' => [65.4321, 65.4318, 65.4325], 'isi' => [165.1234, 165.1301, 165.1189],
                    'suhu' => [24.6, 25.1, 25.3]],
                ['kelas' => 'A', 'toleransi_ml' => 0.1, 'resolusi_ml' => null, 'neraca' => 'Electronic Balance Fujitsu',
                    'suhu_awal' => 22.3, 'suhu_akhir' => 22.9, 'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 57.0,
                    'tekanan_awal' => 1008.4, 'tekanan_akhir' => 1008.1],
                100.0,
            ],
            'picnometer_50ml_veff_kecil' => [
                PicnometerProfile::class,
                ['titik_ke' => 1, 'nominal' => 50.0,
                    'kosong' => [30.12, 30.11, 30.13], 'isi' => [79.83, 79.98, 79.75], 'suhu' => [26.8, 27.4, 26.1]],
                ['kelas' => 'B', 'toleransi_ml' => 0.05, 'resolusi_ml' => null, 'neraca' => 'Electronic Balance Excellent',
                    'suhu_awal' => 23.0, 'suhu_akhir' => 23.6, 'kelembaban_awal' => 60.0, 'kelembaban_akhir' => 58.0,
                    'tekanan_awal' => 1006.0, 'tekanan_akhir' => 1005.5],
                50.0,
            ],
        ];
    }

    /**
     * @param  class-string<FixedVolumetricGlasswareProfile>  $kelasProfil
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $blok
     */
    #[DataProvider('sesiFixed')]
    public function test_lembar_phpspreadsheet_setara_php_volumetric_fixed(
        string $kelasProfil,
        array $titik,
        array $blok,
        float $kapasitas,
    ): void {
        $profil = new $kelasProfil;
        $php = $this->nilaiPhp($profil, $titik, $blok, $kapasitas);

        $lapor = [];
        $selisihMaks = array_fill_keys(self::VARIAN, 0.0);
        $selisihMaksNonK = 0.0;
        $tidakIdentik = array_fill_keys(self::VARIAN, []);
        $cetakBeda = array_fill_keys(self::VARIAN, []);

        foreach (self::VARIAN as $varian) {
            [$ss, $peta] = $this->bangunLembar($titik, $blok, $kapasitas, $profil->namaAlatKemampuan(), $varian);
            $lembar = $this->evaluasi($ss, $peta);

            $baris = [];
            foreach ($php as $kunci => $nilaiPhp) {
                $nilaiLembar = $lembar[$kunci] ?? null;
                [$abs, $rel, $identik] = $this->selisih($nilaiPhp, $nilaiLembar);
                $baris[] = [$kunci, $peta[$kunci] ?? '-', $nilaiPhp, $nilaiLembar, $abs, $rel, $identik];

                if (! $identik) {
                    $tidakIdentik[$varian][] = $kunci;
                }
                $relF = is_float($rel) ? $rel : INF;
                $selisihMaks[$varian] = max($selisihMaks[$varian], $relF);
                if ($varian === 'dua_lintasan' && ! in_array($kunci, self::KUNCI_TERGANTUNG_K, true)) {
                    $selisihMaksNonK = max($selisihMaksNonK, $relF);
                }
            }

            $cetak = $this->bandingkanCetak($profil, $php, $lembar);
            $cetakBeda[$varian] = array_values(array_filter($cetak, static fn (array $c): bool => $c[1] !== $c[2]));

            $lapor[$varian] = ['baris' => $baris, 'cetak' => $cetak];
            $ss->disconnectWorksheets();
        }

        $ringkas = "\n### Ringkasan {$profil->namaAlatKemampuan()}\n\n| varian | selisih rel maks | sel tidak bit-identik | cetak beda |\n|---|---|---|---|\n";
        foreach (self::VARIAN as $varian) {
            $ringkas .= sprintf("| %s | %.3e | %s | %s |\n", $varian, $selisihMaks[$varian],
                $tidakIdentik[$varian] === [] ? '-' : implode(', ', $tidakIdentik[$varian]),
                $cetakBeda[$varian] === [] ? '-' : implode(', ', array_column($cetakBeda[$varian], 0)));
        }
        $this->tulisLaporan($ringkas.$this->formatParitas($profil->namaAlatKemampuan(), $lapor));

        // (1) Rumus yang meniru urutan PHP, STDEV ditulis dua lintasan: lolos
        //     gerbang S4 di semua sel yang tidak bergantung TINV.
        $this->assertLessThanOrEqual(self::TOLERANSI_RELATIF, $selisihMaksNonK,
            'Sel non-k melampaui toleransi relatif 1e-12 — lihat laporan SPIKE_LAPOR.');
        $this->assertSame([], $cetakBeda['dua_lintasan'], 'Angka cetak beda antara PHP dan lembar.');

        // (2) Dengan fungsi kustom yang mendelegasikan ke kode PHP yang sama:
        //     SEMUA sel bit-identik, termasuk k/U/U95.
        $this->assertSame([], $tidakIdentik['fungsi_sidik'], 'Varian fungsi_sidik tidak bit-identik.');
        $this->assertSame([], $cetakBeda['fungsi_sidik']);
    }

    /**
     * k: PHP `StudentTDistribution::quantile(0.975, max(1, floor(veff)))`
     * lawan `TINV(0.05, veff)` PhpSpreadsheet (memotong df sendiri).
     */
    public function test_k_tinv_lawan_quantile_php(): void
    {
        $t = new StudentTDistribution;
        // Rujukan bentuk tertutup: df=1 → tan(0,475π); df=2 → a·√(2/(1−a²)), a=0,95.
        $acuan = [
            1 => tan(0.475 * M_PI),
            2 => 0.95 * sqrt(2 / (1 - 0.95 ** 2)),
        ];

        $baris = [];
        $selisihMaks = 0.0;
        foreach ([1.0, 2.0, 2.9, 4.92, 9.7, 50.0, 53.11949372298425, 277.96, 1e4, 1e5, 1e6] as $veff) {
            $df = max(1.0, floor($veff));
            $mulai = hrtime(true);
            $kPhp = $t->quantile(0.975, $df);
            $msPhp = (hrtime(true) - $mulai) / 1e6;

            $mulai = hrtime(true);
            $kSs = $this->hitungSatu('=TINV(0.05,'.$this->literal($veff).')');
            $msSs = (hrtime(true) - $mulai) / 1e6;
            $kSs2t = $this->hitungSatu('=T.INV.2T(0.05,'.$this->literal($veff).')');

            [$abs, $rel] = $this->selisih($kPhp, $kSs);
            if (is_float($rel)) {
                $selisihMaks = max($selisihMaks, $rel);
            }
            $ref = $acuan[(int) $df] ?? null;

            $baris[] = sprintf(
                '| %s | %d | %.17g | %s | %s | %s | %s | %s | %.2f | %.2f |',
                $this->literal($veff), (int) $df, $kPhp, $this->angka($kSs), $this->angka($kSs2t),
                $this->angka($abs), $this->angka($rel),
                $ref === null ? '-' : sprintf('PHP %.2e / SS %s', abs($kPhp - $ref) / $ref,
                    is_float($kSs) ? sprintf('%.2e', abs($kSs - $ref) / $ref) : '-'),
                $msPhp, $msSs,
            );
        }

        $this->tulisLaporan("\n## k: quantile PHP vs TINV PhpSpreadsheet\n\n"
            ."| veff | df | k PHP | TINV | T.INV.2T | selisih abs | selisih rel | vs bentuk tertutup (rel) | ms PHP | ms SS |\n"
            ."|---|---|---|---|---|---|---|---|---|---|\n".implode("\n", $baris)."\n");

        $this->assertLessThan(1e-6, $selisihMaks, 'TINV menyimpang jauh dari quantile PHP.');
    }

    /**
     * Fungsi Excel yang dipakai master (riset D). "Didukung" = nilai sama
     * dengan harapan semantik Excel; harapan untuk kasus tepi ditulis dari
     * perilaku Excel yang terdokumentasi/diketahui, bukan dari PhpSpreadsheet.
     */
    public function test_dukungan_fungsi_master(): void
    {
        $kasus = [
            // [nama, rumus, harapan, catatan]
            ['IF', '=IF(DATA!A1>2,"besar","kecil")', 'besar', ''],
            ['ISERROR', '=ISERROR(1/0)', true, ''],
            ['ISERROR+VLOOKUP #N/A', '=ISERROR(VLOOKUP(99,Tabel_Kecil,2,0))', true, ''],
            ['IFNA', '=IFNA(VLOOKUP(99,Tabel_Kecil,2,0),"tidak ada")', 'tidak ada', ''],
            ['_xlfn.IFNA', '=_xlfn.IFNA(VLOOKUP(99,Tabel_Kecil,2,0),-1)', -1, 'awalan _xlfn. dari berkas xlsx'],
            ['VLOOKUP persis', '=VLOOKUP(0.008,Tabel_Kecil,2,0)', 4.7, ''],
            ['VLOOKUP hampiran', '=VLOOKUP(0.0085,Tabel_Kecil,2,TRUE)', 4.7, 'terbesar <= kunci'],
            ['ABS', '=ABS(-2.5)', 2.5, ''],
            ['AVERAGE', '=AVERAGE(DATA!A1:A3)', 3.0, '(3+2+4)/3'],
            ['MIN', '=MIN(DATA!A1:A3)', 2.0, ''],
            ['MAX', '=MAX(DATA!A1:A3)', 4.0, ''],
            ['SQRT', '=SQRT(3)', sqrt(3), ''],
            ['SUM', '=SUM(DATA!A1:A3)', 9.0, ''],
            ['STDEV kecil', '=STDEV(DATA!A1:A3)', 1.0, ''],
            ['STDEV terkondisi buruk', '=STDEV(DATA!C1:C3)', $this->stdevDuaLintasan([100.0001, 100.0002, 100.0003]),
                'harapan = dua lintasan (cara Excel & PHP)'],
            ['STDEV(IFERROR(rentang,""))', '=STDEV(IFERROR(DATA!E1:E4,""))', $this->stdevDuaLintasan([1.0, 3.0, 0.0, 0.0]),
                'Excel: sel kosong dibaca 0 (nol hantu H55 Graduated)'],
            ['MATCH persis', '=MATCH(25,Kal_Kecil,0)', 3, ''],
            ['MATCH hampiran', '=MATCH(30,Kal_Kecil,1)', 3, ''],
            ['INDEX', '=INDEX(Tabel_Kecil,2,2)', 4.7, 'baris 2 = 0,008 → 4,7'],
            ['AND', '=AND(1<2,3>2)', true, ''],
            ['IFERROR', '=IFERROR(1/0,"x")', 'x', ''],
            ['EXP', '=EXP(1)', M_E, ''],
            ['FORECAST', '=FORECAST(4,DATA!G1:G3,DATA!F1:F3)', 8.0, 'y=2x'],
            ['ROUND 2,345', '=ROUND(2.345,2)', 2.35, 'Excel 2,35'],
            ['ROUND 1,005', '=ROUND(1.005,2)', 1.01, 'Excel 1,01'],
            ['ROUND 0,285', '=ROUND(0.285,2)', 0.29, 'Excel 0,29'],
            ['ROUND -2,5', '=ROUND(-2.5,0)', -3.0, 'menjauhi nol'],
            ['TINV', '=TINV(0.05,9.7)', (new StudentTDistribution)->quantile(0.975, 9.0), 'df dipotong'],
            ['OFFSET', '=SUM(OFFSET(DATA!A1,0,0,3,1))', 9.0, 'volatil'],
            ['DATE/YEAR/MONTH/DAY', '=YEAR(DATE(2026,10,9))*10000+MONTH(DATE(2026,10,9))*100+DAY(DATE(2026,10,9))', 20261009, ''],
            ['NOW', '=NOW()>45000', true, 'volatil'],
            ['CONCATENATE', '=CONCATENATE("a","b")', 'ab', ''],
            ['INDEX/MATCH(MIN(ABS())) array', '=INDEX(Kal_Kecil,MATCH(MIN(ABS(Kal_Kecil-27)),ABS(Kal_Kecil-27),0))', 25.0, 'terdekat'],
            ['INDEX/MATCH(MIN(ABS())) seri', '=INDEX(Kal_Kecil,MATCH(MIN(ABS(Kal_Kecil-12.5)),ABS(Kal_Kecil-12.5),0))', 0.0, 'seri → pertama'],
            ['named range lintas sheet', '=SUM(Kal_Kecil)', 155.0, '-20+0+25+50+100'],
            ['SIDIK.STDEV (kustom)', '=SIDIK.STDEV(DATA!C1:C3)', $this->stdevDuaLintasan([100.0001, 100.0002, 100.0003]),
                'Calculation::addFunction'],
            ['SIDIK.KFAKTOR (kustom)', '=SIDIK.KFAKTOR(9.7)', (new StudentTDistribution)->quantile(0.975, 9.0),
                'Calculation::addFunction'],
            ['SIDIK.KFAKTOR (rujukan sel)', '=SIDIK.KFAKTOR(DATA!A3)', (new StudentTDistribution)->quantile(0.975, 4.0),
                'argumen sel datang sebagai array → wajib flattenSingleValue'],
        ];
        $timpaTinv = Calculation::addFunction('TINV', ['category' => 'Sidik',
            'functionCall' => [self::class, 'sidikKFaktor'], 'argumentCount' => '2']);

        $baris = [];
        $gagalWajib = [];
        foreach ([false, true] as $modeArray) {
            foreach ($kasus as [$nama, $rumus, $harapan, $catatan]) {
                $hasil = $this->hitungSatu($rumus, $modeArray);
                $cocok = $this->cocok($harapan, $hasil);
                $baris[] = sprintf('| %s | %s | `%s` | %s | %s | %s | %s |',
                    $modeArray ? 'CSE' : 'biasa', $nama, $rumus, $this->angka($harapan), $this->angka($hasil),
                    $cocok ? 'YA' : 'TIDAK', $catatan);
                if (! $cocok && ! $modeArray && ! in_array($nama, ['STDEV terkondisi buruk', 'STDEV(IFERROR(rentang,""))',
                    'ROUND 2,345', 'ROUND 1,005', 'ROUND 0,285', 'TINV'], true)) {
                    $gagalWajib[] = $nama;
                }
            }
        }

        $this->tulisLaporan("\n## Dukungan fungsi\n\n"
            .'- `Calculation::addFunction(\'TINV\', …)` (menimpa bawaan) memulangkan: '.var_export($timpaTinv, true)."\n\n"
            ."| mode | fungsi | rumus | harapan | hasil | cocok | catatan |\n"
            ."|---|---|---|---|---|---|---|\n".implode("\n", $baris)."\n");

        $this->assertFalse($timpaTinv, 'addFunction ternyata bisa menimpa TINV bawaan — catatan spike perlu diubah.');
        $this->assertSame([], $gagalWajib, 'Fungsi dasar master tidak didukung: '.implode(', ', $gagalWajib));
    }

    /** Waktu & memori satu lembar (bangun + evaluasi), median 20 kali. */
    public function test_waktu_dan_memori_satu_lembar(): void
    {
        [, $titik, $blok, $kapasitas] = self::sesiFixed()['master_pipet_volume_1ml'];

        $bangun = [];
        $evaluasi = [];
        $memori = [];
        for ($i = 0; $i < 21; $i++) {
            gc_collect_cycles();
            $awal = memory_get_usage();
            memory_reset_peak_usage();

            $t0 = hrtime(true);
            [$ss, $peta] = $this->bangunLembar($titik, $blok, $kapasitas, 'Pipet Volume', false);
            $t1 = hrtime(true);
            $this->evaluasi($ss, $peta);
            $t2 = hrtime(true);

            if ($i > 0) { // putaran pertama = pemanasan autoload/opcache
                $bangun[] = ($t1 - $t0) / 1e6;
                $evaluasi[] = ($t2 - $t1) / 1e6;
                $memori[] = [(memory_get_usage() - $awal) / 1024, (memory_get_peak_usage() - $awal) / 1024];
            }
            $ss->disconnectWorksheets();
            unset($ss);
        }

        sort($bangun);
        sort($evaluasi);
        $median = static fn (array $x): float => $x[intdiv(count($x), 2)];

        // Profil per sel (lembar segar, urutan peta ≈ urutan ketergantungan;
        // waktu sel termasuk preseden yang belum ter-cache).
        [$ss, $peta] = $this->bangunLembar($titik, $blok, $kapasitas, 'Pipet Volume', 'dua_lintasan');
        $perSel = [];
        foreach ($peta as $kunci => $alamat) {
            [$sheet, $sel] = explode('!', $alamat);
            $t0 = hrtime(true);
            $ss->getSheetByNameOrThrow($sheet)->getCell($sel)->getCalculatedValue();
            $perSel[$kunci] = (hrtime(true) - $t0) / 1e6;
        }
        $ss->disconnectWorksheets();
        arsort($perSel);
        $teratas = array_slice($perSel, 0, 8, true);
        $profil = implode(', ', array_map(static fn (string $k, float $ms): string => sprintf('%s %.1f ms', $k, $ms),
            array_keys($teratas), $teratas));

        // Pembanding: jalur PHP murni untuk sesi yang sama.
        $php = [];
        for ($i = 0; $i < 21; $i++) {
            $t0 = hrtime(true);
            (new V)->hitungSesi(V::KELUARGA_FIXED, [$titik], $blok);
            $php[] = (hrtime(true) - $t0) / 1e6;
        }
        array_shift($php);
        sort($php);

        $this->tulisLaporan(sprintf(
            "\n## Waktu & memori (Pipet Volume 1 mL, 20 putaran sesudah pemanasan)\n\n"
            ."- bangun lembar: median %.2f ms (min %.2f, maks %.2f)\n"
            ."- evaluasi semua sel keluaran: median %.2f ms (min %.2f, maks %.2f)\n"
            ."- memori tertahan selama lembar hidup: ~%.0f KiB; puncak di atas awal: ~%.0f KiB\n"
            ."- pembanding PHP hitungSesi(): median %.3f ms\n"
            ."- sel termahal (%d sel keluaran, total %.1f ms): %s\n"
            ."- memory_limit: %s; PHP %s; PhpSpreadsheet %s\n",
            $median($bangun), $bangun[0], end($bangun),
            $median($evaluasi), $evaluasi[0], end($evaluasi),
            max(array_column($memori, 0)), max(array_column($memori, 1)),
            $median($php), count($perSel), array_sum($perSel), $profil,
            ini_get('memory_limit'), PHP_VERSION,
            InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet'),
        ));

        $this->assertLessThan(1000.0, $median($evaluasi));
    }

    // ------------------------------------------------------------------ PHP

    /**
     * Semua nilai antara dari jalur PHP produksi. Yang tidak diekspos
     * `hitungSesi()` (uici, kuadrat, penyebut WS) dihitung ulang di sini dengan
     * ekspresi yang SAMA PERSIS dengan `GumCalculator::agregasiBudget()`.
     *
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $blok
     * @return array<string, float|string|null>
     */
    private function nilaiPhp(FixedVolumetricGlasswareProfile $profil, array $titik, array $blok, float $kapasitas): array
    {
        $hasil = (new V)->hitungSesi(V::KELUARGA_FIXED, [$titik], $blok);
        $this->assertTrue($hasil['boleh_terbit'], json_encode($hasil['ditolak']));

        $p = $hasil['praolah'];
        $t = $hasil['titik'][0];
        $m = $t['masukan_budget'];
        $a = $t['agregat'];

        $n = [
            'suhu_ruang' => $p['suhu_ruang'], 'kelembaban' => $p['kelembaban'], 'tekanan' => $p['tekanan'],
            'rho_udara' => $p['rho_udara'], 'gamma' => $p['gamma'], 'diameter_mm' => $p['diameter_mm'],
            'u_meniskus' => $p['u_meniskus'], 'u_timbang' => $p['u_timbang'], 'u_massa' => $p['u_massa'],
        ];
        foreach ([0, 1, 2] as $i) {
            $n["massa_$i"] = $t['massa_per_ulangan'][$i];
            $n["titik_kal_$i"] = $t['koreksi_suhu'][$i]['titik_c'];
            $n["koreksi_kal_$i"] = $t['koreksi_suhu'][$i]['koreksi_kalibrator_c'];
            $n["koreksi_sensor_$i"] = $t['koreksi_suhu'][$i]['koreksi_sensor_c'];
            $n["suhu_$i"] = $t['suhu_terkoreksi_per_ulangan'][$i];
            $n["rho_air_$i"] = $t['rho_air_per_ulangan'][$i];
            $n["v20_$i"] = $t['v20_per_ulangan'][$i];
        }
        $n += [
            'massa_rata' => $t['massa_rata_rata'], 'suhu_rata' => $t['suhu_rata_rata'],
            'rho_air_rata' => $t['rho_air_rata_rata'], 'v20' => $t['v20'], 'deviasi' => $t['deviasi'],
            'stdev_v20' => $t['stdev_v20'], 'rentang' => $t['rentang_suhu'], 'u_suhu' => $m['u_suhu'],
            'u_keterulangan' => $m['u_keterulangan'],
        ];

        $penyebut = 0.0;
        foreach ($t['komponen_budget'] as $j => $k) {
            $uici = $k['u'] * $k['ci'];
            $n["b{$j}_U"] = $k['u_diperluas'];
            $n["b{$j}_pembagi"] = $k['pembagi'];
            $n["b{$j}_u"] = $k['u'];
            $n["b{$j}_ci"] = $k['ci'];
            $n["b{$j}_vi"] = $k['vi'];
            $n["b{$j}_uici"] = $uici;
            $n["b{$j}_uici2"] = $uici ** 2;
            $n["b{$j}_uici4vi"] = ($uici ** 4) / $k['vi'];
        }
        $jumlahKuadrat = array_sum(array_map(static fn (array $k): float => ($k['u'] * $k['ci']) ** 2, $t['komponen_budget']));
        foreach ($t['komponen_budget'] as $k) {
            $penyebut += (($k['u'] * $k['ci']) ** 4) / $k['vi'];
        }

        $tabel = new TabelStandarVolumetric;
        $cmc = $tabel->cmc($profil->namaAlatKemampuan(), $kapasitas);
        $u95 = max($a['ketidakpastian_diperluas'], $cmc ?? 0.0);
        $keputusan = (new ReflectionMethod(GumCalculator::class, 'keputusan'))
            ->invoke(app(GumCalculator::class), $t['deviasi'], $u95, (float) $blok['toleransi_ml']);

        return $n + [
            'jumlah_kuadrat' => $jumlahKuadrat,
            'uc' => $a['ketidakpastian_gabungan'],
            'penyebut_ws' => $penyebut,
            'veff' => $a['derajat_kebebasan_efektif'],
            'k' => $a['faktor_cakupan_k'],
            'U' => $a['ketidakpastian_diperluas'],
            'cmc_nominal' => $tabel->nominalTerindeks($profil->namaAlatKemampuan(), $kapasitas),
            'cmc' => $cmc,
            'U95' => $u95,
            'keputusan' => $keputusan,
            'veff_master_k43' => $t['pembanding_master']['veff_dibagi_baris_akhir'],
        ];
    }

    // --------------------------------------------------------------- lembar

    /**
     * Bangun lembar INPUT + PERHITUNGAN + TABEL. Tiap rumus menyalin URUTAN
     * operasi PHP (kurung, kiri-ke-kanan) — beda urutan = beda bit terakhir.
     *
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $blok
     * @return array{0: Spreadsheet, 1: array<string, string>}
     */
    private function bangunLembar(array $titik, array $blok, float $kapasitas, string $jenisAlat, string $varian): array
    {
        $data = json_decode((string) file_get_contents(database_path('data/tabel-standar-volumetric.json')), true);
        $neraca = (new TabelStandarVolumetric)->neraca(V::KELUARGA_FIXED, $blok['neraca']);
        $u95Suhu = (new TabelStandarVolumetric)->u95Suhu();

        $ss = new Spreadsheet;
        $in = $ss->getActiveSheet()->setTitle('INPUT');
        $hit = $ss->createSheet()->setTitle('PERHITUNGAN');
        $tab = $ss->createSheet()->setTitle('TABEL');

        // TABEL — lapis 1 (data acuan), dipanggil lewat named range.
        $isiTabel = static function (string $kolA, string $kolB, array $baris, string $kunciA, string $kunciB) use ($tab): int {
            $r = 2;
            foreach ($baris as $b) {
                $tab->setCellValue($kolA.$r, (float) $b[$kunciA]);
                $tab->setCellValue($kolB.$r, (float) $b[$kunciB]);
                $r++;
            }

            return $r - 1;
        };
        $akhirDiameter = $isiTabel('A', 'B', $data['diameter_iso4787'], 'batas_galat_ml', 'diameter_maks_mm');
        $akhirKal = $isiTabel('D', 'E', $data['koreksi_suhu']['kalibrator'], 'titik_c', 'koreksi_c');
        $akhirPrt = $isiTabel('G', 'H', $data['koreksi_suhu']['sensor_prt'], 'titik_c', 'koreksi_c');
        $akhirCmc = $isiTabel('J', 'K', $data['cmc'][$jenisAlat], 'nominal_ml', 'cmc_ml');
        foreach ([
            'Tabel_Diameter' => "\$A\$2:\$B\${$akhirDiameter}",
            'Kal_Titik' => "\$D\$2:\$D\${$akhirKal}",
            'Kal_Koreksi' => "\$E\$2:\$E\${$akhirKal}",
            'Tabel_PRT' => "\$G\$2:\$H\${$akhirPrt}",
            'CMC_Nominal' => "\$J\$2:\$J\${$akhirCmc}",
            'CMC_Tabel' => "\$J\$2:\$K\${$akhirCmc}",
        ] as $nama => $rentang) {
            $ss->addNamedRange(new NamedRange($nama, $tab, $rentang));
        }

        // INPUT — sel masukan sesi + konstanta metode.
        foreach ([
            'B2' => $blok['kelas'], 'B3' => (float) $blok['toleransi_ml'], 'B4' => $kapasitas,
            'B5' => (float) $titik['nominal'], 'B6' => (float) $neraca['resolusi_g'], 'B7' => (float) $neraca['stdev_g'],
            'B8' => $u95Suhu['termometer_c'], 'B9' => $u95Suhu['sensor_c'],
            'B10' => $blok['suhu_awal'], 'B11' => $blok['suhu_akhir'],
            'B12' => $blok['kelembaban_awal'], 'B13' => $blok['kelembaban_akhir'],
            'B14' => $blok['tekanan_awal'], 'B15' => $blok['tekanan_akhir'],
            'B16' => V::DENSITAS_ANAK_TIMBANGAN, 'B17' => V::U_DENSITAS_AIR[V::KELUARGA_FIXED],
            'B18' => V::TANDA_CI_MUAI[V::KELUARGA_FIXED], 'B19' => V::N_STDEV_NERACA, 'B20' => V::SUHU_ACUAN,
            'B21' => V::GAMMA_KELAS_A, 'B22' => V::GAMMA_KELAS_B, 'B23' => 999_999_999_999.0,
        ] as $sel => $nilai) {
            $in->setCellValue($sel, $nilai);
        }
        foreach ([0, 1, 2] as $i) {
            $r = 26 + $i;
            $in->setCellValue("C$r", (float) $titik['kosong'][$i]);
            $in->setCellValue("D$r", (float) $titik['isi'][$i]);
            $in->setCellValue("E$r", (float) $titik['suhu'][$i]);
        }

        $peta = [];
        $rumus = static function (string $kunci, string $sel, string $f, bool $array = false) use ($hit, &$peta): void {
            $cell = $hit->getCell($sel);
            $cell->setValueExplicit($f, DataType::TYPE_FORMULA);
            if ($array) {
                $cell->setFormulaAttributes(['t' => 'array', 'ref' => $sel]);
            }
            $peta[$kunci] = "PERHITUNGAN!$sel";
        };

        // Lingkungan & densitas udara (PERHITUNGAN!H57 master).
        $rumus('suhu_ruang', 'B2', '=AVERAGE(INPUT!B10,INPUT!B11)');
        $rumus('kelembaban', 'B3', '=AVERAGE(INPUT!B12,INPUT!B13)');
        $rumus('tekanan', 'B4', '=AVERAGE(INPUT!B14,INPUT!B15)');
        $rumus('rho_udara', 'B5', '=((0.34848*B4)-(0.009*B3)*EXP(0.061*B2))/(B2+273.15)/1000');
        $rumus('gamma', 'B6', '=IF(INPUT!B2="A",INPUT!B21,IF(INPUT!B2="B",INPUT!B22,"cek kelas"))');
        $rumus('diameter_mm', 'B7', '=VLOOKUP(INPUT!B3,Tabel_Diameter,2,0)');
        $rumus('u_meniskus', 'B8', '=((0.1/2)*((3.14*(B7^2))/4))/1000');
        $rumus('u_timbang', 'B9', '=INPUT!B6/SQRT(3)');
        $rumus('u_massa', 'B10', '=SQRT(B9^2+(INPUT!B7/SQRT(INPUT!B19))^2)');

        // Per ulangan, baris 14..16.
        foreach ([0, 1, 2] as $i) {
            $r = 14 + $i;
            $ri = 26 + $i;
            $rumus("massa_$i", "B$r", "=INPUT!D$ri-INPUT!C$ri");
            $rumus("titik_kal_$i", "C$r",
                "=INDEX(Kal_Titik,MATCH(MIN(ABS(Kal_Titik-INPUT!E$ri)),ABS(Kal_Titik-INPUT!E$ri),0))", true);
            $rumus("koreksi_kal_$i", "D$r", "=INDEX(Kal_Koreksi,MATCH(C$r,Kal_Titik,0))");
            $rumus("koreksi_sensor_$i", "E$r", "=VLOOKUP(C$r,Tabel_PRT,2,0)");
            $rumus("suhu_$i", "F$r", "=INPUT!E$ri+D$r+E$r");
            $rumus("rho_air_$i", "G$r", "=0.99997495*(1-(((F$r+(-3.983035))^2)*(F$r+301.797))/(522528.9*(F$r+69.34881)))");
            $rumus("v20_$i", "H$r", "=B$r*(1/(G$r-\$B\$5)*(1-(\$B\$5/INPUT!\$B\$16)*(1-(\$B\$6*(F$r-INPUT!\$B\$20)))))");
        }

        $rumus('massa_rata', 'B18', '=AVERAGE(B14:B16)');
        $rumus('suhu_rata', 'B19', '=AVERAGE(F14:F16)');
        $rumus('rho_air_rata', 'B20', '=AVERAGE(G14:G16)');
        $rumus('v20', 'B21', '=B18*(1/(B20-B5)*(1-(B5/INPUT!B16)*(1-(B6*(B19-INPUT!B20)))))');
        $rumus('deviasi', 'B22', '=B21-INPUT!B5');
        $rumus('stdev_v20', 'B23', match ($varian) {
            'stdev_bawaan' => '=STDEV(H14:H16)',
            'fungsi_sidik' => '=SIDIK.STDEV(H14:H16)',
            default => '=SQRT(((H14-AVERAGE(H14:H16))^2+(H15-AVERAGE(H14:H16))^2+(H16-AVERAGE(H14:H16))^2)/(COUNT(H14:H16)-1))',
        });
        $rumus('rentang', 'B25', '=MAX(F14:F16)-MIN(F14:F16)');
        $rumus('u_suhu', 'B26', '=SQRT((INPUT!B8/2)^2+(INPUT!B9/2)^2+(B25/(2*SQRT(3)))^2)');
        $rumus('u_keterulangan', 'B27', '=B23/SQRT(3)');
        $hit->setCellValue('B28', '=1-(B6*(B19-INPUT!B20))');                                   // faktor muai
        $hit->setCellValue('B29', '=B18*((INPUT!B16-B20)/(INPUT!B16*((B20-B5)^2)))*B28');        // ci ρ

        // Budget, baris 33..40: B U | C pembagi | D u | E ci | F vi | G uici | H uici² | I uici⁴/vi.
        $budget = [
            ['=B10', '=2', '=((INPUT!B16-B5)/(INPUT!B16*(B20-B5)))*B28', 18],
            ['=0.1*B5', '=SQRT(3)', '=B29', 50],
            ['=INPUT!B17', '=2', '=-B29', 60],
            ['=0.1*INPUT!B16', '=SQRT(3)', '=B18*B5/(INPUT!B16^2*(B20-B5))*B28', 50],
            ['=B26', '=2', '=(-B18*B6*(INPUT!B16-B5)*(B19-INPUT!B20)/(INPUT!B16*(B20-B5)))', 60],
            ['=0.1*B6', '=SQRT(3)', '=INPUT!B18*(B18*(INPUT!B16-B5))/(INPUT!B16*(B20-B5))', 40],
            ['=B8', '=SQRT(3)', '=1', 50],
            ['=B27', '=1', '=1', 2],
        ];
        foreach ($budget as $j => [$u, $pembagi, $ci, $vi]) {
            $r = 33 + $j;
            $rumus("b{$j}_U", "B$r", $u);
            $rumus("b{$j}_pembagi", "C$r", $pembagi);
            $rumus("b{$j}_u", "D$r", "=B$r/C$r");
            $rumus("b{$j}_ci", "E$r", $ci);
            $hit->setCellValue("F$r", (float) $vi);
            $peta["b{$j}_vi"] = "PERHITUNGAN!F$r";
            $rumus("b{$j}_uici", "G$r", "=D$r*E$r");
            $rumus("b{$j}_uici2", "H$r", "=G$r^2");
            $rumus("b{$j}_uici4vi", "I$r", "=G$r^4/F$r");
        }

        $rumus('jumlah_kuadrat', 'B43', '=SUM(H33:H40)');
        $rumus('uc', 'B44', '=SQRT(B43)');
        $rumus('penyebut_ws', 'B45', '=SUM(I33:I40)');
        $rumus('veff', 'B46', '=MIN(B43^2/B45,INPUT!B23)');
        $rumus('k', 'B47', $varian === 'fungsi_sidik' ? '=SIDIK.KFAKTOR(B46)' : '=TINV(0.05,MAX(1,B46))');
        $rumus('U', 'B48', '=B47*B44');
        $rumus('cmc_nominal', 'B49',
            '=INDEX(CMC_Nominal,MATCH(MIN(ABS(CMC_Nominal-INPUT!B4)),ABS(CMC_Nominal-INPUT!B4),0))', true);
        $rumus('cmc', 'B50', '=VLOOKUP(B49,CMC_Tabel,2,0)');
        $rumus('U95', 'B51', '=MAX(B48,B50)');
        $rumus('keputusan', 'B52', '=IF(ABS(B22)+B51<=INPUT!B3,"PASS","FAIL")');
        $rumus('veff_master_k43', 'B53', '=B44^4/I40');
        $rumus('round_v20_4', 'B55', '=ROUND(B21,4)');
        $rumus('round_U95_4', 'B56', '=ROUND(B51,4)');

        return [$ss, $peta];
    }

    /**
     * @param  array<string, string>  $peta
     * @return array<string, mixed>
     */
    private function evaluasi(Spreadsheet $ss, array $peta): array
    {
        $hasil = [];
        foreach ($peta as $kunci => $alamat) {
            [$sheet, $sel] = explode('!', $alamat);
            try {
                $hasil[$kunci] = $ss->getSheetByNameOrThrow($sheet)->getCell($sel)->getCalculatedValue();
            } catch (Throwable $e) {
                $hasil[$kunci] = 'EXC: '.$e->getMessage();
            }
        }

        return $hasil;
    }

    // --------------------------------------------------------------- bantu

    /**
     * @param  array<string, mixed>  $php
     * @param  array<string, mixed>  $lembar
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function bandingkanCetak(FixedVolumetricGlasswareProfile $profil, array $php, array $lembar): array
    {
        $d = $profil->desimalSertifikat();
        $du = $profil->desimalU95();
        $dk = $profil->desimalFaktorCakupan();
        $cetak = static fn (string $jenis, mixed $v): string => ! is_float($v) && ! is_int($v)
            ? 'BUKAN ANGKA: '.var_export($v, true)
            : match ($jenis) {
                'hasil' => Angka::hasil((float) $v, $d),
                'u95' => Angka::ketidakpastian((float) $v, $du),
                'k' => Angka::hasil((float) $v, $dk),
            };

        return [
            ['Actual Volume (V20)', $cetak('hasil', $php['v20']), $cetak('hasil', $lembar['v20'] ?? null)],
            ['Correction (V20 - Nominal)', $cetak('hasil', $php['deviasi']), $cetak('hasil', $lembar['deviasi'] ?? null)],
            ['U95', $cetak('u95', $php['U95']), $cetak('u95', $lembar['U95'] ?? null)],
            ['k', $cetak('k', $php['k']), $cetak('k', $lembar['k'] ?? null)],
            ['keputusan', (string) $php['keputusan'], (string) ($lembar['keputusan'] ?? '')],
            ['ROUND(V20,4) vs round()', var_export(round($php['v20'], 4), true), var_export($lembar['round_v20_4'] ?? null, true)],
            ['ROUND(U95,4) vs round()', var_export(round($php['U95'], 4), true), var_export($lembar['round_U95_4'] ?? null, true)],
        ];
    }

    /** @return array{0: float|string, 1: float|string, 2: bool} */
    private function selisih(mixed $php, mixed $lembar): array
    {
        if (! is_numeric($php) || ! is_numeric($lembar) || is_string($lembar)) {
            $sama = $php === $lembar || (is_bool($lembar) && $php === $lembar);

            return [$sama ? 0.0 : 'n/a', $sama ? 0.0 : 'n/a', $sama];
        }

        $abs = abs((float) $php - (float) $lembar);
        $skala = max(abs((float) $php), PHP_FLOAT_MIN);

        return [$abs, $abs / $skala, (float) $php === (float) $lembar];
    }

    private function hitungSatu(string $rumus, bool $modeArray = false): mixed
    {
        $ss = new Spreadsheet;
        $data = $ss->getActiveSheet()->setTitle('DATA');
        foreach (['A1' => 3, 'A2' => 2, 'A3' => 4, 'C1' => 100.0001, 'C2' => 100.0002, 'C3' => 100.0003,
            'E1' => 1, 'E2' => 3, 'F1' => 1, 'F2' => 2, 'F3' => 3, 'G1' => 2, 'G2' => 4, 'G3' => 6] as $sel => $nilai) {
            $data->setCellValue($sel, $nilai);
        }
        $tab = $ss->createSheet()->setTitle('TABEL');
        foreach ([[0.005, 3.8], [0.008, 4.7], [0.01, 5.3]] as $i => [$a, $b]) {
            $tab->setCellValue('A'.($i + 1), $a);
            $tab->setCellValue('B'.($i + 1), $b);
        }
        foreach ([-20.0, 0.0, 25.0, 50.0, 100.0] as $i => $v) {
            $tab->setCellValue('D'.($i + 1), $v);
        }
        $ss->addNamedRange(new NamedRange('Tabel_Kecil', $tab, '$A$1:$B$3'));
        $ss->addNamedRange(new NamedRange('Kal_Kecil', $tab, '$D$1:$D$5'));

        $hitung = $ss->createSheet()->setTitle('HITUNG');
        $cell = $hitung->getCell('A1');
        $cell->setValueExplicit($rumus, DataType::TYPE_FORMULA);
        if ($modeArray) {
            $cell->setFormulaAttributes(['t' => 'array', 'ref' => 'A1']);
            Calculation::getInstance($ss)->setInstanceArrayReturnType(Calculation::RETURN_ARRAY_AS_ARRAY);
        }

        try {
            $hasil = $cell->getCalculatedValue();
            while (is_array($hasil)) {
                $hasil = reset($hasil);
            }
        } catch (Throwable $e) {
            $hasil = 'EXC: '.$e->getMessage();
        }
        $ss->disconnectWorksheets();

        return $hasil;
    }

    private function cocok(mixed $harapan, mixed $hasil): bool
    {
        if (is_bool($harapan) || is_string($harapan)) {
            return $harapan === $hasil;
        }
        if (! is_int($hasil) && ! is_float($hasil)) {
            return false;
        }

        return abs((float) $harapan - (float) $hasil) <= 1e-12 * max(1.0, abs((float) $harapan));
    }

    /** @param  list<float>  $x */
    private function stdevDuaLintasan(array $x): float
    {
        $rata = array_sum($x) / count($x);

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $x)) / (count($x) - 1));
    }

    private function literal(float $x): string
    {
        return rtrim(rtrim(sprintf('%.17F', $x), '0'), '.');
    }

    private function angka(mixed $x): string
    {
        return match (true) {
            is_float($x) => sprintf('%.17g', $x),
            is_bool($x) => $x ? 'TRUE' : 'FALSE',
            default => (string) $x,
        };
    }

    /** @param  array<string, array{baris: list<array<int, mixed>>, cetak: list<array{0: string, 1: string, 2: string}>}>  $lapor */
    private function formatParitas(string $alat, array $lapor): string
    {
        $teks = "\n## Paritas sel: {$alat}\n";
        foreach ($lapor as $varian => $isi) {
            $teks .= "\n### Varian STDEV: {$varian}\n\n| kunci | sel | PHP | PhpSpreadsheet | selisih abs | selisih rel | bit identik |\n|---|---|---|---|---|---|---|\n";
            foreach ($isi['baris'] as [$kunci, $sel, $php, $lembar, $abs, $rel, $identik]) {
                $teks .= sprintf("| %s | %s | %s | %s | %s | %s | %s |\n", $kunci, $sel, $this->angka($php),
                    $this->angka($lembar), is_float($abs) ? sprintf('%.3e', $abs) : $abs,
                    is_float($rel) ? sprintf('%.3e', $rel) : $rel, $identik ? 'ya' : 'tidak');
            }
            $teks .= "\n| cetak | PHP | PhpSpreadsheet | sama |\n|---|---|---|---|\n";
            foreach ($isi['cetak'] as [$nama, $a, $b]) {
                $teks .= "| {$nama} | {$a} | {$b} | ".($a === $b ? 'ya' : 'TIDAK')." |\n";
            }
        }

        return $teks;
    }

    private function tulisLaporan(string $teks): void
    {
        $berkas = getenv('SPIKE_LAPOR');
        if (is_string($berkas) && $berkas !== '') {
            file_put_contents($berkas, $teks, FILE_APPEND);
        }
    }
}
