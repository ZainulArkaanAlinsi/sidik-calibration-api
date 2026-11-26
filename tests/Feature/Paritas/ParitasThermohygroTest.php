<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Services\Calibration\Profiles\ThermohygroProfile;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use App\Support\TataLetakSertifikat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Thermohygrometer: aplikasi lawan `Master Olah Data Suhu & Kelembapan.xlsm` (satu sesi: 5 titik suhu,
 * 3 titik %RH chamber Biobase, 2 titik %RH chamber GEA), sel demi sel. Fixture `tests/Fixtures/paritas/thermohygro.json`,
 * dibangun `docs/skrip/gen-fixture-paritas-batch4.py` dari nilai-cache workbook.
 *
 * Titik panggil terkecil tanpa HTTP dan tanpa database: `ThermohygroCalculator::hitungSesi()` (yang dipanggil
 * `ThermohygroProfile::hitungPerGrup`), diberi pembacaan mentah dari sel `INPUT DATA` fixture.
 *
 * Selisih yang dikunci di sini (nomor dan uraian di `docs/paritas-batch4.md`):
 *  A-1 (16 Sep 2026, disetujui pemilik, paraf MT menyusul): delapan baris budget memakai `U = N/SQRT(Q)` padahal `Q` sudah
 *      berisi pembaginya. Aplikasi memakai `N/Q`. Selisih U95 hitung: suhu +0,2 %, %RH Biobase -8,6 %, %RH GEA -2,9 %.
 *      U95 yang TERCETAK tidak bergeser (suhu "2.0"; %RH tertahan lantai CMC "4.8");
 *  B4-4: `k` aplikasi memakai `v_eff` pecahan (`FLOOR_V_EFF = false`), Excel `TINV` memotong derajat bebas. Selisih ~1e-6..1e-5,
 *      jauh di bawah presisi cetak (`k` dicetak `0`);
 *  B4-5: urutan tabel Humidity (GEA dulu di master, Biobase dulu di kalkulator);
 *  M63: `SERTIFIKAT!M63 = #REF!` di master, aplikasi mencetak U95 %RH yang benar (deviasi terdokumentasi).
 *
 * Yang TIDAK dibuktikan sampel: Excel memilih koreksi standar dari indeks yang DIKETIK teknisi, aplikasi dari titik tabel
 * terdekat. Di sepuluh titik sampel hasilnya sama; sesi dengan indeks yang sengaja berbeda tidak punya acuan Excel.
 */
#[Group('paritas')]
class ParitasThermohygroTest extends TestCase
{
    use MenegakkanParitas;

    /** Selisih relatif terkecil yang masih berarti "beda formula", bukan galat urutan operasi (TOL_REL 1e-11) atau `k` pecahan (<= 1e-5). */
    private const BATAS_SELISIH_A1 = 5e-4;

    // ------------------------------------------------------------------ bantu

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('thermohygro')['workbook'];
    }

    /** @return array<string, mixed> */
    private static function grupExcel(string $kunci): array
    {
        foreach (self::w()['grup'] as $g) {
            if ($g['kunci'] === $kunci) {
                return $g;
            }
        }

        throw new \LogicException("Grup {$kunci} tidak ada di fixture.");
    }

    /**
     * Masukan `hitungSesi` persis dari sel `INPUT DATA` fixture, urut `titik_ke`.
     *
     * @return list<array<string, mixed>>
     */
    private static function titikMasukan(): array
    {
        $titik = [];

        foreach (self::w()['grup'] as $g) {
            foreach ($g['titik'] as $t) {
                $titik[] = [
                    'parameter' => $t['parameter'],
                    'titik_ke' => $t['titik_ke'],
                    'titik_ukur' => self::nilai($t['masukan']['titik_ukur']),
                    'standar' => $t['masukan']['standar']['nilai'],
                    'uut' => $t['masukan']['uut']['nilai'],
                ];
            }
        }

        usort($titik, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return $titik;
    }

    /** Resolusi dari `INPUT DATA!E16/E19` dan CMC dari `DATABASE!S5/S6`, bukan diketik di tes. */
    private static function spek(): array
    {
        $m = self::w()['masukan'];
        $k = self::w()['konstanta'];

        return [
            'resolusi_suhu' => self::nilai($m['resolusi_suhu']),
            'resolusi_kelembaban' => self::nilai($m['resolusi_kelembaban']),
            'cmc_suhu' => self::nilai($k['cmc_suhu']),
            'cmc_kelembaban' => self::nilai($k['cmc_kelembaban']),
        ];
    }

    /** @return array{grup: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>} */
    private static function hasil(): array
    {
        static $hasil = null;

        return $hasil ??= (new ThermohygroCalculator)->hitungSesi(self::titikMasukan(), self::spek());
    }

    /** @return array<string, mixed> */
    private static function grupApp(string $kunci): array
    {
        $x = self::grupExcel($kunci);

        foreach (self::hasil()['grup'] as $g) {
            if ($g['parameter'] === $x['parameter'] && $g['chamber'] === $x['chamber']) {
                return $g;
            }
        }

        throw new \LogicException("Grup {$kunci} tidak dihasilkan aplikasi.");
    }

    /** @return array<string, mixed> */
    private static function budgetApp(array $grup, string $sumber): array
    {
        foreach ($grup['budget'] as $b) {
            if ($b['sumber'] === $sumber) {
                return $b;
            }
        }

        throw new \LogicException("Komponen {$sumber} tidak ada di budget aplikasi.");
    }

    /** @return array<string, mixed> */
    private static function titikApp(array $grup, int $titikKe): array
    {
        foreach ($grup['titik'] as $t) {
            if ($t['titik_ke'] === $titikKe) {
                return $t;
            }
        }

        throw new \LogicException("Titik {$titikKe} tidak ada di grup aplikasi.");
    }

    /**
     * Pembagi yang benar menurut GUM 4.3.7. Pengulangan memakai `SQRT(n)` (n = 5 disimpan di kolom pembagi), dan itu
     * BENAR; baris lain sudah menyimpan pembaginya sendiri di `Q`.
     */
    private static function pembagiBenar(string $komponen, float $q): float
    {
        return $komponen === 'pengulangan_uut' ? sqrt($q) : $q;
    }

    /** Baris master yang mengakarkan `Q` dua kali: rumus `ui = N/SQRT(Q)` pada komponen selain keterulangan. */
    private static function akarGanda(string $komponen, array $rumus): bool
    {
        return $komponen !== 'pengulangan_uut'
            && preg_match('/^=N\d+\/SQRT\(Q\d+\)$/', (string) $rumus['ui']['rumus']) === 1;
    }

    /** @return array<string, array{0: string}> */
    public static function kunciGrup(): array
    {
        return [
            'suhu' => ['suhu_biobase'],
            'kelembaban chamber Biobase' => ['kelembaban_biobase'],
            'kelembaban chamber GEA' => ['kelembaban_gea'],
        ];
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function grupDenganBarisAkarGanda(): array
    {
        return [
            'suhu (U23, U24, U25)' => ['suhu_biobase', ['drift_standar', 'stabilitas_chamber', 'homogenitas_chamber']],
            'kelembaban Biobase (U44, U45, U46)' => ['kelembaban_biobase', ['drift_standar', 'stabilitas_chamber', 'homogenitas_chamber']],
            'kelembaban GEA (U64, U65; drift U61 benar)' => ['kelembaban_gea', ['stabilitas_chamber', 'homogenitas_chamber']],
        ];
    }

    // ------------------------------------------------------------------ fixture & susunan grup

    /**
     * Kepercayaan pada acuan: rantai komponen -> uc -> v_eff -> k -> U dihitung ulang dari sel dasar saat fixture
     * dibangun dan cocok dengan nilai-cache Excel di 1,1e-13 relatif. Tanpa ini, tes lain membandingkan ke angka yang
     * belum terbukti konsisten.
     */
    public function test_fixture_konsisten_dan_memuat_sepuluh_titik_tiga_grup(): void
    {
        $w = self::w();

        $this->assertLessThanOrEqual(self::TOL_REL, $w['rekonstruksi']['selisih_relatif_maks_terhadap_cache'], 'rekonstruksi fixture lawan cache');
        $this->assertSame(['suhu_biobase', 'kelembaban_biobase', 'kelembaban_gea'], array_column($w['grup'], 'kunci'));
        $this->assertSame([5, 3, 2], array_map(static fn (array $g): int => count($g['titik']), $w['grup']));
        $this->assertSame('#REF!', $w['u95_rh_bawah_tabel_galat']['nilai_teks']);
    }

    /**
     * Semua sepuluh titik terhitung (tidak ada `belum_dihitung`) dan jatuh ke grup yang sama dengan blok workbook:
     * suhu satu budget, %RH dua budget menurut chamber.
     */
    public function test_semua_titik_terhitung_dan_masuk_grup_yang_sama_dengan_blok_workbook(): void
    {
        $hasil = self::hasil();

        $this->assertSame([], $hasil['belum_dihitung']);
        $this->assertCount(3, $hasil['grup']);

        foreach (self::w()['grup'] as $x) {
            $g = self::grupApp($x['kunci']);

            $this->assertSame(array_column($x['titik'], 'titik_ke'), array_column($g['titik'], 'titik_ke'), "titik di grup {$x['kunci']}");
            $this->assertSame($x['parameter'] === 'suhu' ? '°C' : '%RH', $g['satuan'], "satuan grup {$x['kunci']}");
        }
    }

    /**
     * Chamber diturunkan aplikasi dari set point (>= 50 %RH Biobase, di bawahnya GEA); di workbook teknisi memilih
     * blok isian. Sampel memuat satu titik di tiap sisi ambang (49 dan 50) dan keduanya sepihak dengan blok master.
     * Perilaku persis di ambang tidak punya acuan Excel (lihat butir MENUNGGU di dokumen).
     */
    public function test_chamber_dari_set_point_sama_dengan_blok_isian_workbook(): void
    {
        foreach (self::w()['grup'] as $x) {
            foreach ($x['titik'] as $t) {
                $setPoint = self::nilai($t['masukan']['titik_ukur']);
                $diharapkan = $x['parameter'] === 'suhu' ? ThermohygroCalculator::CHAMBER_BIOBASE : $x['chamber'];

                $this->assertSame($x['chamber'], self::titikApp(self::grupApp($x['kunci']), $t['titik_ke'])['chamber'], "chamber titik {$t['titik_ke']}");

                if ($x['parameter'] === 'kelembaban') {
                    $this->assertSame($diharapkan, ThermohygroCalculator::chamberUntuk($setPoint), "chamberUntuk({$setPoint}) lawan blok {$x['kunci']}");
                }
            }
        }
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Rata-rata, STDEV, koreksi standar, nilai terkoreksi, rata-rata dan STDEV UUT (`PERHITUNGAN FC`) serta angka
     * mentah kolom Standard/UUT/Correction di `SERTIFIKAT`, sel demi sel.
     */
    #[DataProvider('kunciGrup')]
    public function test_tahap_titik_sama_dengan_perhitungan_fc_dan_sertifikat(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);

        foreach ($x['titik'] as $t) {
            $a = self::titikApp($g, $t['titik_ke']);
            $e = $t['excel'];
            $c = $t['cetak'];
            $k = "{$kunci} titik {$t['titik_ke']}";

            $this->assertSamaExcel($e['rata_rata_standar'], $a['rata_rata_standar'], "{$k}: rata-rata standar");
            $this->assertSamaExcel($e['stdev_standar'], $a['standar_deviasi_standar'], "{$k}: STDEV standar");
            $this->assertSamaExcel($e['koreksi_standar'], $a['koreksi_standar'], "{$k}: koreksi standar");
            $this->assertSamaExcel($e['standar_terkoreksi'], $a['standar_terkoreksi'], "{$k}: standar terkoreksi");
            $this->assertSamaExcel($e['rata_rata_uut'], $a['rata_rata_uut'], "{$k}: rata-rata UUT");
            $this->assertSamaExcel($e['stdev_uut'], $a['standar_deviasi_uut'], "{$k}: STDEV UUT");

            $this->assertSamaExcel($c['standar'], $a['standar_terkoreksi'], "{$k}: kolom Standard");
            $this->assertSamaExcel($c['uut'], $a['uut_terkoreksi'], "{$k}: kolom UUT");
            $this->assertSamaExcel($c['koreksi'], $a['koreksi'], "{$k}: kolom Correction");
        }

        $this->assertSamaExcel($x['stdev_maks_uut'], $g['standar_deviasi_maks_uut'], "{$kunci}: STDEV UUT terbesar");
    }

    /**
     * Excel mengambil koreksi lewat `VLOOKUP(indeks, tabel, 2, 0)` dengan indeks yang DIKETIK tangan; aplikasi memilih
     * titik tabel terdekat dari rata-rata standar. Di semua sepuluh titik sampel indeks ketikan = titik terdekat, jadi
     * hasilnya sama, tetapi sampel tidak bisa membedakan kedua aturan. Tes ini mengunci faktanya supaya klaim
     * "SAMA" tidak melebar.
     */
    public function test_indeks_koreksi_excel_diketik_dan_sama_dengan_titik_terdekat_aplikasi(): void
    {
        $tabel = self::w()['konstanta'];

        foreach (self::w()['grup'] as $x) {
            $baris = $x['parameter'] === 'suhu' ? $tabel['tabel_koreksi_suhu'] : $tabel['tabel_koreksi_rh'];

            foreach ($x['titik'] as $t) {
                $e = $t['excel'];
                $rata = self::nilai($e['rata_rata_standar']);
                $terdekat = null;
                $jarak = null;

                foreach ($baris as $b) {
                    $d = abs(self::nilai($b['titik']) - $rata);

                    if ($jarak === null || $d < $jarak) {
                        $jarak = $d;
                        $terdekat = self::nilai($b['titik']);
                    }
                }

                $this->assertTrue($e['indeks_koreksi_diketik'], "{$e['indeks_koreksi']['sel']} adalah angka ketikan, bukan rumus");
                $this->assertSame($terdekat, self::nilai($e['indeks_koreksi']), "titik {$t['titik_ke']}: indeks ketikan {$e['indeks_koreksi']['sel']} = titik tabel terdekat dari {$rata}");
            }
        }
    }

    // ------------------------------------------------------------------ budget

    /** Urutan baris budget aplikasi = urutan baris workbook (budget GEA memang tidak seurutan dua budget lain), dan semuanya ikut dihitung. */
    #[DataProvider('kunciGrup')]
    public function test_urutan_dan_jumlah_komponen_budget_sama_dengan_baris_workbook(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);

        $this->assertSame(array_keys($x['komponen']), array_column($g['budget'], 'sumber'), "urutan komponen {$kunci}");
        $this->assertSame([true], array_values(array_unique(array_column($g['budget'], 'disertakan'))), "semua komponen {$kunci} disertakan");
    }

    /**
     * ci dan vi tiap baris, lalu `ui`. Enam belas dari delapan belas baris SAMA dengan Excel; delapan baris akar ganda
     * (butir A-1) dikunci sebagai SELISIH: aplikasi `N/Q`, Excel `N/SQRT(Q)`. Baris akar ganda dikenali dari RUMUS di
     * sel (`=N../SQRT(Q..)` pada komponen selain keterulangan), bukan dari daftar ketikan; daftarnya hanya dicocokkan.
     *
     * @param  list<string>  $diharapkanAkarGanda
     */
    #[DataProvider('grupDenganBarisAkarGanda')]
    public function test_u_komponen_sama_dengan_excel_kecuali_delapan_baris_akar_ganda(string $kunci, array $diharapkanAkarGanda): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);
        $akarGanda = [];

        foreach ($x['komponen'] as $nama => $k) {
            $b = self::budgetApp($g, $nama);
            $konteks = "{$kunci} {$nama}";
            $n = self::nilai($k['u_mentah']);
            $q = self::nilai($k['pembagi']);

            $this->assertSamaExcel($k['ci'], $b['ci'], "{$konteks}: ci");
            $this->assertSamaExcel($k['vi'], (float) $b['vi'], "{$konteks}: vi");
            // Masukan N (sertifikat standar, daya baca, STDEV, drift, stabilitas, homogenitas) tidak ikut berselisih.
            $this->assertSamaHitungUlang($n, $b['u'] * self::pembagiBenar($nama, $q), "{$konteks}: N {$k['u_mentah']['sel']} = u aplikasi x pembagi");

            if (! self::akarGanda($nama, $x['rumus_komponen'][$nama])) {
                $this->assertSamaExcel($k['ui'], $b['u'], "{$konteks}: ui");

                continue;
            }

            $akarGanda[] = $nama;
            $excel = self::nilai($k['ui']);

            $this->assertSamaExcel($k['ui'], $n / sqrt($q), "{$konteks}: rumus Excel N/SQRT(Q) terbaca benar");
            $this->assertSamaHitungUlang($n / $q, $b['u'], "{$konteks}: aplikasi N/Q");
            $this->assertGreaterThan(0.05, abs($b['u'] - $excel) / $excel, "{$konteks}: SELISIH A-1 nyata (Excel {$excel}, aplikasi {$b['u']})");
        }

        $this->assertSame($diharapkanAkarGanda, $akarGanda, "baris akar ganda {$kunci}");
    }

    // ------------------------------------------------------------------ agregasi

    /**
     * Agregasi bersama (`GumCalculator::agregasiBudget`) diberi `ui`, `ci`, `vi` PERSIS dari sel Excel. Tanpa perbedaan
     * masukan, hasilnya harus sama: uc (`AC27`), v_eff (`AC28`), k (`AC29`, `TINV`), U (`AC30`). Ini pembuktian bahwa
     * seluruh selisih U95 di grup lain berasal dari komponen (A-1), bukan dari agregasinya.
     */
    #[DataProvider('kunciGrup')]
    public function test_agregasi_dengan_masukan_excel_sama_dengan_blok_agregat_workbook(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $komponen = array_values(array_map(
            static fn (array $k): array => ['u' => self::nilai($k['ui']), 'ci' => self::nilai($k['ci']), 'vi' => self::nilai($k['vi'])],
            $x['komponen'],
        ));

        $agg = (new GumCalculator)->agregasiBudget($komponen);
        $a = $x['agregat'];

        $this->assertSamaExcel($a['uc'], $agg['ketidakpastian_gabungan'], "{$kunci}: uc");
        $this->assertSamaExcel($a['veff'], (float) $agg['derajat_kebebasan_efektif'], "{$kunci}: v_eff");
        $this->assertKSamaExcel($a['k'], $agg['faktor_cakupan_k'], "{$kunci}: k = TINV(0,05; v_eff)");
        $this->assertUSamaExcel($a['u_hitung'], $agg['ketidakpastian_diperluas'], "{$kunci}: U = k x uc");
    }

    /**
     * Aplikasi melawan rantai yang dihitung ulang di tes dari N, Q, ci, vi fixture dengan pembagi yang benar: uc dan
     * v_eff harus sama (SAMA). Lawan Excel, selisih U nyata (SELISIH A-1) dan nilainya ditulis di pesan supaya
     * dokumen paritas bisa mengutipnya.
     */
    #[DataProvider('kunciGrup')]
    public function test_agregat_aplikasi_sama_dengan_rantai_pembagi_benar_dan_berselisih_dari_excel(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);

        $jumlah = 0.0;
        $penyebut = 0.0;

        foreach ($x['komponen'] as $nama => $k) {
            $uici = self::nilai($k['u_mentah']) / self::pembagiBenar($nama, self::nilai($k['pembagi'])) * self::nilai($k['ci']);
            $jumlah += $uici ** 2;

            if ($uici !== 0.0) {
                $penyebut += $uici ** 4 / self::nilai($k['vi']);
            }
        }

        $this->assertSamaHitungUlang(sqrt($jumlah), $g['ketidakpastian_gabungan'], "{$kunci}: uc rantai pembagi benar");
        $this->assertSamaHitungUlang($jumlah ** 2 / $penyebut, $g['derajat_kebebasan_efektif'], "{$kunci}: v_eff rantai pembagi benar");

        $excelUc = self::nilai($x['agregat']['uc']);
        $excelU = self::nilai($x['agregat']['u_hitung']);
        $selisihUc = ($g['ketidakpastian_gabungan'] - $excelUc) / $excelUc;
        $selisihU = ($g['ketidakpastian_diperluas'] - $excelU) / $excelU;

        $this->assertGreaterThan(self::BATAS_SELISIH_A1, abs($selisihUc), sprintf('%s: SELISIH A-1 uc: Excel %.9f, aplikasi %.9f (%+.2f %%)', $kunci, $excelUc, $g['ketidakpastian_gabungan'], $selisihUc * 100));
        $this->assertGreaterThan(self::BATAS_SELISIH_A1, abs($selisihU), sprintf('%s: SELISIH A-1 U: Excel %.9f, aplikasi %.9f (%+.2f %%)', $kunci, $excelU, $g['ketidakpastian_diperluas'], $selisihU * 100));
    }

    /**
     * Catatan audit `pembagi_akar_ganda` (yang terbaca orang yang membuka jejak sesi) menyebut "dengan pembagi master
     * angkanya X". X itu harus sama dengan U Excel (`AC30`/`AC51`/`AC70`). Selisih yang tersisa hanya `k` pecahan
     * (B4-4) dan pembulatan 6 desimal teks catatan.
     */
    #[DataProvider('kunciGrup')]
    public function test_catatan_audit_akar_ganda_mengutip_angka_master_yang_sama_dengan_excel(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);

        $catatan = array_values(array_filter($g['catatan_audit'], static fn (array $c): bool => $c['kode'] === 'pembagi_akar_ganda'));
        $this->assertCount(1, $catatan, "{$kunci}: satu catatan pembagi_akar_ganda");

        $this->assertSame(1, preg_match('/angkanya ([0-9.,]+)\./', $catatan[0]['pesan'], $m), 'pola kalimat catatan berubah: '.$catatan[0]['pesan']);
        $kutipan = (float) str_replace(',', '.', str_replace('.', '', $m[1]));

        $this->assertEqualsWithDelta(self::nilai($x['agregat']['u_hitung']), $kutipan, 1e-5, "{$kutipan} (catatan) lawan {$x['agregat']['u_hitung']['sel']}");
    }

    /**
     * `k` aplikasi memakai v_eff pecahan (`FLOOR_V_EFF = false`), Excel `TINV(0,05; v_eff)` memotong derajat bebas ke
     * bilangan bulat. Dua bukti terpisah: (1) di v_eff Excel sendiri, kuantil tanpa potong BERBEDA dari sel `k` Excel
     * (jadi Excel memotong, bukan memakai aproksimasi polinomial seperti tertulis di docblock konstanta); (2) selisih
     * `k` aplikasi lawan kuantil terpotong di v_eff aplikasi nyata tetapi menggeser U kurang dari seperdua puluh langkah
     * cetak (0,05).
     */
    #[DataProvider('kunciGrup')]
    public function test_k_aplikasi_dan_excel_beda_hanya_karena_v_eff_tidak_dipotong(string $kunci): void
    {
        $this->assertFalse(ThermohygroCalculator::FLOOR_V_EFF, 'asumsi tes ini: v_eff pecahan; bila berubah, perbarui B4-4');

        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);
        $t = new StudentTDistribution;

        $kExcel = self::nilai($x['agregat']['k']);
        $veffExcel = self::nilai($x['agregat']['veff']);

        $this->assertGreaterThan(self::TOL_K, abs($t->quantile(0.975, $veffExcel) - $kExcel), "{$kunci}: Excel TINV memotong derajat bebas (kuantil tanpa potong != {$x['agregat']['k']['sel']})");

        $selisihK = abs($g['faktor_cakupan_k'] - $t->quantile(0.975, floor($g['derajat_kebebasan_efektif'])));

        $this->assertGreaterThan(self::TOL_K, $selisihK, "{$kunci}: k aplikasi memakai v_eff pecahan");
        $this->assertLessThan(0.005, $selisihK * $g['ketidakpastian_gabungan'], "{$kunci}: pengaruh k pecahan pada U jauh di bawah langkah cetak");
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * U95 dan `k` di `SERTIFIKAT` (`U22`/`U35`/`U49`, `T30`/`T64`): format sel sama dengan format aplikasi, teks cetak
     * sama persis, dan keputusan lantai CMC (U hitung vs CMC) sama. U95 hitung suhu berselisih (A-1) tetapi tercetak
     * sama ("2.0"); %RH tertahan lantai CMC ("4.8") di Excel maupun aplikasi. Tabel Humidity memuat SATU sel `k`
     * (`T64`, milik chamber Biobase) dan dipakai kedua chamber; kedua `k` aplikasi tercetak "2" seperti sel itu.
     */
    #[DataProvider('kunciGrup')]
    public function test_u95_dan_k_tercetak_sama_dengan_sertifikat(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);
        $format = (new ThermohygroProfile)->formatKolomSertifikat(new CalibrationSession, self::nilai($x['titik'][0]['masukan']['titik_ukur']), $g['satuan']);

        $this->assertSame($x['cetak_u95']['format'], $format['u95'], "format {$x['cetak_u95']['sel']}");
        $this->assertSame($x['cetak_k']['format'], $format['k'], "format {$x['cetak_k']['sel']}");
        $this->assertSame(TataLetakSertifikat::desimalFormat($format['u95']), (new ThermohygroProfile)->desimalU95(), 'desimal U95 profil');

        $this->assertSame(
            $x['cetak_u95']['teks'],
            self::keTitik(TataLetakSertifikat::formatAngka($g['u95_sertifikat'], $format['u95'])),
            "U95 tercetak vs {$x['cetak_u95']['sel']}",
        );
        $this->assertSame(
            $x['cetak_k']['teks'],
            self::keTitik(TataLetakSertifikat::formatAngka($g['faktor_cakupan_k'], $format['k'])),
            "k tercetak vs {$x['cetak_k']['sel']}",
        );

        $a = $x['agregat'];
        $excelDariCmc = self::nilai($a['cmc']) > self::nilai($a['u_hitung']);

        $this->assertSamaExcel($a['u_cetak'], max(self::nilai($a['u_hitung']), self::nilai($a['cmc'])), "{$kunci}: Excel U dilaporkan = max(U, CMC)");
        $this->assertSame($excelDariCmc, $g['sumber_u95'] === 'cmc', "{$kunci}: keputusan lantai CMC");

        if ($excelDariCmc) {
            $this->assertSamaExcel($a['u_cetak'], $g['u95_sertifikat'], "{$kunci}: U dilaporkan (CMC)");
        }
    }

    /**
     * Kolom Standard / UUT / Correction tiap titik, teks cetak: format sel (`0.00`, `0.0` suhu / `0` %RH, `0.00`) sama
     * dengan format profil, dan teks aplikasi = teks Excel di semua sepuluh titik. Tidak ada nol bertanda minus di
     * sampel, jadi keputusan "-0.0" tidak diuji di sini.
     */
    #[DataProvider('kunciGrup')]
    public function test_angka_cetak_tiap_titik_sama_dengan_sertifikat(string $kunci): void
    {
        $x = self::grupExcel($kunci);
        $g = self::grupApp($kunci);
        $profil = new ThermohygroProfile;

        $this->assertSame(2, $profil->desimalSertifikat(), 'desimal kolom profil');

        foreach ($x['titik'] as $t) {
            $a = self::titikApp($g, $t['titik_ke']);
            $format = $profil->formatKolomSertifikat(new CalibrationSession, self::nilai($t['masukan']['titik_ukur']), $g['satuan']);
            $nilai = ['standar' => $a['standar_terkoreksi'], 'uut' => $a['uut_terkoreksi'], 'koreksi' => $a['koreksi']];

            foreach ($nilai as $kolom => $angka) {
                $c = $t['cetak'][$kolom];

                $this->assertFalse($c['nol_bertanda_minus'], "{$c['sel']}: sampel tanpa nol bertanda minus");
                $this->assertSame($c['format'], $format[$kolom], "format {$c['sel']}");
                $this->assertSame($c['teks'], self::keTitik(TataLetakSertifikat::formatAngka($angka, $format[$kolom])), "{$kolom} titik {$t['titik_ke']} tercetak vs {$c['sel']}");
            }
        }
    }

    // ------------------------------------------------------------------ konstanta data

    /**
     * Konstanta lab di aplikasi (`database/data/tabel-master-suhu-3alat.json`, bagian `thermohygro`) sama dengan sel
     * `STANDAR_KALIBRATOR`, `stabilitas dan homogenitas Cham`, dan `DATABASE`: U95 dan drift kalibrator, enam nilai
     * chamber, kedua CMC, dan seluruh tabel koreksi (7 titik suhu, 9 titik %RH) baris demi baris.
     */
    public function test_konstanta_dan_tabel_koreksi_aplikasi_sama_dengan_workbook(): void
    {
        $tabel = new TabelKalibratorSuhu3Alat;
        $th = $tabel->thermohygro();
        $k = self::w()['konstanta'];

        $this->assertSamaExcel($k['u95_kalibrator_suhu'], (float) $th['u95_kalibrator']['suhu'], 'U95 kalibrator suhu');
        $this->assertSamaExcel($k['u95_kalibrator_rh'], (float) $th['u95_kalibrator']['rh'], 'U95 kalibrator %RH');
        $this->assertSamaExcel($k['drift_kalibrator_suhu'], (float) $th['drift_kalibrator']['suhu'], 'drift kalibrator suhu');
        $this->assertSamaExcel($k['drift_kalibrator_rh'], (float) $th['drift_kalibrator']['rh'], 'drift kalibrator %RH');

        foreach (['biobase_suhu', 'biobase_rh', 'gea_rh'] as $chamber) {
            $this->assertSamaExcel($k["stabilitas_{$chamber}"], (float) $th['chamber'][$chamber]['stabilitas'], "stabilitas {$chamber}");
            $this->assertSamaExcel($k["homogenitas_{$chamber}"], (float) $th['chamber'][$chamber]['homogenitas'], "homogenitas {$chamber}");
        }

        $cmc = array_column($tabel->cmcMaster(TabelKalibratorSuhu3Alat::ALAT_THERMOHYGRO), 'u', 'rentang');
        $this->assertSamaExcel($k['cmc_suhu'], (float) $cmc['Suhu'], 'CMC suhu');
        $this->assertSamaExcel($k['cmc_kelembaban'], (float) $cmc['Kelembaban'], 'CMC %RH');

        foreach (['koreksi_suhu' => 'tabel_koreksi_suhu', 'koreksi_rh' => 'tabel_koreksi_rh'] as $kunciApp => $kunciExcel) {
            $this->assertCount(count($k[$kunciExcel]), $th[$kunciApp], "jumlah baris {$kunciApp}");

            foreach ($k[$kunciExcel] as $i => $baris) {
                $this->assertSamaExcel($baris['titik'], (float) $th[$kunciApp][$i]['titik'], "titik tabel {$kunciApp}[{$i}]");
                $this->assertSamaExcel($baris['koreksi'], (float) $th[$kunciApp][$i]['koreksi'], "koreksi tabel {$kunciApp}[{$i}]");
                $this->assertSamaExcel(
                    $baris['koreksi'],
                    (float) $tabel->koreksiThermohygro($kunciApp === 'koreksi_suhu' ? 'suhu' : 'kelembaban', self::nilai($baris['titik'])),
                    "koreksiThermohygro di titik tabel {$baris['titik']['sel']}",
                );
            }
        }
    }

    /**
     * Baris CMC yang dipakai profil (`kemampuan-kalibrasi.json`, Thermohygrometer, lampiran LK-285-IDN) sama dengan
     * `DATABASE!S5/S6`. Itu yang jadi lantai U95 di produksi; angka yang diadu ke Excel di tes lain datang dari sel yang sama.
     */
    public function test_cmc_lampiran_akreditasi_sama_dengan_database_workbook(): void
    {
        $data = json_decode((string) file_get_contents(base_path('database/data/kemampuan-kalibrasi.json')), true, flags: JSON_THROW_ON_ERROR);
        $baris = null;

        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                if ($alat['nama_alat'] === 'Thermohygrometer') {
                    $baris = array_column($alat['rentang'], 'ketidakpastian', 'parameter');
                }
            }
        }

        $this->assertNotNull($baris, 'Thermohygrometer ada di data kemampuan');
        $this->assertSamaExcel(self::w()['konstanta']['cmc_suhu'], (float) $baris['Suhu'], 'CMC suhu lampiran');
        $this->assertSamaExcel(self::w()['konstanta']['cmc_kelembaban'], (float) $baris['Kelembapan'], 'CMC kelembapan lampiran');
    }

    // ------------------------------------------------------------------ cacat dan urutan di sertifikat

    /**
     * Baris `Uncertainty 95% ± ... %RH` di bawah tabel Humidity: master `SERTIFIKAT!M63 = #REF!` (kerusakan
     * salin-tempel). Aplikasi mencetak U95 %RH yang benar (angka kolom U95 tabel Humidity); itu deviasi sengaja dengan
     * catatan audit `thermohygro_u95_rh_ref_rusak`. Yang dikunci di sini: Excel memang tidak punya angka di sel itu, dan
     * U95 %RH yang tersedia untuk aplikasi (kedua chamber) tercetak "4.8" di `U35`/`U49`.
     */
    public function test_sel_u95_kelembapan_di_bawah_tabel_rusak_dan_aplikasi_mencetak_nilai_yang_benar(): void
    {
        $galat = self::w()['u95_rh_bawah_tabel_galat'];

        $this->assertSame('SERTIFIKAT!M63', $galat['sel']);
        $this->assertSame('#REF!', $galat['nilai_teks']);
        $this->assertSame('=#REF!', $galat['rumus']);

        foreach (['kelembaban_biobase', 'kelembaban_gea'] as $kunci) {
            $x = self::grupExcel($kunci);

            $this->assertSame('4.8', $x['cetak_u95']['teks'], "{$x['cetak_u95']['sel']}");
            $this->assertSame('4.8', self::keTitik(TataLetakSertifikat::formatAngka(self::grupApp($kunci)['u95_sertifikat'], '0.0')), "U95 %RH aplikasi {$kunci}");
        }
    }

    /**
     * Tabel Humidity di master satu tabel: baris chamber GEA (`E35:E36`) dicetak SEBELUM baris Biobase (`E49:E51`), jadi
     * urut menaik menurut nilai standar. Kalkulator mengurutkan grup Biobase dulu (docblock-nya menulis "sama seperti
     * letak dua tabelnya di sheet SERTIFIKAT", yang keliru). Ini selisih URUTAN, bukan angka; apakah urutan itu sampai
     * ke kertas bergantung pada `titik_ke` yang diisi teknisi (profil mengurutkan baris menurut `titik_ke`) dan butuh
     * keputusan lab.
     */
    public function test_urutan_tabel_humidity_master_gea_dulu_sedangkan_kalkulator_biobase_dulu(): void
    {
        $baris = [];
        $standar = [];

        foreach (['kelembaban_biobase', 'kelembaban_gea'] as $kunci) {
            foreach (self::grupExcel($kunci)['titik'] as $t) {
                $this->assertSame(1, preg_match('/^SERTIFIKAT!E(\d+)$/', $t['cetak']['standar']['sel'], $m));
                $baris[$kunci][] = (int) $m[1];
                $standar[(int) $m[1]] = self::nilai($t['cetak']['standar']);
            }
        }

        $this->assertLessThan(min($baris['kelembaban_biobase']), max($baris['kelembaban_gea']), 'master: baris GEA di atas baris Biobase');

        ksort($standar);
        $menaik = array_values($standar);
        sort($menaik);
        $this->assertSame($menaik, array_values($standar), 'master: nilai Standard tabel Humidity menaik dari atas ke bawah');

        $urutanKalkulator = array_map(
            static fn (array $g): string => $g['parameter'].'_'.$g['chamber'],
            self::hasil()['grup'],
        );
        $this->assertSame(['suhu_biobase', 'kelembaban_biobase', 'kelembaban_gea'], $urutanKalkulator, 'SELISIH urutan: kalkulator Biobase sebelum GEA, master GEA sebelum Biobase');
    }
}
