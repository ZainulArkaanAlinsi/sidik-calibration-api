<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ThermometerGlassProfile;
use App\Services\Calibration\ThermometerGlassCalculator;
use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use App\Support\TataLetakSertifikat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Thermometer Glass: aplikasi lawan workbook master `Master Olah Data_Suhu_Thermometer Glass.xlsm`,
 * tahap demi tahap. Fixture `tests/Fixtures/paritas/thermometer-glass.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-batch7.py`.
 *
 * Titik panggil: `ThermometerGlassCalculator::hitungSesi()` (rantai per titik + budget 11 komponen + agregasi)
 * dan `ThermometerGlassProfile::hitungPerGrup()` (jalur yang sama dengan simpan dan hitung ulang, plus lantai
 * CMC dari pita `CalibrationCapability`).
 *
 * Master ini menghitung SATU budget per sesi (STDEV maksimum, index maksimum, rentang titik es), jadi komponen
 * budget dibandingkan sekali per sesi, bukan per titik.
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-batch7.md`, tanpa mengubah `app/`):
 *  B7-5 keterulangan STANDAR: master `U27 = N27/Q27` (dibagi n), aplikasi dibagi akar n (keputusan lab 16 Sep 2026);
 *  B7-6 faktor cakupan: master polinomial `AC34`, aplikasi kuantil t eksak (selisih relatif 5,7e-7);
 *  B7-7 digit tabel: koreksi probe aplikasi 5 angka penting, drift sensor 9 desimal, master presisi penuh;
 *  B7-8 cetak UUT tiga titik pertama: master format `0` (tanpa desimal), aplikasi satu desimal.
 */
#[Group('paritas')]
class ParitasThermometerGlassTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    /** Urutan baris 20..30 `PERHITUNGAN U95%`, sama dengan urutan `budget` aplikasi dan kunci fixture. */
    private const KOMPONEN = [
        'ketidakpastian_standar',
        'ketidakpastian_sensor',
        'resolusi_standar',
        'drift_standar',
        'drift_sensor',
        'resolusi_alat',
        'pengulangan_uut',
        'pengulangan_standar',
        'variasi_spasial_bath',
        'stabilitas_titik_es',
        'stabilitas_bath',
    ];

    private const DRIFT_SENSOR = 'drift_sensor';

    private const PENGULANGAN_STANDAR = 'pengulangan_standar';

    /** Rumus `PERHITUNGAN U95%!AC34` apa adanya; koefisiennya diulang di [kPolinomial]. */
    private const RUMUS_K_MASTER = '=1.95996+2.37356/AC33+2.818745/AC33^2+2.546662/AC33^3+1.761829/AC33^4+0.245458/AC33^5+1.000764/AC33^6';

    /** @return array<string, mixed> */
    private static function w(): array
    {
        return self::fixtureParitas('thermometer-glass')['workbook'];
    }

    /** @return list<array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}> */
    private static function masukanTitik(): array
    {
        return array_map(static fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => self::nilai($t['nominal']),
            'standar' => array_map('floatval', $t['standar']['nilai']),
            'uut' => array_map('floatval', $t['uut']['nilai']),
        ], self::w()['masukan']['titik']);
    }

    /** @return array<string, mixed> */
    private static function spekKalkulator(): array
    {
        $m = self::w()['masukan'];
        $s = $m['spek'];

        return [
            // Aplikasi menyimpan merk huruf kecil (`TabelKalibratorSuhu3Alat::MERK`); master menulis `Yokogawa`.
            'merk_kalibrator' => strtolower($s['merk_kalibrator']['nilai']),
            'tipe_sensor' => ThermometerGlassProfile::TIPE_SENSOR_STANDAR,
            'no_probe' => (int) self::nilai($m['titik'][0]['no_probe']),
            'oilbath' => $s['oilbath']['nilai'],
            'resolusi' => self::nilai($s['resolusi_alat']),
            'resolusi_standar' => self::nilai($s['resolusi_standar_x2']),
            'cmc' => self::nilai($s['cmc_pita_0_100']),
            'titik_es' => array_map(static fn (array $e): float => self::nilai($e), $s['titik_es']),
        ];
    }

    /** @return array<string, mixed> */
    private function hitung(): array
    {
        $hasil = (new ThermometerGlassCalculator)->hitungSesi(self::masukanTitik(), self::spekKalkulator());

        $this->assertSame([], $hasil['belum_dihitung'], 'titik belum dihitung');
        $this->assertCount(5, $hasil['titik'], 'jumlah titik');
        $this->assertCount(count(self::KOMPONEN), $hasil['budget'], 'jumlah komponen budget');

        return $hasil;
    }

    /**
     * Rantai master `PERHITUNGAN U95%!AC31:AC33` direkonstruksi di tes dari kolom ui dan vi:
     * uc = SQRT(SUM(AC20:AC30)), v_eff = uc^4 / SUM(AG20:AG30). Dipakai hanya untuk membuktikan penyebab
     * selisih (skenario berlabel), bukan sebagai acuan baru; kebenarannya dibuktikan lebih dulu pada ui master.
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

    /** `PERHITUNGAN U95%!AC34`: aproksimasi polinomial master untuk t(0,975; v_eff). */
    private static function kPolinomial(float $v): float
    {
        return 1.95996 + 2.37356 / $v + 2.818745 / $v ** 2 + 2.546662 / $v ** 3 + 1.761829 / $v ** 4 + 0.245458 / $v ** 5 + 1.000764 / $v ** 6;
    }

    /**
     * Daftar ui master (kolom U baris 20..30), dengan penggantian per kunci komponen bila diminta.
     *
     * @param  array<string, float>  $ganti
     * @return array{ui: list<float>, vi: list<float>}
     */
    private static function uiMaster(array $ganti = []): array
    {
        $ui = [];
        $vi = [];

        foreach (self::KOMPONEN as $kunci) {
            $b = self::w()['budget'][$kunci];
            $ui[] = $ganti[$kunci] ?? self::nilai($b['ui']);
            $vi[] = self::nilai($b['vi']);
        }

        return ['ui' => $ui, 'vi' => $vi];
    }

    // ------------------------------------------------------------------ masukan dan konstanta

    public function test_masukan_master_cocok_dengan_konstanta_dan_pembacaan_aplikasi(): void
    {
        $m = self::w()['masukan'];

        $this->assertSame('PRT PT100', $m['spek']['sensor_standar']['nilai'], 'sel '.$m['spek']['sensor_standar']['sel']);
        $this->assertSame('RTD', ThermometerGlassProfile::TIPE_SENSOR_STANDAR, 'aplikasi menamai PRT PT100 sebagai RTD');
        $this->assertSame('Yokogawa', $m['spek']['merk_kalibrator']['nilai'], 'sel '.$m['spek']['merk_kalibrator']['sel']);
        $this->assertSame('dua', $m['spek']['oilbath']['nilai'], 'sel '.$m['spek']['oilbath']['sel']);
        $this->assertContains('dua', array_column(ThermometerGlassProfile::OILBATH, 'nilai'), 'oilbath dua dikenal aplikasi');
        $this->assertCount(5, $m['titik'], 'master contoh punya lima titik');
        // assertEquals: konstanta aplikasi bilangan bulat, sel master desimal; yang dibandingkan nilainya.
        $this->assertEquals(
            array_map(static fn (array $t): float => self::nilai($t['nominal']), $m['titik']),
            ThermometerGlassProfile::TITIK_SARAN,
            'titik saran aplikasi = nominal master (INPUT DATA!B50:B54)',
        );

        foreach ($m['titik'] as $i => $t) {
            $ke = 'titik '.($i + 1);

            $this->assertSame((float) ThermometerGlassProfile::NO_PROBE, self::nilai($t['no_probe']), "{$ke}: no. probe {$t['no_probe']['sel']}");
            $this->assertCount(ThermometerGlassCalculator::N_PENGULANGAN, $t['standar']['nilai'], "{$ke}: jumlah pembacaan standar");
            $this->assertCount(ThermometerGlassCalculator::N_PENGULANGAN, $t['uut']['nilai'], "{$ke}: jumlah pembacaan UUT");
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
            $this->assertSamaExcel($x['indeks_tabel'], (float) $t['index_standar'], "{$ke} index tabel");
            $this->assertSamaExcel($x['koreksi_meter'], (float) $t['koreksi_meter_standar'], "{$ke} koreksi meter kalibrator");
            $this->assertSamaExcel($x['uut_rata_rata'], (float) $t['rata_rata_uut'], "{$ke} rata-rata UUT");
            $this->assertSamaExcel($x['uut_sd'], (float) $t['standar_deviasi_uut'], "{$ke} STDEV UUT");

            // Standar terkoreksi lulus di batas relatif baku karena besarnya 30-100 °C: selisih koreksi probe
            // (B7-7, sampai 2,3e-10 mutlak) tertutup batas 1e-11 x nilai. Dicatat di dokumen, bukan disembunyikan.
            $this->assertSamaExcel($x['standar_terkoreksi'], (float) $t['standar_terkoreksi'], "{$ke} standar terkoreksi");
        }
    }

    public function test_besaran_sesi_sama_dengan_sel_master(): void
    {
        $w = self::w()['sesi'];
        $h = $this->hitung();

        $this->assertSamaExcel($w['sd_maks_standar'], (float) $h['standar_deviasi_maks_standar'], 'STDEV standar maksimum');
        $this->assertSamaExcel($w['sd_maks_uut'], (float) $h['standar_deviasi_maks_uut'], 'STDEV UUT maksimum');
        $this->assertSamaExcel($w['indeks_maks'], (float) $h['index_maks'], 'index tabel maksimum');
        $this->assertSamaExcel($w['rentang_titik_es'], (float) $h['rentang_titik_es'], 'rentang uji titik es');
    }

    // ------------------------------------------------------------------ budget

    public function test_sebelas_komponen_budget_sama_dengan_master_kecuali_selisih_terkunci(): void
    {
        $budget = self::w()['budget'];

        $this->assertSame(self::KOMPONEN, array_keys($budget), 'urutan 11 komponen fixture = baris 20..30 master');

        $app = $this->hitung()['budget'];

        $this->assertSame(self::KOMPONEN, array_column($app, 'sumber'), 'urutan dan nama komponen aplikasi');

        foreach (self::KOMPONEN as $i => $kunci) {
            $x = $budget[$kunci];
            $k = $app[$i];

            $this->assertTrue($k['disertakan'], "{$kunci}: disertakan");
            $this->assertSamaExcel($x['vi'], (float) $k['vi'], "{$kunci}: vi");
            $this->assertSamaExcel($x['ci'], (float) $k['ci'], "{$kunci}: ci");

            // Dua komponen dikunci terpisah: pengulangan standar (B7-5) dan drift sensor (B7-7).
            if (in_array($kunci, [self::PENGULANGAN_STANDAR, self::DRIFT_SENSOR], true)) {
                continue;
            }

            $this->assertSamaExcel($x['ui'], (float) $k['u'], "{$kunci}: ui");
        }
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

    // ------------------------------------------------------------------ angka cetak

    /**
     * Cetak satu sel tabel hasil lewat `TataLetakSertifikat::teksSel`, dengan desimal yang dibaca dari profil.
     * Paket suhu 3 alat tidak punya peta `format_sertifikat`, jadi semua kolom mengikuti `desimalSertifikat()` dan
     * `desimalU95()`. Ini bukan render PDF penuh; titik desimal bentuk master lewat `keTitik`.
     */
    private function cetak(string $kolom, float $nilai): string
    {
        $profil = new ThermometerGlassProfile;
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
        return self::keTitik(TataLetakSertifikat::teksK(['desimal_k' => (new ThermometerGlassProfile)->desimalFaktorCakupan()], $k));
    }

    public function test_cetak_standar_dan_koreksi_sama_dengan_teks_sertifikat(): void
    {
        foreach ($this->hitung()['titik'] as $i => $t) {
            $s = self::w()['titik'][$i]['sertifikat'];
            $ke = 'titik '.($i + 1);

            $this->assertSame($s['standar']['teks'], $this->cetak('standar', (float) $t['standar_terkoreksi']), "{$ke}: standar vs {$s['standar']['sel']}");
            $this->assertSame($s['koreksi']['teks'], $this->cetak('koreksi', (float) $t['koreksi']), "{$ke}: koreksi vs {$s['koreksi']['sel']}");
        }
    }

    public function test_cetak_uut_titik_keempat_dan_kelima_sama_dengan_teks_sertifikat(): void
    {
        $hasil = $this->hitung()['titik'];

        foreach ([3, 4] as $i) {
            $uut = self::w()['titik'][$i]['sertifikat']['uut'];

            $this->assertSame('0.0', $uut['format'], 'titik '.($i + 1).": format {$uut['sel']}");
            $this->assertSame($uut['teks'], $this->cetak('uut', (float) $hasil[$i]['uut_terkoreksi']), 'titik '.($i + 1).": UUT vs {$uut['sel']}");
        }
    }

    public function test_cetak_u95_dan_k_sama_dengan_teks_sertifikat_walau_nilai_antara_beda(): void
    {
        $s = self::w()['sertifikat'];
        $h = $this->hitung();

        $this->assertSame($s['u95']['teks'], $this->cetak('u95', (float) $h['u95_sertifikat']), "U95 vs {$s['u95']['sel']}");
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
                if ($alat['nama_alat'] !== 'Termometer Gelas') {
                    continue;
                }

                foreach ($alat['rentang'] as $r) {
                    CalibrationCapability::factory()->create([
                        'organization_id' => $organisasi->id,
                        'equipment_category_id' => $kategori->id,
                        'nama_alat' => 'Termometer Gelas',
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

        $this->assertSame(2, $pita, 'Lampiran akreditasi Termometer Gelas harus punya dua pita CMC.');

        $alat = Equipment::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Termometer Gelas Uji Paritas',
            'nama_alat_kemampuan' => 'Termometer Gelas',
            'satuan' => '°C',
            'range_min' => 0,
            'range_max' => 100,
            'resolusi' => self::nilai(self::w()['masukan']['spek']['resolusi_alat']),
            'toleransi' => null,
        ]);

        $kalibrator = Standard::factory()->create([
            'organization_id' => $organisasi->id,
            'nama' => 'Kalibrator Suhu Uji Paritas',
            // Kolom `merk` yang MENENTUKAN tabel koreksi mana yang dibaca profil.
            'merk' => self::w()['masukan']['spek']['merk_kalibrator']['nilai'],
            'serial_number' => 'PARITAS-TG-01',
            'parameter_kondisi' => null,
            'berlaku_sampai' => now()->addYear(),
        ]);

        return [$alat, $kalibrator];
    }

    /** @return list<array<string, mixed>> keluaran `hitungan` profil, urut titik. */
    private function hitungProfil(): array
    {
        [$alat, $kalibrator] = $this->alatDanKalibrator();
        $spek = self::w()['masukan']['spek'];
        $titikEs = array_map(static fn (array $e): float => self::nilai($e), $spek['titik_es']);

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => $t['titik_ukur'],
            'pembacaan' => [],
            'standard' => $kalibrator,
            'konteks' => [
                'alat_bantu' => $spek['oilbath']['nilai'],
                'standar' => $t['standar'],
                'uut' => $t['uut'],
                'titik_es' => $titikEs,
            ],
        ], self::masukanTitik());

        $hasil = (new ThermometerGlassProfile)->hitungPerGrup($titik, $alat);

        $this->assertNotNull($hasil, 'profil tidak menghitung');
        $this->assertSame([], $hasil['belum_dihitung'], 'titik belum dihitung');
        $this->assertCount(5, $hasil['hitungan'], 'jumlah titik profil');

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
            // `titik_ukur` baris hitungan = standar terkoreksi (kolom Standard Reading), `rata_rata` = UUT.
            $this->assertSamaExcel($x['standar_terkoreksi'], (float) $h['titik_ukur'], "{$ke} profil: standar terkoreksi");
            $this->assertSamaExcel($x['uut_rata_rata'], (float) $h['rata_rata'], "{$ke} profil: rata-rata UUT");
            $this->assertSamaExcel($x['uut_sd'], (float) $h['standar_deviasi'], "{$ke} profil: STDEV UUT");
            $this->assertSame(5, $h['jumlah_pengulangan'], "{$ke} profil: jumlah pengulangan");

            // Simpan, hitung ulang, dan pratinjau memakai fungsi yang sama: jalur profil = kalkulator persis.
            $this->assertSame((float) $t['koreksi'], (float) $h['koreksi'], "{$ke} profil: koreksi = kalkulator");
            $this->assertSame((float) $kalkulator['ketidakpastian_gabungan'], (float) $h['ketidakpastian_gabungan'], "{$ke} profil: uc = kalkulator");
            $this->assertSame((float) $kalkulator['faktor_cakupan_k'], (float) $h['faktor_cakupan_k'], "{$ke} profil: k = kalkulator");
            $this->assertSame((float) $kalkulator['derajat_kebebasan_efektif'], (float) $h['derajat_kebebasan_efektif'], "{$ke} profil: v_eff = kalkulator");
            $this->assertSame((float) $kalkulator['u95_sertifikat'], (float) $h['ketidakpastian_diperluas'], "{$ke} profil: U95 = kalkulator");
        }
    }

    /**
     * Pita CMC: titik tertinggi 100 °C ada di dua pita (0-100 dan 100-200); pita BAWAH menang, sama dengan
     * `AC36` master (`IF(U18<=100, DATABASE!S5, ...)`). U hitung 1,13 di atas CMC 0,58, jadi lantai CMC tidak aktif
     * dan U95 sertifikat = U hitung, sama dengan master yang `MAX(AC35:AI36)` jatuh ke U hitung.
     */
    public function test_pita_cmc_bawah_menang_di_100_derajat_dan_lantai_tidak_aktif(): void
    {
        $spek = self::w()['masukan']['spek'];

        $hitungan = $this->hitungProfil();

        $pita = CalibrationCapability::query()->where('nama_alat', 'Termometer Gelas')->orderBy('range_max')->get();

        $this->assertCount(2, $pita);
        $this->assertEqualsWithDelta(self::nilai($spek['cmc_pita_0_100']), (float) $pita[0]->ketidakpastian_terbaik, 1e-15, 'pita 0-100 vs '.$spek['cmc_pita_0_100']['sel']);
        $this->assertEqualsWithDelta(self::nilai($spek['cmc_pita_100_200']), (float) $pita[1]->ketidakpastian_terbaik, 1e-15, 'pita 100-200 vs '.$spek['cmc_pita_100_200']['sel']);

        foreach ($hitungan as $i => $h) {
            $konteks = collect($h['type_b_components'])->firstWhere('sumber', 'konteks_sesi');

            $this->assertNotNull($konteks, 'titik '.($i + 1).': jejak konteks_sesi');
            $this->assertEqualsWithDelta(self::nilai(self::w()['agregat']['cmc']), (float) $konteks['cmc'], 1e-15, 'titik '.($i + 1).': CMC dipakai vs '.self::w()['agregat']['cmc']['sel']);
            $this->assertSame('hitung', $konteks['sumber_u95'], 'titik '.($i + 1).': U95 dari hitungan budget, bukan lantai CMC');
            $this->assertSame((float) $konteks['ketidakpastian_diperluas_hitung'], (float) $h['ketidakpastian_diperluas'], 'titik '.($i + 1).': U95 = U hitung');
        }
    }

    public function test_jejak_audit_profil_memuat_sebelas_komponen_dan_cetak_u95_sama_dengan_sertifikat(): void
    {
        $baris = $this->hitungProfil();
        $u = (float) $baris[0]['ketidakpastian_diperluas'];

        foreach ($baris as $i => $h) {
            $komponen = array_slice($h['type_b_components'], 0, count(self::KOMPONEN));

            $this->assertSame(self::KOMPONEN, array_column($komponen, 'sumber'), 'titik '.($i + 1).': sebelas komponen di jejak');
            // Master menghitung SATU U95 per sesi (`SERTIFIKAT!L35` dari `AC37`), jadi semua titik bernilai sama.
            $this->assertSame($u, (float) $h['ketidakpastian_diperluas'], 'titik '.($i + 1).': U95 satu per sesi');
        }

        $this->assertSame(self::w()['sertifikat']['u95']['teks'], $this->cetak('u95', $u), 'cetak U95 profil vs '.self::w()['sertifikat']['u95']['sel']);
    }

    // ------------------------------------------------------------------ selisih terkunci (menunggu keputusan TM)

    /**
     * B7-5: keterulangan STANDAR. Master `U27 = N27/Q27` dengan Q27 = 5 (dibagi n); baris di atasnya,
     * keterulangan UUT, memakai `U26 = N26/SQRT(Q26)`. Aplikasi membagi akar n (GUM 4.2.3, keputusan lab 16 Sep 2026).
     * Dikunci apa adanya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_5_ui_pengulangan_standar_aplikasi_dibagi_akar_n_master_dibagi_n(): void
    {
        $b = self::w()['budget'][self::PENGULANGAN_STANDAR];
        $app = $this->hitung()['budget'][array_search(self::PENGULANGAN_STANDAR, self::KOMPONEN, true)];
        $mentah = self::nilai($b['mentah']);
        $pembagi = self::nilai($b['pembagi']);

        $this->assertSame(ThermometerGlassCalculator::PEMBAGI_PENGULANGAN_STANDAR_MASTER, $pembagi, 'pembagi master '.$b['pembagi']['sel']);
        $this->assertSame('=N27/Q27', $b['rumus_ui'], 'rumus ui master (tanpa SQRT)');
        $this->assertSamaExcel($b['ui'], $mentah / $pembagi, 'ui master = N27/Q27');
        $this->assertSamaHitungUlang($mentah / sqrt($pembagi), (float) $app['u'], 'SELISIH B7-5: ui aplikasi = N27/akar(Q27)');
        $this->assertGreaterThan(0.5, abs((float) $app['u'] - self::nilai($b['ui'])) / self::nilai($b['ui']), "SELISIH B7-5: ui aplikasi harus berbeda dari {$b['ui']['sel']}");
    }

    #[Group('menunggu-keputusan-tm')]
    public function test_b7_5_dampak_hilir_uc_veff_k_dan_u_sama_dengan_skenario_akar_n(): void
    {
        $w = self::w();
        $a = $w['agregat'];
        $skenario = $w['skenario']['pengulangan_standar_dibagi_akar_n'];
        $h = $this->hitung();

        // Skenario fixture memakai nilai-cache ui master di semua baris selain U27, jadi masih membawa ui drift
        // sensor presisi penuh (B7-7). Selisih sisanya relatif ~1e-10, di bawah batas U/uc/v_eff 1e-9.
        $this->assertSamaHitungUlang($skenario['uc'], (float) $h['ketidakpastian_gabungan'], 'SELISIH B7-5: uc vs skenario akar n', rel: self::TOL_K_REL);
        $this->assertSamaHitungUlang($skenario['veff'], (float) $h['derajat_kebebasan_efektif'], 'SELISIH B7-5: v_eff vs skenario akar n', rel: self::TOL_K_REL);
        $this->assertSamaHitungUlang($skenario['k'], (float) $h['faktor_cakupan_k'], 'SELISIH B7-5: k vs skenario akar n', rel: 0.0, mutlak: self::TOL_K);
        $this->assertSamaHitungUlang($skenario['u'], (float) $h['ketidakpastian_diperluas'], 'SELISIH B7-5: U vs skenario akar n', rel: self::TOL_K_REL);

        // Dan memang berbeda dari master (uc dan U naik sekitar 0,8 %, v_eff turun sekitar 1 %).
        foreach (['uc' => 'ketidakpastian_gabungan', 'veff' => 'derajat_kebebasan_efektif'] as $kunciExcel => $kunciApp) {
            $this->assertGreaterThan(1e-3, abs((float) $h[$kunciApp] - self::nilai($a[$kunciExcel])) / self::nilai($a[$kunciExcel]), "SELISIH B7-5: {$kunciApp} harus berbeda dari {$a[$kunciExcel]['sel']}");
        }

        $this->assertGreaterThan(1e-3, abs((float) $h['ketidakpastian_diperluas'] - self::nilai($a['u_hitung'])) / self::nilai($a['u_hitung']), "SELISIH B7-5: U harus berbeda dari {$a['u_hitung']['sel']}");
        $this->assertGreaterThan(1e-5, abs((float) $h['faktor_cakupan_k'] - self::nilai($a['k'])), "SELISIH B7-5: k harus berbeda dari {$a['k']['sel']}");
    }

    /**
     * B7-5 (jejak): selisih ini TIDAK diam; kalimat audit sesi menyebut U95 dengan pembagi master (1,117354) dan
     * U95 yang dipakai (1,126199), supaya penyetuju tahu tanpa membuka kode (AGENTS.md, Olah data butir 4).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_5_jejak_audit_menyebut_u95_pembagi_master_dan_u95_dipakai(): void
    {
        $w = self::w();
        $hasil = $this->hitung();
        $catatan = collect($hasil['catatan_audit'])->firstWhere('kode', 'pengulangan_standar_dibagi_n');

        $this->assertNotNull($catatan, 'catatan audit pengulangan_standar_dibagi_n harus ada');
        $this->assertStringContainsString('U27 = N27/Q27', $catatan['pesan'], 'catatan menyebut sel master');
        $this->assertStringContainsString(number_format(self::nilai($w['agregat']['u_dilaporkan']), 6, ',', ''), $catatan['pesan'], 'catatan memuat U95 pembagi master (AC37)');
        $this->assertStringContainsString(number_format($w['skenario']['pengulangan_standar_dibagi_akar_n']['u'], 6, ',', ''), $catatan['pesan'], 'catatan memuat U95 yang dipakai');

        // Profil menurunkan catatan itu ke `type_b_components` yang tersimpan di sesi.
        foreach ($this->hitungProfil() as $h) {
            $this->assertContains('pengulangan_standar_dibagi_n', array_column($h['type_b_components'], 'sumber'));
        }
    }

    /**
     * B7-6: faktor cakupan. Master `AC34` = aproksimasi polinomial dari `AC33` (tanpa pemotongan). Aplikasi memakai
     * kuantil t(0,975; v_eff) eksak, juga tanpa pemotongan (`FLOOR_V_EFF = false`), jadi beda hanya algoritma: selisih
     * relatif 5,7e-7 pada v_eff master. Tidak mengubah angka cetak (k tercetak `2`, U95 `1.1`).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_6_k_master_polinomial_aplikasi_kuantil_eksak(): void
    {
        $w = self::w();
        $a = $w['agregat'];
        $skenario = $w['skenario'];
        $veffMaster = self::nilai($a['veff']);
        $t = new StudentTDistribution;

        // Master: AC34 memang polinomial dari AC33 (rumus dan nilainya).
        $this->assertSame(self::RUMUS_K_MASTER, $a['rumus_k'], 'rumus master '.$a['k']['sel']);
        $this->assertSamaExcel($a['k'], self::kPolinomial($veffMaster), 'polinomial di v_eff master = '.$a['k']['sel']);

        // Aplikasi pada v_eff yang SAMA dengan master = kuantil eksak (scipy t.ppf), bukan polinomial.
        $kEksak = $t->quantile(0.975, $veffMaster);
        $this->assertSamaHitungUlang($skenario['k_kuantil_eksak_pada_veff_master']['k'], $kEksak, 'SELISIH B7-6: kuantil aplikasi vs scipy pada v_eff master', rel: 0.0, mutlak: self::TOL_K);
        $this->assertGreaterThan(1e-7, abs($kEksak - self::nilai($a['k'])), "SELISIH B7-6: kuantil eksak harus berbeda dari {$a['k']['sel']}");
        $this->assertSamaHitungUlang($skenario['k_kuantil_eksak_pada_veff_master']['k'] - self::nilai($a['k']), $kEksak - self::nilai($a['k']), 'SELISIH B7-6: besar selisih k', rel: 0.0, mutlak: self::TOL_K);

        // Aplikasi sendiri: k = kuantil di v_eff-nya TANPA dipotong, dan polinomial pada v_eff itu berbeda.
        $h = $this->hitung();
        $veffApp = (float) $h['derajat_kebebasan_efektif'];

        $this->assertSame($t->quantile(0.975, $veffApp), (float) $h['faktor_cakupan_k'], 'k aplikasi = kuantil pada v_eff tanpa dipotong');
        $this->assertGreaterThan(1e-7, abs($t->quantile(0.975, floor($veffApp)) - (float) $h['faktor_cakupan_k']), 'pemotongan v_eff akan menggeser k; aplikasi tidak memotong');
        $this->assertSamaHitungUlang($skenario['pengulangan_standar_dibagi_akar_n']['k_polinomial'], self::kPolinomial($veffApp), 'polinomial master di v_eff aplikasi', rel: 0.0, mutlak: self::TOL_K);
        $this->assertGreaterThan(1e-7, abs(self::kPolinomial($veffApp) - (float) $h['faktor_cakupan_k']), 'SELISIH B7-6: k aplikasi harus berbeda dari polinomial master');
    }

    /**
     * B7-7: digit tabel. Dua tabel referensi aplikasi lebih pendek dari sel master (nilai-cache presisi penuh):
     * koreksi probe PRT di `PERHITUNGAN FC!R23:R27` (aplikasi 5 angka penting), dan ui drift sensor
     * `PERHITUNGAN U95%!N24` (aplikasi 9 desimal, `peta-sel/suhu_3alat.json` `bulat: 9` = 0,501701242). Selisih mutlak sampai 2,3e-10 dan 1,4e-10; tidak mengubah cetak.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_7_koreksi_probe_aplikasi_dibulatkan_lima_angka_penting(): void
    {
        $terlihat = false;

        foreach ($this->hitung()['titik'] as $i => $t) {
            $x = self::w()['titik'][$i]['koreksi_probe'];
            $app = (float) $t['koreksi_probe_standar'];
            $selisih = abs($app - self::nilai($x));
            // Setengah satuan angka penting kelima dari nilai aplikasi: batas galat pembulatan 5 angka penting.
            $batas = 0.5 * 10 ** (floor(log10(abs($app))) - 4);

            $this->assertLessThanOrEqual($batas, $selisih, 'SELISIH B7-7 titik '.($i + 1).": koreksi probe aplikasi vs {$x['sel']} harus selisih pembulatan 5 angka penting saja");
            $terlihat = $terlihat || $selisih > max(self::TOL_MUTLAK, self::TOL_REL * abs(self::nilai($x)));
        }

        $this->assertTrue($terlihat, 'SELISIH B7-7: setidaknya satu titik harus berbeda dari sel R master di atas toleransi baku');
    }

    #[Group('menunggu-keputusan-tm')]
    public function test_b7_7_koreksi_per_titik_berbeda_persis_sebesar_selisih_koreksi_probe(): void
    {
        $terlihat = false;

        foreach ($this->hitung()['titik'] as $i => $t) {
            $w = self::w()['titik'][$i];
            $delta = (float) $t['koreksi_probe_standar'] - self::nilai($w['koreksi_probe']);
            $ke = 'titik '.($i + 1);

            // Koreksi = standar terkoreksi - UUT; satu-satunya masukan yang beda dari master adalah koreksi probe.
            $this->assertSamaHitungUlang(self::nilai($w['koreksi']) + $delta, (float) $t['koreksi'], "SELISIH B7-7 {$ke}: koreksi = {$w['koreksi']['sel']} + selisih koreksi probe", rel: 0.0, mutlak: self::TOL_MUTLAK);
            $terlihat = $terlihat || abs((float) $t['koreksi'] - self::nilai($w['koreksi'])) > max(self::TOL_MUTLAK, self::TOL_REL * abs(self::nilai($w['koreksi'])));
        }

        $this->assertTrue($terlihat, 'SELISIH B7-7: koreksi aplikasi harus berbeda dari sel L sertifikat di atas toleransi baku');
    }

    #[Group('menunggu-keputusan-tm')]
    public function test_b7_7_ui_drift_sensor_aplikasi_dari_tabel_sembilan_desimal(): void
    {
        $b = self::w()['budget'][self::DRIFT_SENSOR];
        $app = (float) $this->hitung()['budget'][array_search(self::DRIFT_SENSOR, self::KOMPONEN, true)]['u'];
        $pembagi = self::nilai($b['pembagi']);

        $this->assertSamaHitungUlang(round(self::nilai($b['mentah']), 9) / $pembagi, $app, "SELISIH B7-7: ui drift sensor aplikasi = round({$b['mentah']['sel']}, 9) / pembagi");
        $this->assertGreaterThan(
            max(self::TOL_MUTLAK, self::TOL_REL * self::nilai($b['ui'])),
            abs($app - self::nilai($b['ui'])),
            "SELISIH B7-7: ui drift sensor aplikasi harus berbeda dari {$b['ui']['sel']}",
        );
    }

    /**
     * B7-5 + B7-7 bersama menjelaskan SELURUH selisih rantai: rantai master (uc, v_eff) dengan dua masukan itu saja
     * yang diganti (U27 = N27/akar(Q27), ui drift sensor dari tabel 9 desimal) sama dengan aplikasi pada batas baku
     * 1e-11, bukan 1e-9. Skenario dihitung di tes dari sel-sel fixture (berlabel), bukan sel Excel.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_5_dan_b7_7_menjelaskan_seluruh_selisih_uc_veff_k_dan_u(): void
    {
        $w = self::w();
        $peng = $w['budget'][self::PENGULANGAN_STANDAR];
        $drift = $w['budget'][self::DRIFT_SENSOR];

        $m = self::uiMaster([
            self::PENGULANGAN_STANDAR => self::nilai($peng['mentah']) / sqrt(self::nilai($peng['pembagi'])),
            self::DRIFT_SENSOR => round(self::nilai($drift['mentah']), 9) / self::nilai($drift['pembagi']),
        ]);
        $rantai = self::rantaiMaster($m['ui'], $m['vi']);
        $h = $this->hitung();

        $this->assertSamaHitungUlang($rantai['uc'], (float) $h['ketidakpastian_gabungan'], 'skenario B7-5+B7-7: uc');
        $this->assertSamaHitungUlang($rantai['veff'], (float) $h['derajat_kebebasan_efektif'], 'skenario B7-5+B7-7: v_eff');

        $kEksak = (new StudentTDistribution)->quantile(0.975, $rantai['veff']);
        $this->assertSamaHitungUlang($kEksak, (float) $h['faktor_cakupan_k'], 'skenario B7-5+B7-7: k', rel: 0.0, mutlak: self::TOL_K);
        $this->assertSamaHitungUlang($kEksak * $rantai['uc'], (float) $h['ketidakpastian_diperluas'], 'skenario B7-5+B7-7: U', rel: self::TOL_K_REL);
    }

    /**
     * B7-8: cetak UUT. Master `SERTIFIKAT!J21:J23` berformat `0` ("30", "51", "61"), `J24:J25` berformat `0.0`
     * ("82.0", "99.0"): satu kolom, dua format di master. Aplikasi satu desimal di semua titik karena paket suhu
     * 3 alat tidak punya peta `format_sertifikat`. Dikunci apa adanya (docblock `ThermometerGlassProfile` yang
     * menyebut "NOL desimal" tidak cocok dengan kodenya; temuan di luar tugas ini).
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_8_cetak_uut_tiga_titik_pertama_aplikasi_satu_desimal_master_nol_desimal(): void
    {
        $hasil = $this->hitung()['titik'];

        foreach ([0, 1, 2] as $i) {
            $uut = self::w()['titik'][$i]['sertifikat']['uut'];
            $app = $this->cetak('uut', (float) $hasil[$i]['uut_terkoreksi']);

            $this->assertSame('0', $uut['format'], 'titik '.($i + 1).": format master {$uut['sel']}");
            $this->assertSame($uut['teks'].'.0', $app, 'SELISIH B7-8 titik '.($i + 1).": cetak UUT aplikasi vs {$uut['sel']}");
            $this->assertNotSame($uut['teks'], $app, 'SELISIH B7-8 titik '.($i + 1).': cetak aplikasi harus berbeda dari master');
        }
    }
}
