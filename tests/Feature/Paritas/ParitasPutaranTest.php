<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationSession;
use App\Services\Calibration\Profiles\CentrifugeProfile;
use App\Services\Calibration\Profiles\ProfilPutaran;
use App\Services\Calibration\Profiles\TachometerProfile;
use App\Services\Calibration\PutaranCalculator;
use App\Services\Calibration\TabelStandarPutaran;
use App\Support\TataLetakSertifikat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS keluarga putaran: aplikasi lawan `Master Olda Tachometer.xlsm` (18 titik, 6 blok) dan
 * `Master Olda Centrifuge.xlsm` (15 titik, 5 blok), sel demi sel. Fixture `tests/Fixtures/paritas/{tachometer,centrifuge}.json`,
 * dibangun `docs/skrip/gen-fixture-paritas-batch4.py` dari nilai-cache workbook.
 *
 * Titik panggil terkecil tanpa HTTP: `PutaranCalculator::hitungBlok()` (yang dipanggil `ProfilPutaran::hitungPerGrup`),
 * satu blok = tiga titik. Tidak ada database: tabel standar kalibrator dibaca dari `database/data/tabel-standar-putaran.json`.
 *
 * Dua mode tabel standar, karena dua hal yang SENGAJA berbeda dari master tidak boleh bercampur dengan
 * kesetaraan rumus:
 *  - "versi master" (subkelas di bawah): drift kalibrator hanya dari 5 baris berrumus kolom K, seperti
 *    `Drift Std Kalibrator!K35`. Mode ini harus SAMA dengan Excel di semua blok yang tidak rusak;
 *  - tabel produksi: drift dilengkapi (keputusan lab, `docs/pertanyaan-lab-waktu-frekuensi.md` §3), jadi
 *    komponen drift dan U95 sebelum lantai CMC lebih besar. Itu SELISIH yang dikunci, bukan dibetulkan.
 *
 * Selisih yang dikunci (nomor dan uraian di `docs/paritas-batch4.md`):
 *  drift (§3): Excel 2,25 rpm lawan aplikasi 2,75 rpm di setengah-lebar;
 *  Tachometer blok 5 (§2): pita sertifikat F15 lawan F18, drift tautan luar K54, pengulangan baris kosong;
 *  B4-2: daya baca standar K94 diketik 0,1 lawan 1 di aplikasi (belum dijawab lab);
 *  Centrifuge blok 1 (§2): pengulangan satu sel `G34`;
 *  B4-3: alamat pengulangan Tachometer blok 6 menunjuk baris simpangan baku blok 5 (angka sampel sama);
 *  nol bertanda minus: Excel `-0.0`, cetak aplikasi `0.0` (keputusan G0-b P4).
 */
#[Group('paritas')]
class ParitasPutaranTest extends TestCase
{
    use MenegakkanParitas;

    private const KOMPONEN = ['ketidakpastian_standar', 'resolusi_standar', 'resolusi_alat', 'drift_standar', 'pengulangan_pembacaan'];

    private const KOLOM_TITIK = ['rata_rata', 'nominal_standar', 'koreksi_standar', 'nilai_terkoreksi', 'koreksi', 'standar_deviasi'];

    /**
     * Koreksi dan nilai terkoreksi adalah selisih dua bilangan sebesar set point (sampai 25002 rpm, ulp ~3,6e-12),
     * jadi lantai mutlak 1e-12 trait terlalu kecil di sana. Dipakai 70 ulp dari set point (1,5e-14 x set point),
     * ukuran yang sama dengan alasan `TOL_MUTLAK` di trait.
     */
    private const TOL_ULP_SET_POINT = 1.5e-14;

    /** @return array<string, array{string}> */
    public static function alat(): array
    {
        return ['tachometer' => ['tachometer'], 'centrifuge' => ['centrifuge']];
    }

    /** @return array<string, mixed> */
    private static function w(string $alat): array
    {
        return self::fixtureParitas($alat)['workbook'];
    }

    private static function profil(string $alat): ProfilPutaran
    {
        return $alat === 'tachometer' ? new TachometerProfile : new CentrifugeProfile;
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $blok
     * @return list<array<string, mixed>>
     */
    private static function titikBlok(array $w, array $blok): array
    {
        return array_values(array_filter($w['titik'], static fn (array $t): bool => in_array($t['titik_ke'], $blok['titik_ke'], true)));
    }

    /** @return array<string, mixed> */
    private function hitungBlok(string $alat, int $indeks, bool $versiMaster): array
    {
        $w = self::w($alat);
        $blok = $w['blok'][$indeks];

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => self::nilai($t['set_point']),
            'pembacaan' => array_map('floatval', $t['pembacaan']['nilai']),
        ], self::titikBlok($w, $blok));

        return (new PutaranCalculator($versiMaster ? new TabelStandarPutaranVersiMasterParitas : new TabelStandarPutaran))->hitungBlok($titik, [
            // Resolusi UUT dibaca dari sel budget workbook (`N{r0+2} = resolusi x 0,5`), bukan diketik ulang.
            'resolusi_uut' => 2 * self::nilai($blok['komponen']['resolusi_alat']['u_mentah']),
            'cmc' => self::nilai($blok['agregat']['cmc']),
            'satuan' => 'rpm',
            'alat' => self::profil($alat)->kode(),
            'blok_ke' => $indeks + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return array<string, array<string, mixed>>
     */
    private static function komponen(array $hasil): array
    {
        $peta = [];
        foreach ($hasil['budget'] as $k) {
            $peta[$k['sumber']] = $k;
        }

        return $peta;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return array<string, array<string, mixed>>
     */
    private static function catatan(array $hasil): array
    {
        $peta = [];
        foreach ($hasil['catatan_audit'] as $c) {
            $peta[$c['kode']] = $c;
        }

        return $peta;
    }

    /**
     * @param  array<string, mixed>  $blok
     * @param  array<string, mixed>  $hasil
     * @param  list<string>  $kecuali
     */
    private function assertKomponenSamaExcel(array $blok, array $hasil, string $konteks, array $kecuali = []): void
    {
        $this->assertSame(self::KOMPONEN, array_keys($blok['komponen']), "{$konteks}: urutan lima komponen = baris r0..r0+4 PERHITUNGAN U95%");
        $app = self::komponen($hasil);
        $this->assertSame(self::KOMPONEN, array_keys($app), "{$konteks}: komponen aplikasi sama dengan lima komponen Excel");

        foreach (self::KOMPONEN as $nama) {
            if (in_array($nama, $kecuali, true)) {
                continue;
            }

            $x = $blok['komponen'][$nama];
            $this->assertSamaExcel($x['vi'], (float) $app[$nama]['vi'], "{$konteks} {$nama}: vi");
            $this->assertSamaExcel($x['ui'], (float) $app[$nama]['u'], "{$konteks} {$nama}: ui");
            $this->assertSamaExcel($x['ci'], (float) $app[$nama]['ci'], "{$konteks} {$nama}: ci");
            $this->assertSamaExcel($x['uici'], (float) $app[$nama]['u'] * (float) $app[$nama]['ci'], "{$konteks} {$nama}: ui*ci");
        }
    }

    // ------------------------------------------------------------------ konstanta data

    /**
     * Tabel sertifikat kalibrator (`SERTIFIKAT KALIBRATOR!C:F`): nominal, koreksi, dan U95 pita di aplikasi
     * (`database/data/tabel-standar-putaran.json`) sama dengan sel workbook, baris demi baris. Kedua workbook
     * memuat tabel yang sama persis.
     */
    #[DataProvider('alat')]
    public function test_tabel_sertifikat_kalibrator_sama_dengan_workbook(string $alat): void
    {
        $tabel = new TabelStandarPutaran;
        $baris = self::w($alat)['sertifikat_kalibrator'];

        $this->assertSame(
            array_map(static fn (array $b): float => (float) $b['nominal']['nilai'], $baris),
            array_map(static fn (array $b): float => (float) $b['nominal'], $tabel->sertifikat()),
            'daftar nominal sertifikat kalibrator',
        );

        foreach ($baris as $b) {
            $nominal = self::nilai($b['nominal']);
            $this->assertNotNull($tabel->koreksi($nominal), "nominal {$nominal}: koreksi ada di tabel aplikasi");
            $this->assertSamaExcel($b['koreksi'], (float) $tabel->koreksi($nominal), "koreksi standar nominal {$nominal}");

            if ($b['u95'] !== null) {
                $this->assertSamaExcel($b['u95'], (float) $tabel->u95Sertifikat($nominal), "U95 pita nominal {$nominal}");
            }
        }
    }

    /**
     * Pita CMC: baris data aplikasi (`kemampuan-kalibrasi.json`, kelompok Waktu dan Frekuensi) sama dengan
     * `DATABASE!R5:S6`. Pemilihan pita lewat database dijaga `LantaiCmcPutaranTest`; yang dikunci di sini
     * hanya angkanya, dan bahwa tiap blok memakai pita yang melingkupi set point tertingginya.
     */
    #[DataProvider('alat')]
    public function test_pita_cmc_data_aplikasi_sama_dengan_database_workbook(string $alat): void
    {
        $w = self::w($alat);
        $nama = $alat === 'tachometer' ? 'Infrared Tachometer' : 'Centrifuge';

        $data = json_decode((string) file_get_contents(base_path('database/data/kemampuan-kalibrasi.json')), true, flags: JSON_THROW_ON_ERROR);
        $baris = null;
        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $a) {
                if ($a['nama_alat'] === $nama) {
                    $baris = $a;
                }
            }
        }
        $this->assertNotNull($baris, "{$nama} ada di data kemampuan");
        $this->assertCount(count($w['cmc_pita']), $baris['rentang'], 'jumlah pita CMC');

        foreach ($w['cmc_pita'] as $i => $pita) {
            [$min, $maks] = array_map('floatval', explode('-', $pita['label']['nilai']));
            $rentang = $baris['rentang'][$i];

            $this->assertSame($min, (float) $rentang['min'], "batas bawah pita {$pita['label']['sel']}");
            $this->assertSame($maks, (float) $rentang['max'], "batas atas pita {$pita['label']['sel']}");
            $this->assertSamaExcel($pita['cmc'], (float) $rentang['ketidakpastian'], "CMC pita {$pita['label']['nilai']}");
        }

        $diLuarPita = 0;
        $pitaTeratas = $w['cmc_pita'][array_key_last($w['cmc_pita'])];
        $batasAtas = (float) explode('-', $pitaTeratas['label']['nilai'])[1];

        foreach ($w['blok'] as $b) {
            $tertinggi = max(array_map(static fn (array $t): float => self::nilai($t['set_point']), self::titikBlok($w, $b)));
            $pita = null;
            foreach ($w['cmc_pita'] as $p) {
                [$min, $maks] = array_map('floatval', explode('-', $p['label']['nilai']));
                if ($tertinggi >= $min && $tertinggi <= $maks) {
                    $pita = $p;
                    break;
                }
            }

            // Di atas pita teratas Excel tetap memakai CMC pita teratas (tidak ada sel kosong); aplikasi juga
            // (lantai terpasang + peringatan `*_di_luar_akreditasi`, dijaga `LantaiCmcPutaranTest`).
            if ($pita === null) {
                $this->assertGreaterThan($batasAtas, $tertinggi, "blok r{$b['baris_u95']}: tanpa pita hanya bila di atas batas teratas");
                $pita = $pitaTeratas;
                $diLuarPita++;
            }

            $this->assertSamaExcel($b['agregat']['cmc'], self::nilai($pita['cmc']), "blok r{$b['baris_u95']}: CMC yang dipakai Excel = pita yang melingkupi {$tertinggi} rpm (atau pita teratas bila di luar)");
        }

        // Sampel Centrifuge memuat set point sampai 25000 rpm, di atas batas akreditasi 9000 rpm.
        $this->assertSame($alat === 'centrifuge' ? 2 : 0, $diLuarPita, 'jumlah blok sampel di luar pita akreditasi');
    }

    // ------------------------------------------------------------------ tahap titik

    /**
     * Rata-rata, nominal standar terdekat, koreksi standar, nilai terkoreksi, koreksi, dan simpangan baku
     * SEMUA titik di kedua alat, sel demi sel (`PERHITUNGAN!{G,I,K}{r+8..r+13}`). Nominal standar diturunkan
     * aplikasi dari set point, sedangkan Excel mengetik `Indexed Value`; aturan turunannya (terdekat, seri ke
     * atas) cocok di semua titik sampel.
     */
    #[DataProvider('alat')]
    public function test_kolom_titik_cocok_excel_semua_titik(string $alat): void
    {
        $w = self::w($alat);
        $dicek = 0;

        foreach ($w['blok'] as $i => $blok) {
            $h = $this->hitungBlok($alat, $i, versiMaster: true);
            $this->assertSame([], $h['ditolak'], "{$alat} blok ".($i + 1).': tidak ada titik yang ditolak');
            $app = [];
            foreach ($h['titik'] as $t) {
                $app[$t['titik_ke']] = $t;
            }

            foreach (self::titikBlok($w, $blok) as $t) {
                $setPoint = self::nilai($t['set_point']);
                $mutlak = max(self::TOL_MUTLAK, self::TOL_ULP_SET_POINT * $setPoint);
                $this->assertArrayHasKey($t['titik_ke'], $app, "{$alat} titik {$t['titik_ke']}: dihitung aplikasi");

                foreach (self::KOLOM_TITIK as $kunci) {
                    $this->assertSamaExcel($t['excel'][$kunci], (float) $app[$t['titik_ke']][$kunci], "{$alat} titik {$t['titik_ke']} {$kunci}", mutlak: $mutlak);
                }
                $dicek++;
            }
        }

        $this->assertSame(count($w['titik']), $dicek, 'semua titik fixture terbandingkan');
    }

    // ------------------------------------------------------------------ tahap budget dan agregat

    /**
     * Blok yang TIDAK rusak di workbook, dengan tabel versi master: lima komponen (ui, ci, vi, ui*ci), uc, v_eff,
     * k, U sebelum lantai, dan U sesudah lantai CMC SAMA di setiap blok; aplikasi tidak mencatat penyimpangan
     * apa pun dari master di jejak auditnya. Blok rusak dibahas di tes SELISIH di bawah.
     */
    #[DataProvider('alat')]
    public function test_budget_dan_agregat_blok_tidak_rusak_cocok_excel(string $alat): void
    {
        $w = self::w($alat);
        $dicek = 0;

        foreach ($w['blok'] as $i => $blok) {
            if ($blok['rusak']) {
                continue;
            }

            $konteks = "{$alat} blok ".($i + 1)." (r{$blok['baris_u95']})";
            $h = $this->hitungBlok($alat, $i, versiMaster: true);
            $a = $blok['agregat'];

            $this->assertKomponenSamaExcel($blok, $h, $konteks);
            $this->assertSamaExcel($a['uc'], $h['ketidakpastian_gabungan'], "{$konteks}: uc");
            $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], "{$konteks}: v_eff");
            $this->assertKSamaExcel($a['k'], $h['faktor_cakupan_k'], "{$konteks}: k (TINV, df dipotong)");
            $this->assertUSamaExcel($a['u_hitung'], $h['ketidakpastian_diperluas'], "{$konteks}: U sebelum lantai CMC");
            $this->assertUSamaExcel($a['u_cetak'], $h['u95_sertifikat'], "{$konteks}: U sesudah lantai CMC");
            $this->assertSame([], $h['catatan_audit'], "{$konteks}: tidak ada penyimpangan dari master yang dicatat");
            $dicek++;
        }

        $this->assertSame(count($w['blok']) - count(array_filter($w['blok'], static fn (array $b): bool => $b['rusak'])), $dicek);
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Angka CETAK per titik (set point, nilai terkoreksi, koreksi; `SERTIFIKAT!C,J,P`) dan U95 per blok
     * (`SERTIFIKAT!S`) pada pemformat produksi `TataLetakSertifikat::formatAngka` dengan format kolom profil
     * (`0.0`, atau `0` di Tachometer sejak 10000 rpm): teks sama persis. Kolom Standard/UUT kedua alat
     * bertukar posisi, jadi dibandingkan per ISI (set point, terkoreksi), bukan per nama kolom.
     * Titik berkoreksi nol bertanda minus di Excel dikecualikan dari teks koreksi (tes tersendiri di bawah).
     */
    #[DataProvider('alat')]
    public function test_angka_cetak_sama_dengan_sertifikat_excel(string $alat): void
    {
        $w = self::w($alat);
        $profil = self::profil($alat);
        $sesi = new CalibrationSession;

        foreach ($w['blok'] as $i => $blok) {
            $h = $this->hitungBlok($alat, $i, versiMaster: true);
            $app = [];
            foreach ($h['titik'] as $t) {
                $app[$t['titik_ke']] = $t;
            }

            foreach (self::titikBlok($w, $blok) as $t) {
                $setPoint = self::nilai($t['set_point']);
                $format = TataLetakSertifikat::normalisasiFormat($profil->formatKolomSertifikat($sesi, $setPoint));
                $c = $t['cetak'];
                $n = $t['titik_ke'];

                $this->assertSame($format['uut'], $c['set_point']['format'], "{$alat} titik {$n}: format sel {$c['set_point']['sel']}");
                $this->assertSame($format['standar'], $c['terkoreksi']['format'], "{$alat} titik {$n}: format sel {$c['terkoreksi']['sel']}");
                $this->assertSame($format['koreksi'], $c['koreksi']['format'], "{$alat} titik {$n}: format sel {$c['koreksi']['sel']}");

                $this->assertSame($c['set_point']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($setPoint, $format['uut'])), "{$alat} titik {$n}: set point vs {$c['set_point']['sel']}");
                $this->assertSame($c['terkoreksi']['teks'], self::keTitik(TataLetakSertifikat::formatAngka((float) $app[$n]['nilai_terkoreksi'], $format['standar'])), "{$alat} titik {$n}: terkoreksi vs {$c['terkoreksi']['sel']}");

                $koreksiCetak = $profil->tandaKoreksiSertifikat() * (float) $app[$n]['koreksi'];
                $this->assertSamaExcel($c['koreksi'], $koreksiCetak, "{$alat} titik {$n}: koreksi cetak (tanda profil x koreksi) vs {$c['koreksi']['sel']}", mutlak: max(self::TOL_MUTLAK, self::TOL_ULP_SET_POINT * $setPoint));

                if (! $c['koreksi']['nol_bertanda_minus']) {
                    $this->assertSame($c['koreksi']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($koreksiCetak, $format['koreksi'], $profil->tandaNolDicetak())), "{$alat} titik {$n}: koreksi vs {$c['koreksi']['sel']}");
                }
            }

            if (! $blok['rusak']) {
                $format = TataLetakSertifikat::normalisasiFormat($profil->formatKolomSertifikat($sesi, 0.0));
                $this->assertSame($format['u95'], $blok['cetak_u95']['format'], "{$alat} blok ".($i + 1).": format sel {$blok['cetak_u95']['sel']}");
                $this->assertSame($blok['cetak_u95']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'], $format['u95'])), "{$alat} blok ".($i + 1).": U95 vs {$blok['cetak_u95']['sel']}");
            }
        }
    }

    /**
     * `k` tercetak `2` (`SERTIFIKAT!U37` Tachometer, `U34` Centrifuge; format `0`): satu sel yang menunjuk
     * `PERHITUNGAN U95%!AC17`, yaitu k blok PERTAMA. Angka k blok pertama aplikasi dan teks cetaknya dibandingkan;
     * di Centrifuge blok 1 rusak (pengulangan satu sel), jadi k-nya beda ~5,5e-7 dari Excel, dan itu SAMA
     * sesudah dicetak. Dikunci di tes SELISIH Centrifuge, bukan di sini.
     */
    #[DataProvider('alat')]
    public function test_k_cetak_sama_dengan_sertifikat_excel(string $alat): void
    {
        $w = self::w($alat);
        $profil = self::profil($alat);
        $format = TataLetakSertifikat::normalisasiFormat($profil->formatKolomSertifikat(new CalibrationSession, 0.0));
        $h = $this->hitungBlok($alat, 0, versiMaster: true);

        $this->assertSamaExcel($w['cetak_k'], self::nilai($w['k_blok_pertama']), 'sel k sertifikat = k blok pertama (AC17)', rel: 0.0, mutlak: 0.0);
        $this->assertSame($format['k'], $w['cetak_k']['format'], "format sel {$w['cetak_k']['sel']}");
        $this->assertSame($w['cetak_k']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($h['faktor_cakupan_k'], $format['k'], tandaNol: true)), "k vs {$w['cetak_k']['sel']}");
        $this->assertEqualsWithDelta(self::nilai($w['k_blok_pertama']), $h['faktor_cakupan_k'], 1e-5, 'k blok pertama aplikasi dekat Excel');
    }

    // ------------------------------------------------------------------ selisih terdokumentasi

    /**
     * SELISIH §3 (`docs/pertanyaan-lab-waktu-frekuensi.md`) — drift kalibrator. Excel: `Drift Std Kalibrator!K35 =
     * MAX(K13:K34)/2` = 2,25 rpm, dari kolom K yang hanya berrumus di 5 dari 15 baris. Tabel produksi memakai
     * semua baris berdata (2,75). Efeknya hanya di komponen drift: empat komponen lain SAMA, ui drift aplikasi
     * lebih besar, U lebih besar, dan aplikasi menyebutnya di jejak audit dengan nilai master yang sama dengan
     * sel Excel. Satu-satunya angka cetak yang terdampak adalah U95 blok di atas lantai CMC (lihat dokumen).
     */
    #[DataProvider('alat')]
    public function test_selisih_drift_tabel_produksi_lebih_besar_dari_master(string $alat): void
    {
        $w = self::w($alat);
        $produksi = new TabelStandarPutaran;

        $this->assertSamaExcel($w['drift']['k35'], $produksi->driftSetengahLebar(lengkap: false), 'drift versi master = K35');
        $this->assertSame([15, 18, 26, 28, 32], $w['drift']['baris_berrumus'], 'kolom K berrumus hanya di 5 baris');
        $this->assertGreaterThan(self::nilai($w['drift']['k35']), $produksi->driftSetengahLebar(), 'drift lengkap lebih besar dari K35');

        foreach ($w['blok'] as $i => $blok) {
            if ($blok['rusak']) {
                continue;
            }

            $konteks = "{$alat} blok ".($i + 1);
            $h = $this->hitungBlok($alat, $i, versiMaster: false);
            $app = self::komponen($h);

            $this->assertKomponenSamaExcel($blok, $h, $konteks, kecuali: ['drift_standar']);
            $this->assertGreaterThan(self::nilai($blok['komponen']['drift_standar']['ui']), (float) $app['drift_standar']['u'], "{$konteks}: ui drift aplikasi > Excel");
            $this->assertGreaterThan(self::nilai($blok['agregat']['u_hitung']), $h['ketidakpastian_diperluas'], "{$konteks}: U sebelum lantai aplikasi > Excel");
            $this->assertGreaterThanOrEqual(self::nilai($blok['agregat']['u_cetak']), $h['u95_sertifikat'], "{$konteks}: U95 sertifikat aplikasi >= Excel");

            $catatan = self::catatan($h);
            $this->assertArrayHasKey('putaran_drift_standar_kolom_k_tidak_lengkap', $catatan, "{$konteks}: selisih drift tercatat di jejak audit");
            $this->assertSamaExcel($blok['komponen']['drift_standar']['ui'], $catatan['putaran_drift_standar_kolom_k_tidak_lengkap']['u_baku_master'], "{$konteks}: ui drift yang dikutip catatan audit = sel Excel");

            // Hanya blok yang drift-nya satu-satunya selisih: catatan memulihkan U Excel tepat.
            if (! ($alat === 'centrifuge' && $i === 0)) {
                $this->assertUSamaExcel($blok['agregat']['u_hitung'], $catatan['putaran_drift_standar_kolom_k_tidak_lengkap']['u95_blok_master_terisolasi'], "{$konteks}: U dengan drift master = U Excel");
            }
        }
    }

    /**
     * SELISIH §3, efek ke angka CETAK: dengan tabel produksi (drift lengkap) U95 tercetak lebih besar dari Excel
     * di setiap blok yang U-nya di atas lantai CMC: 4 dari 6 blok Tachometer (1-4) dan 5 dari 5 blok Centrifuge.
     * Dua blok Tachometer teratas SAMA karena keduanya jatuh ke lantai CMC 5,0. Tes ini mengunci SEBARAN selisih
     * (blok mana, ke arah mana), bukan angka aplikasi; angka Excel diambil dari sel `SERTIFIKAT!S`.
     */
    #[DataProvider('alat')]
    public function test_selisih_drift_u95_tercetak_lebih_besar_dari_excel_di_atas_lantai_cmc(string $alat): void
    {
        $w = self::w($alat);
        $beda = [];

        foreach ($w['blok'] as $i => $blok) {
            $h = $this->hitungBlok($alat, $i, versiMaster: false);
            $cetakApp = self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'], '0.0'));

            if ($cetakApp !== $blok['cetak_u95']['teks']) {
                $beda[] = $i + 1;
                $this->assertGreaterThan((float) $blok['cetak_u95']['teks'], (float) $cetakApp, "{$alat} blok ".($i + 1).": U95 tercetak aplikasi > Excel ({$blok['cetak_u95']['sel']})");
            } else {
                $this->assertSamaExcel($blok['agregat']['cmc'], $h['u95_sertifikat'], "{$alat} blok ".($i + 1).': cetak sama hanya karena lantai CMC');
            }
        }

        $this->assertSame($alat === 'tachometer' ? [1, 2, 3, 4] : [1, 2, 3, 4, 5], $beda, 'blok yang U95 tercetaknya beda dari Excel');
    }

    /**
     * SELISIH §2 — Tachometer blok 5 (baris 98-109, set point 10000/12000/14000 rpm) rusak di workbook:
     *  - pita sertifikat kalibrator `='SERTIFIKAT KALIBRATOR'!F15` (1,6; pita 5000 rpm), sedangkan nominal tertinggi
     *    blok (15000 rpm) bernaung di F18 (3,1) seperti lima blok tetangga;
     *  - drift `='[1]Drift Std Kalibrator'!K54` tautan luar ke workbook lain yang kosong, ter-cache 0;
     *  - pengulangan `=MAX(PERHITUNGAN!G113:L113)` baris kosong, 0.
     * Aplikasi menghitung benar; ketiganya tercatat di jejak audit dengan nilai master yang SAMA dengan sel Excel,
     * dan "semua disamakan dengan master" memulihkan U Excel (1,6949). U yang dicetak sama (5,0): lantai CMC 5,0.
     * Komponen resolusi alat tidak rusak dan SAMA. Daya baca standar (B4-2) dikunci tes tersendiri di bawah.
     */
    public function test_selisih_tachometer_blok5_tiga_kerusakan_salin_tempel(): void
    {
        $w = self::w('tachometer');
        $blok = $w['blok'][4];
        $h = $this->hitungBlok('tachometer', 4, versiMaster: true);
        $app = self::komponen($h);
        $catatan = self::catatan($h);
        $tabel = collect($w['sertifikat_kalibrator'])->keyBy(static fn (array $b): string => (string) (int) $b['nominal']['nilai']);

        $this->assertTrue($blok['rusak']);
        $this->assertSame("='SERTIFIKAT KALIBRATOR'!F15", $blok['rumus_komponen']['ketidakpastian_standar']['rumus']);
        $this->assertSame("='[1]Drift Std Kalibrator'!K54", $blok['rumus_komponen']['drift_standar']['rumus']);
        $this->assertSame('=MAX(PERHITUNGAN!G113:L113)', $blok['rumus_komponen']['pengulangan_pembacaan']['rumus']);

        $this->assertKomponenSamaExcel($blok, $h, 'tachometer blok 5', kecuali: ['ketidakpastian_standar', 'resolusi_standar', 'drift_standar', 'pengulangan_pembacaan']);

        // Pita: Excel F15 (5000 rpm) lawan F18 (15000 rpm) yang dipakai aplikasi, keduanya sel workbook.
        $this->assertSamaExcel($blok['komponen']['ketidakpastian_standar']['u_mentah'], self::nilai($tabel['5000']['u95']), 'Excel memakai U95 pita 5000 rpm (F15)');
        $this->assertSamaExcel($tabel['15000']['u95'], (float) $app['ketidakpastian_standar']['u'] * 2, 'aplikasi memakai U95 pita 15000 rpm (F18)');
        $this->assertSame(60.0, (float) $app['ketidakpastian_standar']['vi'], 'derajat bebas pita sama');

        // Drift dan pengulangan: Excel 0, aplikasi bukan nol.
        $this->assertSame(0.0, self::nilai($blok['komponen']['drift_standar']['ui']));
        $this->assertSame(0.0, self::nilai($blok['komponen']['pengulangan_pembacaan']['ui']));
        $this->assertGreaterThan(0.0, (float) $app['drift_standar']['u']);
        $this->assertGreaterThan(0.0, (float) $app['pengulangan_pembacaan']['u']);

        foreach ([
            'putaran_pita_sertifikat_blok5_f15' => 'ketidakpastian_standar',
            'putaran_drift_blok5_tautan_luar_k54' => 'drift_standar',
            'putaran_pengulangan_blok5_baris_kosong' => 'pengulangan_pembacaan',
        ] as $kode => $nama) {
            $this->assertArrayHasKey($kode, $catatan, "jejak audit memuat {$kode}");
            $this->assertSame($nama, $catatan[$kode]['komponen']);
            $this->assertSamaExcel($blok['komponen'][$nama]['ui'], $catatan[$kode]['u_baku_master'], "{$kode}: ui master yang dikutip = sel Excel");
        }

        $ringkasan = $catatan['putaran_ringkasan_penyimpangan_master'];
        $this->assertUSamaExcel($blok['agregat']['u_hitung'], $ringkasan['u95_blok_master_semua'], 'semua penyimpangan disamakan dengan master memulihkan U Excel');
        $this->assertGreaterThan(self::nilai($blok['agregat']['u_hitung']) * 2, $h['ketidakpastian_diperluas'], 'U aplikasi sebelum lantai > 2x U Excel');
        $this->assertSamaExcel($blok['agregat']['u_cetak'], $h['u95_sertifikat'], 'U sesudah lantai CMC SAMA: lantai 5,0 menang di kedua sisi');
        $this->assertSame($blok['cetak_u95']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'], '0.0')), 'U95 tercetak sama');
    }

    /**
     * SELISIH B4-2 — daya baca tachometer standar blok 5. Excel: `PERHITUNGAN U95%!N99 = K94*0.5` dengan K94
     * DIKETIK 0,1 di bawah label pita `7000-10000`, padahal blok berisi titik sampai 14000 rpm. Blok 6 (label
     * `15000-30000`) mengetik 1. Aplikasi memakai 1 untuk nominal di atas 10000 rpm (`resolusiStandar`),
     * jadi ui resolusi standar blok 5 sepuluh kali lebih besar. Belum ada jawaban lab untuk blok ini; aplikasi
     * sudah mencatatnya di jejak audit (`putaran_resolusi_standar_blok5_ambang`).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b4_2_daya_baca_standar_blok5_tachometer_diketik_0_1(): void
    {
        $w = self::w('tachometer');
        $blok = $w['blok'][4];
        $h = $this->hitungBlok('tachometer', 4, versiMaster: true);
        $app = self::komponen($h);
        $catatan = self::catatan($h);

        $this->assertSame('=K94*0.5', $blok['rumus_komponen']['resolusi_standar']['rumus']);
        $this->assertSame('7000-10000', $blok['resolusi_standar_masukan']['label_pita']['nilai']);
        $this->assertSame(0.1, self::nilai($blok['resolusi_standar_masukan']['masukan']));
        $this->assertSame('15000-30000', $w['blok'][5]['resolusi_standar_masukan']['label_pita']['nilai']);
        $this->assertSame(1.0, self::nilai($w['blok'][5]['resolusi_standar_masukan']['masukan']));

        $this->assertSame(1.0, (new PutaranCalculator)->resolusiStandar(14000.0), 'aplikasi: di atas 10000 rpm daya baca standar 1 rpm');
        $this->assertEqualsWithDelta(10.0, (float) $app['resolusi_standar']['u'] / self::nilai($blok['komponen']['resolusi_standar']['ui']), 1e-9, 'ui resolusi standar aplikasi 10x Excel');

        $this->assertArrayHasKey('putaran_resolusi_standar_blok5_ambang', $catatan);
        $this->assertSamaExcel($blok['komponen']['resolusi_standar']['ui'], $catatan['putaran_resolusi_standar_blok5_ambang']['u_baku_master'], 'ui master yang dikutip catatan audit = sel Excel');
    }

    /**
     * SELISIH §2 — Centrifuge blok 1: pengulangan `=PERHITUNGAN!G34`, SATU sel (titik 1), sedangkan sepuluh blok
     * lain memakai `MAX()` sebaris. Titik 3 blok itu punya simpangan baku terbesar (0,0894 lawan 0,0447 di G34),
     * jadi aplikasi (MAX sebaris) memberi ui pengulangan dua kali lebih besar. Dengan tabel versi master ini
     * satu-satunya selisih: U beda 0,0009 rpm dan k beda 5,5e-7, dan U95 tercetak sama (2,6).
     */
    public function test_selisih_centrifuge_blok1_pengulangan_satu_sel(): void
    {
        $w = self::w('centrifuge');
        $blok = $w['blok'][0];
        $h = $this->hitungBlok('centrifuge', 0, versiMaster: true);
        $app = self::komponen($h);
        $catatan = self::catatan($h);

        $this->assertTrue($blok['rusak']);
        $this->assertSame('=PERHITUNGAN!G34', $blok['pengulangan_rujukan']['rumus']);
        $this->assertSame('satu_sel', $blok['pengulangan_rujukan']['bentuk']);

        $this->assertKomponenSamaExcel($blok, $h, 'centrifuge blok 1', kecuali: ['pengulangan_pembacaan']);
        $this->assertSamaExcel($blok['komponen']['pengulangan_pembacaan']['ui'], 0.02, 'Excel: ui pengulangan = G34 (0,0447)/sqrt(5)/... = 0,02', rel: 1e-9);
        $this->assertEqualsWithDelta(2.0, (float) $app['pengulangan_pembacaan']['u'] / self::nilai($blok['komponen']['pengulangan_pembacaan']['ui']), 1e-9, 'aplikasi: ui pengulangan 2x Excel');

        $simpangan = array_map(static fn (array $t): float => self::nilai($t['excel']['standar_deviasi']), self::titikBlok($w, $blok));
        $this->assertSamaExcel($w['titik'][0]['excel']['standar_deviasi'], $simpangan[0], 'G34 = titik 1');
        $this->assertSame(max($simpangan), $simpangan[2], 'simpangan baku terbesar blok ada di titik 3 (K34), di luar G34');

        $this->assertArrayHasKey('putaran_pengulangan_blok1_sel_tunggal_g34', $catatan);
        $this->assertSamaExcel($blok['komponen']['pengulangan_pembacaan']['ui'], $catatan['putaran_pengulangan_blok1_sel_tunggal_g34']['u_baku_master'], 'ui master yang dikutip catatan audit = sel Excel', rel: 1e-9);
        $this->assertUSamaExcel($blok['agregat']['u_hitung'], $catatan['putaran_ringkasan_penyimpangan_master']['u95_blok_master_semua'], 'U dengan pengulangan master = U Excel');

        $this->assertGreaterThan(self::nilai($blok['agregat']['u_hitung']), $h['ketidakpastian_diperluas'], 'U aplikasi sedikit lebih besar');
        $this->assertLessThan(1e-3, $h['ketidakpastian_diperluas'] - self::nilai($blok['agregat']['u_hitung']), 'selisih U kurang dari 0,001 rpm');
        $this->assertEqualsWithDelta(self::nilai($blok['agregat']['k']), $h['faktor_cakupan_k'], 1e-6, 'selisih k kecil');
        $this->assertSame($blok['cetak_u95']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($h['u95_sertifikat'], '0.0')), 'U95 tercetak sama');
        $this->assertSame($w['cetak_k']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($h['faktor_cakupan_k'], '0')), 'k tercetak sama');
    }

    /**
     * SELISIH B4-3 (alamat, tanpa selisih angka di sampel) — Tachometer blok 6: pengulangan
     * `=MAX(PERHITUNGAN!G94:L94)` menunjuk baris simpangan baku BLOK 5 (titik 13-15); baris milik blok 6
     * adalah 109. Sampel mengisi pembacaan kedua blok dengan pola yang sama (simpangan baku 0,2683 / 0 / 0),
     * jadi angkanya kebetulan sama dengan aplikasi, yang memakai titik blok sendiri. Begitu data blok 6
     * berbeda, Excel membaca blok yang salah dan aplikasi tidak.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b4_3_pengulangan_blok6_tachometer_menunjuk_baris_blok5(): void
    {
        $w = self::w('tachometer');
        $blok = $w['blok'][5];
        $blok5 = $w['blok'][4];

        $rujukan = $blok['pengulangan_rujukan'];
        $this->assertSame('=MAX(PERHITUNGAN!G94:L94)', $rujukan['rumus']);
        $this->assertFalse($rujukan['ke_baris_sendiri']);
        $this->assertSame(94, $rujukan['baris']);
        $this->assertSame(109, $rujukan['baris_stdev_blok_sendiri']);
        $this->assertSame(94, $blok5['pengulangan_rujukan']['baris_stdev_blok_sendiri'], 'baris 94 = simpangan baku blok 5');

        $stdev = static fn (array $b): array => array_map(static fn (array $t): float => self::nilai($t['excel']['standar_deviasi']), self::titikBlok($w, $b));
        $this->assertSamaExcel($w['titik'][12]['excel']['standar_deviasi'], $stdev($blok5)[0], 'G94 = titik 13');
        $this->assertEqualsWithDelta(max($stdev($blok5)), max($stdev($blok)), 1e-12, 'sampel: simpangan baku terbesar blok 5 dan blok 6 sama');

        $h = $this->hitungBlok('tachometer', 5, versiMaster: true);
        $app = self::komponen($h);
        $this->assertSamaExcel($blok['komponen']['pengulangan_pembacaan']['ui'], (float) $app['pengulangan_pembacaan']['u'], 'sampel: ui pengulangan blok 6 SAMA walau alamat Excel salah');
    }

    // ------------------------------------------------------------------ nol bertanda minus

    /**
     * Excel mencetak koreksi yang membulat ke nol sebagai `-0.0` (format sel `0.0`, nilai -0,04 di Tachometer
     * titik 7, -0,03 di Centrifuge titik 2). Aplikasi mencetak `0.0` tanpa minus: keputusan G0-b P4 (10 Nov 2026)
     * karena PDF master tidak memuat `-0.0`. Nilai antaranya tidak berubah, hanya cetaknya; jumlah titik
     * yang terkena dikunci supaya sampel yang bertambah ketahuan.
     */
    #[DataProvider('alat')]
    public function test_nol_bertanda_minus_excel_dicetak_tanpa_minus_oleh_aplikasi(string $alat): void
    {
        $w = self::w($alat);
        $profil = self::profil($alat);
        $this->assertFalse($profil->tandaNolDicetak());
        $terkena = [];

        foreach ($w['blok'] as $i => $blok) {
            $h = $this->hitungBlok($alat, $i, versiMaster: true);
            $app = [];
            foreach ($h['titik'] as $t) {
                $app[$t['titik_ke']] = $t;
            }

            foreach (self::titikBlok($w, $blok) as $t) {
                if (! $t['cetak']['koreksi']['nol_bertanda_minus']) {
                    continue;
                }

                $terkena[] = $t['titik_ke'];
                $this->assertSame('-0.0', $t['cetak']['koreksi']['teks'], "{$alat} titik {$t['titik_ke']}: Excel mencetak -0.0");
                $koreksiCetak = $profil->tandaKoreksiSertifikat() * (float) $app[$t['titik_ke']]['koreksi'];
                $this->assertSame('0.0', self::keTitik(TataLetakSertifikat::formatAngka($koreksiCetak, '0.0', $profil->tandaNolDicetak())), "{$alat} titik {$t['titik_ke']}: aplikasi mencetak 0.0");
                $this->assertSame('-0.0', self::keTitik(TataLetakSertifikat::formatAngka($koreksiCetak, '0.0', true)), "{$alat} titik {$t['titik_ke']}: dengan tanda nol dinyalakan hasilnya sama dengan Excel");
            }
        }

        $this->assertSame($alat === 'tachometer' ? [7] : [2], $terkena, 'titik bernol bertanda minus di sampel');
    }
}

/**
 * Tabel standar versi master: drift kalibrator hanya dari 5 baris berrumus di kolom K sheet
 * `Drift Std Kalibrator` (`K35 = MAX(K13:K34)/2` = 2,25 rpm). Tabel produksi memakai seluruh 15 baris (2,75 rpm).
 */
class TabelStandarPutaranVersiMasterParitas extends TabelStandarPutaran
{
    public function driftSetengahLebar(bool $lengkap = true): float
    {
        return parent::driftSetengahLebar(lengkap: false);
    }
}
