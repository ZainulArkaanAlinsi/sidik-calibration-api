<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermocoupleCalculator;
use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use App\Support\TataLetakSertifikat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Thermocouple: aplikasi lawan workbook master `Master Olah Data_Suhu_Thermocouple.xlsm`, tahap demi
 * tahap. Fixture `tests/Fixtures/paritas/thermocouple.json`, dibangun `docs/skrip/gen-fixture-paritas-batch7.py`.
 *
 * Titik panggil: `ThermocoupleCalculator::hitungSesi()` (rantai per titik + budget + agregasi) dan
 * `ThermocoupleProfile::hitungPerGrup()` (jalur yang sama dengan simpan dan hitung ulang, plus lantai CMC dari pita
 * `CalibrationCapability`).
 *
 * Master ini menghitung SATU budget per sesi (index tabel maksimum `P37`, set point maksimum `D56`), jadi komponen
 * budget dibandingkan sekali per sesi, bukan per titik. Sembilan komponen semuanya Type B; master menghitung STDEV
 * (`PERHITUNGAN FC!K/M`) tetapi tidak ada rumus `PERHITUNGAN U95%` yang merujuknya.
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-batch7.md`, tanpa mengubah `app/`):
 *  B7-9  faktor cakupan: master polinomial `AC32`, aplikasi kuantil t eksak (selisih relatif 1,2e-6);
 *  B7-10 HIPOTETIS: keterulangan: master tidak memasukkannya ke budget, aplikasi menambah komponen ke-10 saat STDEV > 0
 *        (keputusan lab 16 Sep 2026); workbook contoh ber-STDEV nol, jadi dibuktikan lewat skenario berlabel
 *        HIPOTETIS (bukan sel Excel, tidak dihitung SAMA dari Excel);
 *  B7-11 HIPOTETIS: pita CMC: rantai `IF` master `AC34` punya celah (150..151 dan 400..401 °C, teks "cek kapasitas"),
 *        aplikasi memakai pita lampiran akreditasi yang bersambung; set point di celah hanya hipotetis, tidak ada
 *        di sesi contoh.
 */
#[Group('paritas')]
class ParitasThermocoupleTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Urutan baris 20..28 `PERHITUNGAN U95%`, sama dengan urutan `budget` aplikasi dan kunci fixture. */
    private const KOMPONEN = [
        'ketidakpastian_standar',
        'ketidakpastian_sensor',
        'stabilitas_cold_junction',
        'drift_standar',
        'drift_sensor',
        'ac_pick_up',
        'variasi_aksial',
        'variasi_antar_lubang',
        'daya_baca_indikator',
    ];

    /** Ejaan tipe sensor di kalkulator; master menulis `Thermocouple Type K` (`INPUT DATA!X13`). */
    private const TIPE_SENSOR = 'Type K';

    /** Rumus `PERHITUNGAN U95%!AC32` apa adanya; koefisiennya diulang di [kPolinomial]. */
    private const RUMUS_K_MASTER = '=1.95996+2.37356/AC31+2.818745/AC31^2+2.546662/AC31^3+1.761829/AC31^4+0.245458/AC31^5+1.000764/AC31^6';

    /** Rumus `PERHITUNGAN U95%!AC34` apa adanya: tiga pita dan teks "cek kapasitas" di luarnya. */
    private const RUMUS_CMC_MASTER = '=IF(AND(U18>=-20,U18<=150),DATABASE!S5,IF(AND(U18>151,U18<=400),DATABASE!S6,IF(AND(U18>401,U18<=600),DATABASE!S7,"cek kapasitas")))';

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('thermocouple')['workbook'];
    }

    /** @return list<array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>, no_probe: int}> */
    private static function masukanTitik(): array
    {
        return array_map(static fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => self::nilai($t['set_point']),
            'standar' => array_map('floatval', $t['standar']['nilai']),
            'uut' => array_map('floatval', $t['uut']['nilai']),
            'no_probe' => (int) self::nilai($t['no_probe']),
        ], self::w()['masukan']['titik']);
    }

    /** @return array<string, mixed> */
    private static function spekKalkulator(): array
    {
        $s = self::w()['masukan']['spek'];

        return [
            // Aplikasi menyimpan merk huruf kecil (`TabelKalibratorSuhu3Alat::MERK`); master menulis `Yokogawa`.
            'merk_kalibrator' => strtolower($s['merk_kalibrator']['nilai']),
            'tipe_sensor' => self::TIPE_SENSOR,
            'dryblock' => $s['dryblock']['nilai'],
            'resolusi' => self::nilai($s['resolusi_alat']),
            // `AC34` untuk set point maksimum 150 °C = pita pertama (`DATABASE!S5`), sama dengan `agregat.cmc`.
            'cmc' => self::nilai(self::w()['agregat']['cmc']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $titik  masukan pengganti (skenario berlabel); bawaan = masukan master
     * @return array<string, mixed>
     */
    private function hitung(?array $titik = null, int $jumlahBudget = 9): array
    {
        $hasil = (new ThermocoupleCalculator)->hitungSesi($titik ?? self::masukanTitik(), self::spekKalkulator());

        $this->assertSame([], $hasil['belum_dihitung'], 'titik belum dihitung');
        $this->assertCount(3, $hasil['titik'], 'jumlah titik');
        $this->assertCount($jumlahBudget, $hasil['budget'], 'jumlah komponen budget');

        return $hasil;
    }

    /**
     * Rantai master `PERHITUNGAN U95%!AC30:AC31` direkonstruksi di tes dari kolom ui dan vi:
     * uc = SQRT(SUM(AC20:AC28)), v_eff = uc^4 / SUM(AG20:AG28). Dipakai hanya untuk membuktikan penyebab selisih
     * (skenario berlabel), bukan sebagai acuan baru; kebenarannya dibuktikan lebih dulu pada ui master.
     *
     * @param  list<float>  $ui
     * @param  list<float>  $vi
     * @return array{uc: float, veff: float}
     */
    private static function rantaiMaster(array $ui, array $vi): array
    {
        $jumlahKuadrat = 0.0;
        $penyebut = 0.0;

        foreach ($ui as $i => $u) {
            $jumlahKuadrat += $u ** 2;
            $penyebut += ($u ** 2) ** 2 / $vi[$i];
        }

        return ['uc' => sqrt($jumlahKuadrat), 'veff' => $jumlahKuadrat ** 2 / $penyebut];
    }

    /** `PERHITUNGAN U95%!AC32`: aproksimasi polinomial master untuk t(0,975; v_eff). */
    private static function kPolinomial(float $v): float
    {
        return 1.95996 + 2.37356 / $v + 2.818745 / $v ** 2 + 2.546662 / $v ** 3 + 1.761829 / $v ** 4 + 0.245458 / $v ** 5 + 1.000764 / $v ** 6;
    }

    /** STDEV sampel (`STDEV()` Excel, pembagi n-1), untuk skenario berlabel. */
    private static function stdevSampel(array $nilai): float
    {
        $n = count($nilai);
        $rata = array_sum($nilai) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $nilai)) / ($n - 1));
    }

    /**
     * Daftar ui master (kolom U baris 20..28) dan vi (kolom S).
     *
     * @return array{ui: list<float>, vi: list<float>}
     */
    private static function uiMaster(): array
    {
        $ui = [];
        $vi = [];

        foreach (self::KOMPONEN as $kunci) {
            $b = self::w()['budget'][$kunci];
            $ui[] = self::nilai($b['ui']);
            $vi[] = self::nilai($b['vi']);
        }

        return ['ui' => $ui, 'vi' => $vi];
    }

    // ------------------------------------------------------------------ masukan dan konstanta

    public function test_masukan_master_cocok_dengan_konstanta_dan_pembacaan_aplikasi(): void
    {
        $m = self::w()['masukan'];
        $s = $m['spek'];

        $this->assertSame('Yokogawa', $s['merk_kalibrator']['nilai'], 'sel '.$s['merk_kalibrator']['sel']);
        $this->assertContains(strtolower($s['merk_kalibrator']['nilai']), TabelKalibratorSuhu3Alat::MERK, 'merk kalibrator dikenal aplikasi');
        // Master memilih merk meter UUT di sel terpisah (`X21`); aplikasi memakai satu `merk` untuk keduanya.
        // Di workbook contoh keduanya sama, jadi kasus ini TIDAK membuktikan tabel UUT beda merk (MENUNGGU).
        $this->assertSame($s['merk_kalibrator']['nilai'], $s['merk_kalibrator_uut']['nilai'], 'sel '.$s['merk_kalibrator_uut']['sel']);
        $this->assertSame('Thermocouple '.self::TIPE_SENSOR, $s['sensor_standar']['nilai'], 'sel '.$s['sensor_standar']['sel']);
        $this->assertContains(self::TIPE_SENSOR, TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR, 'tipe sensor dikenal aplikasi');
        $this->assertContains($s['dryblock']['nilai'], array_column(ThermocoupleProfile::DRYBLOCK, 'nilai'), 'dryblock dikenal aplikasi, sel '.$s['dryblock']['sel']);
        $this->assertCount(3, $m['titik'], 'master contoh punya tiga titik');

        foreach ($m['titik'] as $i => $t) {
            $ke = 'titik '.($i + 1);

            $this->assertSame(self::nilai($t['set_point']), self::nilai($t['nominal']), "{$ke}: set point standar {$t['set_point']['sel']} = nominal UUT {$t['nominal']['sel']}");
            $this->assertContains(self::nilai($t['set_point']), ThermocoupleProfile::TITIK_SARAN, "{$ke}: set point termasuk titik saran aplikasi");
            $this->assertSame((float) ($i + 1), self::nilai($t['no_probe']), "{$ke}: no. probe {$t['no_probe']['sel']}");
            $this->assertCount(ThermocoupleCalculator::N_PENGULANGAN, $t['standar']['nilai'], "{$ke}: jumlah pembacaan standar");
            $this->assertCount(ThermocoupleCalculator::N_PENGULANGAN, $t['uut']['nilai'], "{$ke}: jumlah pembacaan UUT");
        }
    }

    // ------------------------------------------------------------------ rantai per titik

    public function test_rantai_per_titik_sama_dengan_sel_master(): void
    {
        $w = self::w();

        foreach ($this->hitung()['titik'] as $i => $t) {
            $x = $w['titik'][$i];
            $ke = 'titik '.($i + 1);

            $this->assertSame($x['titik_ke'], $t['titik_ke'], "{$ke}: nomor titik");
            $this->assertSamaExcel($x['standar_rata_rata'], (float) $t['rata_rata_standar'], "{$ke} rata-rata standar");
            $this->assertSamaExcel($x['standar_sd'], (float) $t['standar_deviasi_standar'], "{$ke} STDEV standar");
            $this->assertSamaExcel($x['indeks_tabel'], (float) $t['index_standar'], "{$ke} index tabel standar");
            $this->assertSamaExcel($x['koreksi_meter'], (float) $t['koreksi_meter_standar'], "{$ke} koreksi meter kalibrator");
            // Tabel koreksi probe aplikasi 5 angka penting; di tiga nomor probe ini nilainya pas (-0,08 dan 0,275),
            // jadi sama dengan sel R. Tabel penuhnya belum diadu (MENUNGGU, dokumen §6).
            $this->assertSamaExcel($x['koreksi_probe'], (float) $t['koreksi_probe_standar'], "{$ke} koreksi probe");
            $this->assertSamaExcel($x['standar_terkoreksi'], (float) $t['standar_terkoreksi'], "{$ke} standar terkoreksi");
            $this->assertSamaExcel($x['uut_rata_rata'], (float) $t['rata_rata_uut'], "{$ke} rata-rata UUT");
            $this->assertSamaExcel($x['uut_sd'], (float) $t['standar_deviasi_uut'], "{$ke} STDEV UUT");
            $this->assertSamaExcel($x['uut_indeks_tabel'], (float) $t['index_uut'], "{$ke} index tabel UUT");
            $this->assertSamaExcel($x['uut_koreksi_meter'], (float) $t['koreksi_meter_uut'], "{$ke} koreksi meter UUT");
            $this->assertSamaExcel($x['uut_terkoreksi'], (float) $t['uut_terkoreksi'], "{$ke} UUT terkoreksi");
            $this->assertSamaExcel($x['koreksi'], (float) $t['koreksi'], "{$ke} koreksi");
        }
    }

    public function test_besaran_sesi_sama_dengan_sel_master(): void
    {
        $w = self::w()['sesi'];
        $h = $this->hitung();

        // Master memisahkan STDEV standar (`M23`) dan UUT (`M42`); aplikasi memakai satu angka, maksimum keduanya.
        // Keduanya nol di workbook ini, jadi sama. Perbedaan maknanya dikunci di B7-10.
        $this->assertSamaExcel($w['sd_maks_standar'], (float) $h['standar_deviasi_maks'], 'STDEV maksimum sesi vs standar');
        $this->assertSamaExcel($w['sd_maks_uut'], (float) $h['standar_deviasi_maks'], 'STDEV maksimum sesi vs UUT');
        $this->assertSamaExcel($w['indeks_maks'], (float) $h['index_maks'], 'index tabel maksimum');

        $setPointMaks = max(array_map(static fn (array $t): float => self::nilai($t['set_point']), self::w()['masukan']['titik']));
        $this->assertSamaExcel($w['titik_maks_uut'], $setPointMaks, 'set point maksimum yang memilih pita CMC');
        $this->assertSamaExcel($w['titik_maks_u95'], $setPointMaks, 'set point maksimum di lembar U95');
    }

    // ------------------------------------------------------------------ budget

    public function test_sembilan_komponen_budget_sama_dengan_master(): void
    {
        $budget = self::w()['budget'];

        $this->assertSame(self::KOMPONEN, array_keys($budget), 'urutan 9 komponen fixture = baris 20..28 master');

        $app = $this->hitung()['budget'];

        $this->assertSame(self::KOMPONEN, array_column($app, 'sumber'), 'urutan dan nama komponen aplikasi');

        foreach (self::KOMPONEN as $i => $kunci) {
            $x = $budget[$kunci];
            $k = $app[$i];

            $this->assertTrue($k['disertakan'], "{$kunci}: disertakan");
            $this->assertSamaExcel($x['vi'], (float) $k['vi'], "{$kunci}: vi");
            $this->assertSamaExcel($x['ci'], (float) $k['ci'], "{$kunci}: ci");
            $this->assertSamaExcel($x['ui'], (float) $k['u'], "{$kunci}: ui");
        }
    }

    public function test_tanpa_keterulangan_terukur_budget_aplikasi_sama_dengan_budget_master(): void
    {
        $w = self::w();
        $h = $this->hitung();

        // Master: tidak ada rumus `PERHITUNGAN U95%` yang membaca sel STDEV (`PERHITUNGAN FC!K/L/M`); hanya `P37`
        // (index tabel) dan `D56` (set point maksimum). Dibaca generator dengan menyisir semua rumus lembar itu.
        $this->assertSame(['D56', 'P37'], $w['rujukan_u95_ke_fc'], 'sel PERHITUNGAN FC yang dibaca PERHITUNGAN U95%');
        $this->assertNotContains('pengulangan', array_column($h['budget'], 'sumber'), 'STDEV nol: tidak ada komponen keterulangan');
        $this->assertNotContains('type_a_masuk_budget', array_column($h['catatan_audit'], 'kode'), 'STDEV nol: catatan Type A tidak muncul');
    }

    public function test_agregasi_gum_dengan_ui_master_sama_dengan_uc_dan_veff_master(): void
    {
        $a = self::w()['agregat'];
        $m = self::uiMaster();

        // Rekonstruksi di tes dulu: bila ini tidak cocok dengan sel master, skenario di bawah tidak sah.
        $rantai = self::rantaiMaster($m['ui'], $m['vi']);
        $this->assertSamaExcel($a['uc'], $rantai['uc'], 'rekonstruksi uc dari ui master');
        $this->assertSamaExcel($a['veff'], $rantai['veff'], 'rekonstruksi v_eff dari ui master');

        // Agregator aplikasi (`GumCalculator::agregasiBudget`, dipakai kalkulator) diberi ui master persis.
        $komponen = [];

        foreach (self::KOMPONEN as $kunci) {
            $b = self::w()['budget'][$kunci];
            $komponen[] = ['u' => self::nilai($b['ui']), 'ci' => self::nilai($b['ci']), 'vi' => self::nilai($b['vi'])];
        }

        $agg = (new GumCalculator)->agregasiBudget($komponen);

        $this->assertSamaExcel($a['uc'], (float) $agg['ketidakpastian_gabungan'], 'uc agregator aplikasi dengan ui master');
        $this->assertSamaExcel($a['veff'], (float) $agg['derajat_kebebasan_efektif'], 'v_eff agregator aplikasi dengan ui master');
    }

    public function test_uc_veff_dan_u95_sertifikat_kalkulator_sama_dengan_master(): void
    {
        $a = self::w()['agregat'];
        $h = $this->hitung();

        $this->assertSamaExcel($a['uc'], (float) $h['ketidakpastian_gabungan'], 'uc kalkulator');
        $this->assertSamaExcel($a['veff'], (float) $h['derajat_kebebasan_efektif'], 'v_eff kalkulator');
        $this->assertSamaExcel($a['cmc'], (float) $h['cmc'], 'CMC dipakai');
        // `AC35 = MAX(AC33:AI34)`: CMC 0,84 di atas U hitung 0,7686, jadi lantai CMC yang dilaporkan.
        $this->assertSamaExcel($a['u_dilaporkan'], (float) $h['u95_sertifikat'], 'U95 dilaporkan (lantai CMC)');
        $this->assertSame('cmc', $h['sumber_u95'], 'U95 dari lantai CMC');
        $this->assertGreaterThan((float) $h['ketidakpastian_diperluas'], (float) $h['u95_sertifikat'], 'U hitung di bawah lantai');
    }

    // ------------------------------------------------------------------ angka cetak

    /**
     * Cetak satu sel tabel hasil lewat `TataLetakSertifikat::teksSel`, dengan desimal yang dibaca dari profil.
     * Paket suhu 3 alat tidak punya peta `format_sertifikat`, jadi semua kolom mengikuti `desimalSertifikat()` dan
     * `desimalU95()`. Ini bukan render PDF penuh; titik desimal bentuk master lewat `keTitik`.
     */
    private function cetak(string $kolom, float $nilai): string
    {
        $profil = new ThermocoupleProfile;
        $kunci = ['standar' => 'standard_value', 'uut' => 'unit_under_test', 'koreksi' => 'correction', 'u95' => 'u95'][$kolom];
        $baris = [
            $kunci => $nilai,
            'desimal' => $profil->desimalSertifikat(),
            'desimal_u95' => $profil->desimalU95(),
            'tanda_nol' => $profil->tandaNolDicetak(),
        ];

        return self::keTitik(TataLetakSertifikat::teksSel($baris, $kolom, [], (int) $profil->desimalSertifikat()));
    }

    private function cetakK(float $k): string
    {
        return self::keTitik(TataLetakSertifikat::teksK(['desimal_k' => (new ThermocoupleProfile)->desimalFaktorCakupan()], $k));
    }

    public function test_cetak_standar_uut_dan_koreksi_sama_dengan_teks_sertifikat(): void
    {
        foreach ($this->hitung()['titik'] as $i => $t) {
            $s = self::w()['titik'][$i]['sertifikat'];
            $ke = 'titik '.($i + 1);

            $this->assertSame('0.0', $s['uut']['format'], "{$ke}: format master {$s['uut']['sel']}");
            $this->assertSame($s['standar']['teks'], $this->cetak('standar', (float) $t['standar_terkoreksi']), "{$ke}: standar vs {$s['standar']['sel']}");
            $this->assertSame($s['uut']['teks'], $this->cetak('uut', (float) $t['uut_terkoreksi']), "{$ke}: UUT vs {$s['uut']['sel']}");
            $this->assertSame($s['koreksi']['teks'], $this->cetak('koreksi', (float) $t['koreksi']), "{$ke}: koreksi vs {$s['koreksi']['sel']}");
        }
    }

    public function test_cetak_u95_dan_k_sama_dengan_teks_sertifikat_walau_nilai_antara_beda(): void
    {
        $s = self::w()['sertifikat'];
        $h = $this->hitung();

        $this->assertSame($s['u95']['teks'], $this->cetak('u95', (float) $h['u95_sertifikat']), "U95 vs {$s['u95']['sel']}");
        // k master 1,97208826 dan k aplikasi 1,97208580 berbeda (B7-9), tetapi keduanya tercetak "2".
        $this->assertSame($s['k']['teks'], $this->cetakK((float) $h['faktor_cakupan_k']), "k vs {$s['k']['sel']}");
    }

    // ------------------------------------------------------------------ jalur profil (simpan / hitung ulang)

    /**
     * Kalibrator + alat + pita CMC dari lampiran akreditasi yang SAMA dengan yang dibaca seeder produksi
     * (`database/data/kemampuan-kalibrasi.json`), bukan angka yang diketik ulang di tes.
     *
     * @return array{0: Equipment, 1: Standard}
     */
    private function alatDanKalibrator(): array
    {
        $organisasi = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => $organisasi->id,
            'kode' => 'suhu',
            'nama' => 'Suhu',
        ]);

        $lampiran = json_decode((string) file_get_contents(database_path('data/kemampuan-kalibrasi.json')), true, flags: JSON_THROW_ON_ERROR);
        $pita = 0;

        foreach ($lampiran['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                if ($alat['nama_alat'] !== 'Thermocouple') {
                    continue;
                }

                foreach ($alat['rentang'] as $r) {
                    CalibrationCapability::factory()->create([
                        'organization_id' => $organisasi->id,
                        'equipment_category_id' => $kategori->id,
                        'nama_alat' => 'Thermocouple',
                        'range_min' => $r['min'],
                        'range_max' => $r['max'],
                        'satuan' => $r['satuan'],
                        'ketidakpastian_terbaik' => $r['ketidakpastian'],
                        'satuan_ketidakpastian' => $r['satuan_u'],
                        'faktor_cakupan' => 2,
                        'metode' => $alat['metode'],
                    ]);
                    $pita++;
                }
            }
        }

        $this->assertSame(3, $pita, 'Lampiran akreditasi Thermocouple harus punya tiga pita CMC.');

        $alat = Equipment::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Thermocouple Uji Paritas',
            'nama_alat_kemampuan' => 'Thermocouple',
            'satuan' => '°C',
            'range_min' => -20,
            'range_max' => 600,
            'resolusi' => self::nilai(self::w()['masukan']['spek']['resolusi_alat']),
            'toleransi' => null,
        ]);

        $kalibrator = Standard::factory()->create([
            'organization_id' => $organisasi->id,
            'nama' => 'Kalibrator Suhu Uji Paritas',
            // Kolom `merk` yang MENENTUKAN tabel koreksi mana yang dibaca profil.
            'merk' => self::w()['masukan']['spek']['merk_kalibrator']['nilai'],
            'serial_number' => 'PARITAS-TC-01',
            'parameter_kondisi' => null,
            'berlaku_sampai' => now()->addYear(),
        ]);

        return [$alat, $kalibrator];
    }

    /**
     * @param  list<array<string, mixed>>|null  $masukan  masukan titik pengganti (skenario berlabel); bawaan = master
     * @return list<array<string, mixed>> keluaran `hitungan` profil, urut titik.
     */
    private function hitungProfil(?array $masukan = null): array
    {
        [$alat, $kalibrator] = $this->alatDanKalibrator();
        $spek = self::w()['masukan']['spek'];

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => $t['titik_ukur'],
            'pembacaan' => [],
            'standard' => $kalibrator,
            'konteks' => [
                // Ejaan master ("Thermocouple Type K") diterima profil dan dinormalkan ke "Type K".
                'tipe_sensor' => $spek['sensor_standar']['nilai'],
                'alat_bantu' => $spek['dryblock']['nilai'],
                'standar' => $t['standar'],
                'uut' => $t['uut'],
                'no_probe' => $t['no_probe'],
            ],
        ], $masukan ?? self::masukanTitik());

        $hasil = (new ThermocoupleProfile)->hitungPerGrup($titik, $alat);

        $this->assertNotNull($hasil, 'profil tidak menghitung');
        $this->assertSame([], $hasil['belum_dihitung'], 'titik belum dihitung');
        $this->assertCount(3, $hasil['hitungan'], 'jumlah titik profil');

        return $hasil['hitungan'];
    }

    public function test_jalur_profil_menghasilkan_nilai_yang_sama_dengan_kalkulator_dan_sel_master(): void
    {
        $w = self::w();
        $kalkulator = $this->hitung();

        foreach ($this->hitungProfil() as $i => $h) {
            $x = $w['titik'][$i];
            $t = $kalkulator['titik'][$i];
            $ke = 'titik '.($i + 1);

            $this->assertSame($x['titik_ke'], $h['titik_ke'], "{$ke}: nomor titik");
            // `titik_ukur` baris hitungan = standar terkoreksi (kolom Standard Reading), `rata_rata` = UUT terkoreksi.
            $this->assertSamaExcel($x['standar_terkoreksi'], (float) $h['titik_ukur'], "{$ke} profil: standar terkoreksi");
            $this->assertSamaExcel($x['uut_terkoreksi'], (float) $h['rata_rata'], "{$ke} profil: UUT terkoreksi");
            $this->assertSamaExcel($x['koreksi'], (float) $h['koreksi'], "{$ke} profil: koreksi");
            $this->assertSamaExcel($x['uut_sd'], (float) $h['standar_deviasi'], "{$ke} profil: STDEV UUT");
            $this->assertSame(5, $h['jumlah_pengulangan'], "{$ke} profil: jumlah pengulangan");

            // Simpan, hitung ulang, dan pratinjau memakai fungsi yang sama: jalur profil = kalkulator persis.
            $this->assertSame((float) $t['koreksi'], (float) $h['koreksi'], "{$ke} profil: koreksi = kalkulator");
            $this->assertSame((float) $kalkulator['ketidakpastian_gabungan'], (float) $h['ketidakpastian_gabungan'], "{$ke} profil: uc = kalkulator");
            $this->assertSame((float) $kalkulator['faktor_cakupan_k'], (float) $h['faktor_cakupan_k'], "{$ke} profil: k = kalkulator");
            $this->assertSame((float) $kalkulator['derajat_kebebasan_efektif'], (float) $h['derajat_kebebasan_efektif'], "{$ke} profil: v_eff = kalkulator");
            $this->assertSame((float) $kalkulator['u95_sertifikat'], (float) $h['ketidakpastian_diperluas'], "{$ke} profil: U95 = kalkulator");
            $this->assertSamaExcel($w['agregat']['u_dilaporkan'], (float) $h['ketidakpastian_diperluas'], "{$ke} profil: U95 vs ".$w['agregat']['u_dilaporkan']['sel']);
        }
    }

    /**
     * Pita CMC: set point tertinggi 150 °C ada di dua pita (-20..150 dan 150..400); pita BAWAH menang, sama dengan
     * `AC34` master (`IF(AND(U18>=-20,U18<=150), DATABASE!S5, ...)`). U hitung 0,7686 di bawah CMC 0,84, jadi lantai
     * CMC aktif dan U95 sertifikat = CMC, sama dengan master yang `MAX(AC33:AI34)` jatuh ke `AC34`.
     */
    public function test_pita_cmc_bawah_menang_di_150_derajat_dan_lantai_aktif(): void
    {
        $spek = self::w()['masukan']['spek'];

        $hitungan = $this->hitungProfil();

        $pita = CalibrationCapability::query()->where('nama_alat', 'Thermocouple')->orderBy('range_max')->get();

        $this->assertCount(3, $pita);
        $this->assertEqualsWithDelta(self::nilai($spek['cmc_pita_minus20_150']), (float) $pita[0]->ketidakpastian_terbaik, 1e-15, 'pita -20..150 vs '.$spek['cmc_pita_minus20_150']['sel']);
        $this->assertEqualsWithDelta(self::nilai($spek['cmc_pita_150_400']), (float) $pita[1]->ketidakpastian_terbaik, 1e-15, 'pita 150..400 vs '.$spek['cmc_pita_150_400']['sel']);
        $this->assertEqualsWithDelta(self::nilai($spek['cmc_pita_400_600']), (float) $pita[2]->ketidakpastian_terbaik, 1e-15, 'pita 400..600 vs '.$spek['cmc_pita_400_600']['sel']);

        foreach ($hitungan as $i => $h) {
            $konteks = collect($h['type_b_components'])->firstWhere('sumber', 'konteks_sesi');

            $this->assertNotNull($konteks, 'titik '.($i + 1).': jejak konteks_sesi');
            $this->assertEqualsWithDelta(self::nilai(self::w()['agregat']['cmc']), (float) $konteks['cmc'], 1e-15, 'titik '.($i + 1).': CMC dipakai vs '.self::w()['agregat']['cmc']['sel']);
            $this->assertSame('cmc', $konteks['sumber_u95'], 'titik '.($i + 1).': U95 dari lantai CMC');
            $this->assertEqualsWithDelta(self::nilai(self::w()['agregat']['cmc']), (float) $h['ketidakpastian_diperluas'], 1e-15, 'titik '.($i + 1).': U95 = CMC');
        }
    }

    public function test_jejak_audit_profil_memuat_sembilan_komponen_dan_probe_dipakai_sama_dengan_master(): void
    {
        $baris = $this->hitungProfil();
        $u = (float) $baris[0]['ketidakpastian_diperluas'];

        foreach ($baris as $i => $h) {
            $komponen = array_slice($h['type_b_components'], 0, count(self::KOMPONEN));
            $sumber = array_column($h['type_b_components'], 'sumber');
            $probe = collect($h['type_b_components'])->firstWhere('sumber', 'probe_dipakai');

            $this->assertSame(self::KOMPONEN, array_column($komponen, 'sumber'), 'titik '.($i + 1).': sembilan komponen di jejak');
            $this->assertContains('v_eff_tidak_dipotong', $sumber, 'titik '.($i + 1).': catatan v_eff');
            $this->assertContains('lantai_cmc', $sumber, 'titik '.($i + 1).': catatan lantai CMC');
            $this->assertNotContains('pengulangan', $sumber, 'titik '.($i + 1).': STDEV nol, tanpa komponen keterulangan');
            $this->assertNotNull($probe, 'titik '.($i + 1).': jejak probe_dipakai');
            $this->assertSamaExcel(self::w()['titik'][$i]['koreksi_probe'], (float) $probe['nilai'], 'titik '.($i + 1).': koreksi probe di jejak');
            // Master menghitung SATU U95 per sesi (`SERTIFIKAT!L34` dari `AC35`), jadi semua titik bernilai sama.
            $this->assertSame($u, (float) $h['ketidakpastian_diperluas'], 'titik '.($i + 1).': U95 satu per sesi');
            $this->assertSame(0.0, (float) $h['type_a'], 'titik '.($i + 1).': tanpa sumbangan Type A');
        }

        $this->assertSame(self::w()['sertifikat']['u95']['teks'], $this->cetak('u95', $u), 'cetak U95 profil vs '.self::w()['sertifikat']['u95']['sel']);
    }

    // ------------------------------------------------------------------ selisih terkunci (menunggu keputusan TM)

    /**
     * B7-9: faktor cakupan. Master `AC32` = aproksimasi polinomial dari `AC31` (tanpa pemotongan). Aplikasi memakai
     * kuantil t(0,975; v_eff) eksak, juga tanpa pemotongan (`FLOOR_V_EFF = false`), jadi beda hanya algoritma: selisih
     * relatif 1,2e-6 pada v_eff master. U hitung ikut berbeda sebesar itu. Tidak mengubah angka cetak: k tercetak
     * `2` di keduanya dan U95 tercetak `0.84` karena lantai CMC menutup U hitung.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_9_k_master_polinomial_aplikasi_kuantil_eksak(): void
    {
        $w = self::w();
        $a = $w['agregat'];
        $skenario = $w['skenario']['k_kuantil_eksak_pada_veff_master'];
        $veffMaster = self::nilai($a['veff']);
        $t = new StudentTDistribution;

        // Master: AC32 memang polinomial dari AC31 (rumus dan nilainya).
        $this->assertSame(self::RUMUS_K_MASTER, $a['rumus_k'], 'rumus master '.$a['k']['sel']);
        $this->assertSamaExcel($a['k'], self::kPolinomial($veffMaster), 'polinomial di v_eff master = '.$a['k']['sel']);

        // Aplikasi pada v_eff yang SAMA dengan master = kuantil eksak (scipy t.ppf), bukan polinomial.
        $kEksak = $t->quantile(0.975, $veffMaster);
        $this->assertSamaHitungUlang($skenario['k'], $kEksak, 'SELISIH B7-9: kuantil aplikasi vs scipy pada v_eff master', rel: 0.0, mutlak: self::TOL_K);
        $this->assertGreaterThan(1e-7, abs($kEksak - self::nilai($a['k'])), "SELISIH B7-9: kuantil eksak harus berbeda dari {$a['k']['sel']}");
        $this->assertSamaHitungUlang($skenario['k'] - self::nilai($a['k']), $kEksak - self::nilai($a['k']), 'SELISIH B7-9: besar selisih k', rel: 0.0, mutlak: self::TOL_K);
        $this->assertSamaHitungUlang($skenario['selisih_relatif_terhadap_ac32'], abs($kEksak - self::nilai($a['k'])) / self::nilai($a['k']), 'SELISIH B7-9: selisih relatif k', rel: 1e-6);

        // Aplikasi sendiri: k = kuantil di v_eff-nya TANPA dipotong, dan polinomial pada v_eff itu berbeda.
        $h = $this->hitung();
        $veffApp = (float) $h['derajat_kebebasan_efektif'];

        $this->assertSame($t->quantile(0.975, $veffApp), (float) $h['faktor_cakupan_k'], 'k aplikasi = kuantil pada v_eff tanpa dipotong');
        $this->assertGreaterThan(1e-7, abs($t->quantile(0.975, floor($veffApp)) - (float) $h['faktor_cakupan_k']), 'pemotongan v_eff akan menggeser k; aplikasi tidak memotong');
        $this->assertGreaterThan(1e-7, abs(self::kPolinomial($veffApp) - (float) $h['faktor_cakupan_k']), 'SELISIH B7-9: k aplikasi harus berbeda dari polinomial master');
        $this->assertGreaterThan(1e-7, abs((float) $h['faktor_cakupan_k'] - self::nilai($a['k'])), "SELISIH B7-9: k aplikasi harus berbeda dari {$a['k']['sel']}");
    }

    #[Group('menunggu-keputusan-tm')]
    public function test_b7_9_dampak_hilir_u_hitung_sama_dengan_skenario_kuantil_eksak(): void
    {
        $w = self::w();
        $a = $w['agregat'];
        $skenario = $w['skenario']['k_kuantil_eksak_pada_veff_master'];
        $h = $this->hitung();

        // uc dan v_eff SAMA dengan master (test lain); satu-satunya beda di hilir adalah k, jadi U hitung = uc * k eksak.
        $this->assertSamaHitungUlang($skenario['u'], (float) $h['ketidakpastian_diperluas'], 'SELISIH B7-9: U hitung vs skenario kuantil eksak', rel: self::TOL_K_REL);
        $this->assertGreaterThan(1e-7, abs((float) $h['ketidakpastian_diperluas'] - self::nilai($a['u_hitung'])) / self::nilai($a['u_hitung']), "SELISIH B7-9: U hitung harus berbeda dari {$a['u_hitung']['sel']}");

        // Yang tercetak tidak bergeser: lantai CMC 0,84 di atas kedua U hitung, k tercetak 2 di keduanya.
        $this->assertSame($w['sertifikat']['u95']['teks'], $this->cetak('u95', (float) $h['u95_sertifikat']), 'U95 tercetak tetap sama');
        $this->assertSame($w['sertifikat']['k']['teks'], $this->cetakK((float) $h['faktor_cakupan_k']), 'k tercetak tetap sama');
    }

    /**
     * HIPOTETIS SELISIH B7-10: keterulangan. Master menghitung STDEV (`PERHITUNGAN FC!K`, `M23`, `M42`) tetapi tidak
     * memasukkannya ke budget: sembilan komponen `AC29` semuanya Type B. Aplikasi menambah komponen ke-10 saat STDEV > 0
     * (u = STDEV maksimum / akar 5, vi = 4; keputusan lab 16 Sep 2026). Workbook contoh ber-STDEV nol, jadi selisih ini
     * dibuktikan dengan masukan HIPOTETIS berlabel di fixture (`skenario.keterulangan_ditambahkan`), bukan sel Excel;
     * nilai skenario dihitung ulang, bukan nilai-cache sel, dan tidak dihitung SAMA dari Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_10_keterulangan_terukur_masuk_budget_aplikasi_sebagai_komponen_kesepuluh(): void
    {
        $w = self::w();
        $s = $w['skenario']['keterulangan_ditambahkan'];
        $titik = self::masukanTitik();
        $titik[0]['standar'] = array_map('floatval', $s['bacaan_standar_titik_1']);

        $h = $this->hitung($titik, 10);
        $peng = $h['budget'][9];

        $this->assertSame('pengulangan', $peng['sumber'], 'HIPOTETIS SELISIH B7-10: komponen ke-10 aplikasi');
        $this->assertSame('t-student', $peng['distribusi']);
        $this->assertSamaHitungUlang($s['sd'], (float) $h['standar_deviasi_maks'], 'HIPOTETIS SELISIH B7-10: STDEV maksimum');
        $this->assertSamaHitungUlang($s['u_keterulangan'], (float) $peng['u'], 'HIPOTETIS SELISIH B7-10: u keterulangan = STDEV / akar 5');
        $this->assertSame($s['vi_keterulangan'], (float) $peng['vi'], 'HIPOTETIS SELISIH B7-10: vi keterulangan = n - 1');
        $this->assertSame(1.0, (float) $peng['ci']);

        // Sembilan komponen lainnya tidak bergeser dari master.
        foreach (self::KOMPONEN as $i => $kunci) {
            $this->assertSamaExcel($w['budget'][$kunci]['ui'], (float) $h['budget'][$i]['u'], "HIPOTETIS SELISIH B7-10: {$kunci} tetap sama dengan master");
        }

        // Hilir: uc, v_eff, k, U = skenario (sembilan ui master + komponen itu).
        $this->assertSamaHitungUlang($s['uc'], (float) $h['ketidakpastian_gabungan'], 'HIPOTETIS SELISIH B7-10: uc vs skenario');
        $this->assertSamaHitungUlang($s['veff'], (float) $h['derajat_kebebasan_efektif'], 'HIPOTETIS SELISIH B7-10: v_eff vs skenario');
        $this->assertSamaHitungUlang($s['k'], (float) $h['faktor_cakupan_k'], 'HIPOTETIS SELISIH B7-10: k vs skenario', rel: 0.0, mutlak: self::TOL_K);
        $this->assertSamaHitungUlang($s['u'], (float) $h['ketidakpastian_diperluas'], 'HIPOTETIS SELISIH B7-10: U hitung vs skenario', rel: self::TOL_K_REL);

        // Dan memang berbeda dari rantai master, yang tidak peduli STDEV (tidak ada rumus U95% yang merujuknya).
        $this->assertSame([], preg_grep('/^[KLM]\d+$/', $w['rujukan_u95_ke_fc']), 'master: tak ada rujukan ke sel STDEV');
        $this->assertGreaterThan(1e-3, abs((float) $h['ketidakpastian_gabungan'] - self::nilai($w['agregat']['uc'])) / self::nilai($w['agregat']['uc']), "HIPOTETIS SELISIH B7-10: uc harus berbeda dari {$w['agregat']['uc']['sel']}");
        $this->assertGreaterThan(1e-3, abs((float) $h['derajat_kebebasan_efektif'] - self::nilai($w['agregat']['veff'])) / self::nilai($w['agregat']['veff']), "HIPOTETIS SELISIH B7-10: v_eff harus berbeda dari {$w['agregat']['veff']['sel']}");
        $this->assertGreaterThan(1e-3, abs((float) $h['ketidakpastian_diperluas'] - self::nilai($w['agregat']['u_hitung'])) / self::nilai($w['agregat']['u_hitung']), "HIPOTETIS SELISIH B7-10: U hitung harus berbeda dari {$w['agregat']['u_hitung']['sel']}");

        // Pada masukan ini lantai CMC tetap menutup selisihnya: U95 dilaporkan sama dengan master.
        $this->assertSamaExcel($w['agregat']['u_dilaporkan'], (float) $h['u95_sertifikat'], 'U95 dilaporkan tetap sama berkat lantai CMC');
    }

    /**
     * HIPOTETIS SELISIH B7-10 (jejak): selisih ini TIDAK diam; catatan audit `type_a_masuk_budget` menyebut sel master
     * `M23` dan angka U95 dengan dan tanpa komponen itu, dan profil menyimpan sumbangannya di `type_a` serta
     * `type_b_components`. Masukannya hipotetis (bacaan standar titik 1 divariasikan), bukan sel Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_10_jejak_audit_dan_type_a_profil(): void
    {
        $s = self::w()['skenario']['keterulangan_ditambahkan'];
        $titik = self::masukanTitik();
        $titik[0]['standar'] = array_map('floatval', $s['bacaan_standar_titik_1']);

        $catatan = collect($this->hitung($titik, 10)['catatan_audit'])->firstWhere('kode', 'type_a_masuk_budget');

        $this->assertNotNull($catatan, 'catatan audit type_a_masuk_budget harus ada');
        $this->assertStringContainsString('PERHITUNGAN FC!M23', $catatan['pesan'], 'catatan menyebut sel master');

        foreach ($this->hitungProfil($titik) as $i => $h) {
            $sumber = array_column($h['type_b_components'], 'sumber');

            $this->assertContains('pengulangan', $sumber, 'titik '.($i + 1).': komponen keterulangan di jejak');
            $this->assertContains('type_a_masuk_budget', $sumber, 'titik '.($i + 1).': catatan audit di jejak');
            $this->assertSamaHitungUlang($s['u_keterulangan'], (float) $h['type_a'], 'HIPOTETIS SELISIH B7-10 titik '.($i + 1).': type_a profil');
        }
    }

    /**
     * HIPOTETIS SELISIH B7-10 (cakupan STDEV): master menghitung STDEV standar (`M23`) dan UUT (`M42`) terpisah;
     * aplikasi memakai satu angka, maksimum KEDUANYA. Keterulangan hanya pada pembacaan UUT pun menambah komponen di
     * aplikasi. Bacaan UUT di bawah hipotetis, bukan sel Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_10_stdev_uut_saja_juga_menambah_komponen_di_aplikasi(): void
    {
        // HIPOTETIS (bukan sel workbook): bacaan UUT titik 2 divariasikan, standar tidak.
        $bacaanUut = [99.8, 99.9, 99.9, 99.9, 100.0];
        $titik = self::masukanTitik();
        $titik[1]['uut'] = $bacaanUut;

        $h = $this->hitung($titik, 10);

        $this->assertSamaHitungUlang(self::stdevSampel($bacaanUut), (float) $h['standar_deviasi_maks'], 'HIPOTETIS SELISIH B7-10: STDEV maksimum dari UUT');
        $this->assertSamaHitungUlang(self::stdevSampel($bacaanUut) / sqrt(5), (float) $h['budget'][9]['u'], 'HIPOTETIS SELISIH B7-10: u keterulangan dari STDEV UUT');
        $this->assertSame('pengulangan', $h['budget'][9]['sumber']);
    }

    /**
     * HIPOTETIS SELISIH B7-11: pita CMC. `AC34` master memilih pita lewat `IF(AND(U18>=-20,U18<=150),...,IF(AND(U18>151,U18<=400),...,
     * IF(AND(U18>401,U18<=600),...,"cek kapasitas")))`: set point maksimum di 150..151 dan 400..401 °C jatuh ke teks
     * "cek kapasitas", yang diabaikan `MAX(AC33:AI34)`, jadi U95 master = U hitung tanpa lantai CMC. Aplikasi memakai
     * pita lampiran akreditasi yang bersambung (150..400 = 1,5; 400..600 = 3,3) dan menaikkan U95 ke CMC itu.
     * Skenario (set point hipotetis di celah) dihitung generator dari TEKS rumus, bukan sel Excel; tidak ada sesi
     * contoh di celah itu, jadi tidak dihitung SAMA dari Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_11_celah_pita_cmc_master_aplikasi_memakai_pita_bersambung(): void
    {
        $w = self::w();
        $celah = $w['skenario']['pita_cmc_celah'];
        $spek = $w['masukan']['spek'];
        $cmcPita = ['150.5' => self::nilai($spek['cmc_pita_150_400']), '400.5' => self::nilai($spek['cmc_pita_400_600'])];

        $this->assertSame(self::RUMUS_CMC_MASTER, $w['agregat']['rumus_cmc'], 'rumus master '.$w['agregat']['cmc']['sel']);
        $this->assertSame(self::RUMUS_CMC_MASTER, $celah['rumus'], 'rumus yang dievaluasi skenario');
        $this->assertCount(2, $celah['titik']);

        foreach ($celah['titik'] as $c) {
            $titik = self::masukanTitik();
            $titik[2]['titik_ukur'] = (float) $c['u18'];
            $hitungan = $this->hitungProfil($titik);
            $ke = 'set point maksimum '.$c['u18'];

            // Master: di celah AC34 = teks, tanpa lantai; U95 = U hitung.
            $this->assertNull($c['cmc_master'], "{$ke}: master tak punya CMC");
            $this->assertSame('cek kapasitas', $c['teks_master'], "{$ke}: master memulangkan teks");
            $this->assertSamaExcel($w['agregat']['u_hitung'], (float) $c['u95_master'], "{$ke}: U95 master = U hitung");

            // Aplikasi: pita lampiran yang mencakup set point itu menjadi lantai.
            $cmcApp = $cmcPita[(string) $c['u18']];
            $konteks = collect($hitungan[0]['type_b_components'])->firstWhere('sumber', 'konteks_sesi');

            $this->assertEqualsWithDelta($cmcApp, (float) $konteks['cmc'], 1e-15, "HIPOTETIS SELISIH B7-11 {$ke}: CMC aplikasi dari pita lampiran");
            $this->assertSame('cmc', $konteks['sumber_u95'], "HIPOTETIS SELISIH B7-11 {$ke}: U95 aplikasi dari lantai CMC");
            $this->assertEqualsWithDelta($cmcApp, (float) $hitungan[0]['ketidakpastian_diperluas'], 1e-15, "HIPOTETIS SELISIH B7-11 {$ke}: U95 aplikasi = CMC");
            $this->assertGreaterThan(0.1, (float) $hitungan[0]['ketidakpastian_diperluas'] - (float) $c['u95_master'], "HIPOTETIS SELISIH B7-11 {$ke}: U95 aplikasi harus di atas U95 master");
        }
    }
}
