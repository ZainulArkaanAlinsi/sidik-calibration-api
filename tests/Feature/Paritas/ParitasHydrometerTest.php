<?php

namespace Tests\Feature\Paritas;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Services\Acuan\FormatSertifikatAcuan;
use App\Services\Calibration\HydrometerCalculator;
use App\Services\Calibration\Profiles\HydrometerProfile;
use App\Support\Angka;
use App\Support\HydrometerMentah;
use App\Support\TataLetakSertifikat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS Hydrometer: aplikasi lawan dua workbook master `Hydrometer 0.600 - 0.650 gmL.xlsx` (ringan, varian
 * beban tambahan) dan `Hydrometer 1.800 - 2.000 gmL.xlsx` (berat, tanpa beban tambahan), tahap demi tahap.
 * Fixture `tests/Fixtures/paritas/hydrometer.json`, dibangun `docs/skrip/gen-fixture-paritas-batch7.py`.
 *
 * Titik panggil: `HydrometerCalculator::hitungSesi()` (rantai Cuckow + budget 11 komponen) dan
 * `HydrometerProfile::hitungPerGrup()` (jalur yang sama dengan simpan dan hitung ulang, plus lantai CMC).
 *
 * Konflik yang dikunci di sini (nomor di `docs/paritas-batch7.md`, tanpa mengubah `app/`):
 *  B7-1 CMC ringan: master mengetik 0,0007 di semua skala, aplikasi memakai pita akreditasi (0,00051);
 *  B7-2 penyebut koefisien tegangan permukaan: master berat per skala, aplikasi meniru master ringan (O55);
 *  B7-3 koefisien stem skala 3: `NILAI U95%!J139` menunjuk C57 (milik skala 1), aplikasi memakai C126;
 *
 * Dugaan awal "B7-4" (format cetak U95 berat `0.0000` lawan lima desimal) TERBUKTI KELIRU: jalur cetak sungguhan
 * memakai peta `format_sertifikat`, bukan `desimalU95()`, dan petanya sama dengan sel master. Nomornya tidak dipakai.
 * Cetak dibandingkan lewat `FormatSertifikatAcuan` + `TataLetakSertifikat::formatAngka`.
 */
#[Group('paritas')]
class ParitasHydrometerTest extends TestCase
{
    use MenegakkanParitas;
    use RefreshDatabase;

    private const STEM = 'Stem Diameter';

    private const TEGANGAN = 'Surface Tension of liquid reference';

    /** Indeks (0-based) skala yang ci stem-nya salah rujuk di kedua master (`J139` = C57). */
    private const SKALA_STEM_RUSAK = 2;

    /** @return array<string, array{string}> */
    public static function varian(): array
    {
        return ['ringan' => ['ringan'], 'berat' => ['berat']];
    }

    /** @return array<string, mixed> */
    private static function w(string $varian): array
    {
        return self::fixtureParitas('hydrometer')['workbook'][$varian];
    }

    /**
     * Blok Pre Condition sesi dari sel masukan master. Master tidak punya saklar beban tambahan; varian
     * ditentukan oleh `PERHITUNGAN!E24` terisi (ringan 54,0052) atau nol (berat).
     *
     * @return array<string, mixed>
     */
    private static function blokPreCondition(string $varian): array
    {
        $m = self::w($varian)['masukan'];
        $sl = self::nilai($m['beban_tambahan_Sl']);

        return [
            'pakai_beban_tambahan' => $sl > 0,
            'beban_tambahan' => $sl > 0 ? $sl : null,
            'massa_udara' => self::nilai($m['massa_udara_Ma']),
            'tegangan_permukaan' => self::nilai($m['tegangan_permukaan']),
            'satuan_tegangan' => $m['satuan_tegangan']['nilai'],
            'suhu_acuan_alat' => self::nilai($m['suhu_acuan_alat_tr']),
            'suhu_acuan_faktor' => self::nilai($m['suhu_acuan_faktor']),
            'diameter_stem' => array_map('floatval', $m['diameter_stem']['nilai']),
            'resolusi' => self::nilai($m['resolusi']),
        ];
    }

    /** @return array<string, float> */
    private static function lingkungan(string $varian): array
    {
        $m = self::w($varian)['masukan'];
        $hasil = [];

        foreach (['suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir', 'tekanan_awal', 'tekanan_akhir'] as $kunci) {
            $hasil[$kunci] = self::nilai($m[$kunci]);
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function hitung(string $varian): array
    {
        $titik = [];

        foreach (self::w($varian)['titik'] as $t) {
            $titik[] = [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => self::nilai($t['titik_ukur']),
                'massa' => array_map('floatval', $t['massa']['nilai']),
                'suhu' => array_map('floatval', $t['suhu']['nilai']),
            ];
        }

        $hasil = (new HydrometerCalculator)->hitungSesi($titik, self::blokPreCondition($varian) + self::lingkungan($varian));

        $this->assertSame([], $hasil['ditolak'], "{$varian}: titik ditolak gerbang aplikasi");
        $this->assertTrue($hasil['boleh_terbit'], "{$varian}: sesi tidak boleh terbit");
        $this->assertCount(3, $hasil['titik'], "{$varian}: jumlah titik");

        return $hasil;
    }

    /** Satu elemen deret `{sel: [...], nilai: [...]}` jadi `{sel, nilai}` supaya pesan galat menyebut selnya. */
    private static function elemen(array $deret, int $i): array
    {
        return ['sel' => $deret['sel'][$i], 'nilai' => $deret['nilai'][$i]];
    }

    /** Nama komponen yang selisihnya dikunci (nomor konflik), atau null bila harus sama dengan Excel. */
    private static function ciTerkunci(string $varian, int $skala, string $komponen): ?string
    {
        if ($komponen === self::STEM && $skala === self::SKALA_STEM_RUSAK) {
            return 'B7-3';
        }

        if ($komponen === self::TEGANGAN && $varian === 'berat' && $skala >= 1) {
            return 'B7-2';
        }

        return null;
    }

    // ------------------------------------------------------------------ pra-olah

    #[DataProvider('varian')]
    public function test_praolah_sama_dengan_sel_master(string $varian): void
    {
        $p = $this->hitung($varian)['praolah'];
        $x = self::w($varian)['praolah'];

        // kunci aplikasi => kunci fixture
        $peta = [
            'suhu_ruang' => 'suhu_ruang',
            'rho_udara' => 'rho_udara',
            'f_press' => 'f_press',
            'f_temp' => 'f_temp',
            'm_air' => 'm_air',
            'rho_air_titik1' => 'rho_air_titik1',
            'rho_air_maks' => 'rho_air_maks',
            'pid_yx' => 'pid_yx',
            'pid_yl' => 'pid_yl',
            'tegangan_hydrometer' => 'tegangan_hydrometer',
            'diameter' => 'diameter_rata',
            'st_maks' => 'st_maks',
            'st_min' => 'st_min',
        ];

        foreach ($peta as $kunciApp => $kunciExcel) {
            $this->assertSamaExcel($x[$kunciExcel], (float) $p[$kunciApp], "{$varian} praolah {$kunciApp}");
        }
    }

    // ------------------------------------------------------------------ rantai per titik

    #[DataProvider('varian')]
    public function test_densitas_koreksi_dan_simpangan_baku_sama_dengan_sel_master(string $varian): void
    {
        $w = self::w($varian);
        $hasil = $this->hitung($varian);
        $p = $hasil['praolah'];

        foreach ($hasil['titik'] as $i => $t) {
            $x = $w['titik'][$i];
            $ke = "{$varian} titik ".($i + 1);

            $this->assertSame($x['titik_ke'], $t['titik_ke'], "{$ke}: nomor titik");
            $this->assertSamaExcel($x['titik_ukur'], (float) $t['titik_ukur'], "{$ke} titik skala");

            $this->assertSamaExcel($x['suhu_rata'], (float) $p['suhu_rata_titik'][$i], "{$ke} suhu rata-rata");
            $this->assertSamaExcel($x['tegangan_permukaan_titik'], (float) $p['st_per_titik'][$i], "{$ke} tegangan permukaan titik");
            $this->assertSamaExcel($x['rho_air_titik'], (float) $p['rho_air_per_titik'][$i], "{$ke} densitas cairan acuan");

            // Rata-rata massa terkoreksi di cairan (`PERHITUNGAN!G44:I44`); aplikasi tidak mengekspos per ulangan.
            $this->assertSamaExcel($x['massa_terkoreksi_rata'], (float) $t['massa_di_cairan'], "{$ke} massa di cairan");

            $this->assertCount(3, $t['densitas_per_ulangan'], "{$ke}: jumlah ulangan");
            foreach ($t['densitas_per_ulangan'] as $u => $d) {
                $this->assertSamaExcel(self::elemen($x['densitas_per_ulangan'], $u), (float) $d, "{$ke} densitas ulangan ".($u + 1));
            }

            $this->assertSamaExcel($x['densitas_rata'], (float) $t['densitas'], "{$ke} densitas rata-rata");
            $this->assertSamaExcel($x['simpangan_baku'], (float) $t['simpangan_baku'], "{$ke} simpangan baku");
            $this->assertSamaExcel($w['sertifikat']['titik'][$i]['koreksi'], (float) $t['koreksi'], "{$ke} koreksi");
        }
    }

    // ------------------------------------------------------------------ budget

    #[DataProvider('varian')]
    public function test_sebelas_komponen_budget_sama_dengan_master_kecuali_selisih_terkunci(string $varian): void
    {
        $w = self::w($varian);

        foreach ($this->hitung($varian)['titik'] as $i => $t) {
            $komponenExcel = $w['titik'][$i]['komponen'];
            $nama = array_keys($komponenExcel);
            $ke = "{$varian} titik ".($i + 1);

            $this->assertCount(11, $komponenExcel, "{$ke}: fixture");
            $this->assertCount(11, $t['komponen_budget'], "{$ke}: jumlah komponen aplikasi");

            foreach ($t['komponen_budget'] as $j => $c) {
                $this->assertSame($nama[$j], $c['sumber'], "{$ke}: urutan komponen ke-".($j + 1));
                $e = $komponenExcel[$nama[$j]];

                $this->assertSamaExcel($e['ui'], (float) $c['u'], "{$ke} {$nama[$j]} ui");
                $this->assertSamaExcel($e['vi'], (float) $c['vi'], "{$ke} {$nama[$j]} vi");

                // ci yang selisihnya dikunci di tes B7-2 / B7-3 di bawah; sisanya wajib sama.
                if (self::ciTerkunci($varian, $i, $nama[$j]) === null) {
                    $this->assertSamaExcel($e['ci'], (float) $c['ci'], "{$ke} {$nama[$j]} ci");
                }
            }
        }
    }

    #[DataProvider('varian')]
    public function test_uc_veff_k_dan_u_sama_dengan_master_kecuali_selisih_terkunci(string $varian): void
    {
        $w = self::w($varian);

        foreach ($this->hitung($varian)['titik'] as $i => $t) {
            // Ringan skala 3 turun ke tes B7-3: ci stem-nya berbeda dan ui stem tidak nol.
            if ($varian === 'ringan' && $i === self::SKALA_STEM_RUSAK) {
                continue;
            }

            $a = $w['titik'][$i]['agregat'];
            $ke = "{$varian} titik ".($i + 1);

            $this->assertSamaExcel($a['uc'], (float) $t['ketidakpastian_gabungan'], "{$ke} uc");
            $this->assertSamaExcel($a['veff'], (float) $t['derajat_kebebasan_efektif'], "{$ke} veff");
            $this->assertKSamaExcel($a['k'], (float) $t['faktor_cakupan_k'], "{$ke} k");
            $this->assertUSamaExcel($a['u_hitung'], (float) $t['ketidakpastian_diperluas'], "{$ke} U");
        }
    }

    // ------------------------------------------------------------------ angka cetak

    #[DataProvider('varian')]
    public function test_cetak_nominal_aktual_koreksi_dan_k_sama_dengan_teks_sertifikat(string $varian): void
    {
        $sertifikat = self::w($varian)['sertifikat'];

        foreach ($this->hitung($varian)['titik'] as $i => $t) {
            $c = $sertifikat['titik'][$i];
            $ke = "{$varian} titik ".($i + 1);
            $titikUkur = (float) $t['titik_ukur'];

            // Nominal master `0.610`: nol di belakang TIDAK dibuang. Itu datang dari format sel master
            // (`0.000`) di peta `format_sertifikat`, bukan dari `desimalSertifikat()`.
            $this->assertSame($c['nominal']['teks'], $this->cetak($varian, $titikUkur, 'standar', $titikUkur, $c['nominal']['format']), "{$ke}: nominal vs {$c['nominal']['sel']}");
            $this->assertSame($c['aktual']['teks'], $this->cetak($varian, $titikUkur, 'uut', (float) $t['densitas'], $c['aktual']['format']), "{$ke}: aktual vs {$c['aktual']['sel']}");
            $this->assertSame($c['koreksi']['teks'], $this->cetak($varian, $titikUkur, 'koreksi', (float) $t['koreksi'], $c['koreksi']['format']), "{$ke}: koreksi vs {$c['koreksi']['sel']}");
            $this->assertSame($sertifikat['k']['teks'], $this->cetakK($varian, $titikUkur, (float) $t['faktor_cakupan_k'], $sertifikat['k']['format']), "{$ke}: k vs {$sertifikat['k']['sel']}");
        }
    }

    /**
     * Cetak U95 berat sama dengan `SERTIFIKAT!S17:S19`: master berformat `0.0000` (empat desimal), dan aplikasi
     * mengikutinya lewat peta `format_sertifikat` meski `desimalU95()` = 5. Ringan (`0.00000`) di bawah lantai CMC
     * dan dikunci di B7-1.
     */
    public function test_cetak_u95_berat_sama_dengan_sel_sertifikat_lewat_peta_format(): void
    {
        foreach ($this->hitungProfil('berat') as $i => $h) {
            $u95 = self::w('berat')['sertifikat']['titik'][$i]['u95'];
            $titikUkur = self::nilai(self::w('berat')['titik'][$i]['titik_ukur']);

            $this->assertSame('0.0000', $u95['format'], 'berat titik '.($i + 1).": format {$u95['sel']}");
            $this->assertSame($u95['teks'], $this->cetak('berat', $titikUkur, 'u95', (float) $h['ketidakpastian_diperluas'], $u95['format']), 'berat titik '.($i + 1).": U95 vs {$u95['sel']}");
        }
    }

    /**
     * Cetak satu sel hasil lewat jalur render sungguhan: format dari peta `format_sertifikat` paket Hydrometer
     * (`FormatSertifikatAcuan`, yang dibekukan ke snapshot), diterapkan `TataLetakSertifikat::formatAngka`, lalu
     * titik desimal bentuk master. Ikut menegaskan format peta = format sel workbook (`$formatWorkbook`).
     */
    private function cetak(string $varian, float $titikUkur, string $kolom, float $nilai, string $formatWorkbook): string
    {
        $format = FormatSertifikatAcuan::untuk((new HydrometerProfile)->kodePaketAcuan(), $varian, $titikUkur);

        $this->assertArrayHasKey($kolom, $format, "{$varian}: peta format_sertifikat tanpa kolom {$kolom}");
        $this->assertSame($formatWorkbook, $format[$kolom], "{$varian}: format kolom {$kolom} di aplikasi vs sel workbook");

        return self::keTitik(TataLetakSertifikat::formatAngka($nilai, $format[$kolom], (new HydrometerProfile)->tandaNolDicetak()));
    }

    private function cetakK(string $varian, float $titikUkur, float $k, string $formatWorkbook): string
    {
        $format = FormatSertifikatAcuan::untuk((new HydrometerProfile)->kodePaketAcuan(), $varian, $titikUkur);

        $this->assertSame($formatWorkbook, $format['k'] ?? null, "{$varian}: format k di aplikasi vs sel workbook");

        return self::keTitik(TataLetakSertifikat::teksK(['format' => $format], $k));
    }

    // ------------------------------------------------------------------ jalur profil (simpan / hitung ulang)

    /**
     * Alat + pita CMC dari lampiran akreditasi yang SAMA dengan yang dibaca seeder produksi
     * (`database/data/kemampuan-kalibrasi.json`), bukan angka yang diketik ulang di tes.
     */
    private function alat(string $varian): Equipment
    {
        $organisasi = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => $organisasi->id,
            'kode' => 'densitas',
            'nama' => 'Densitas',
        ]);

        $lampiran = json_decode((string) file_get_contents(database_path('data/kemampuan-kalibrasi.json')), true, flags: JSON_THROW_ON_ERROR);
        $pita = 0;

        foreach ($lampiran['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                if ($alat['nama_alat'] !== 'Hydrometer') {
                    continue;
                }

                foreach ($alat['rentang'] as $r) {
                    CalibrationCapability::factory()->create([
                        'organization_id' => $organisasi->id,
                        'equipment_category_id' => $kategori->id,
                        'nama_alat' => 'Hydrometer',
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

        $this->assertSame(2, $pita, 'Lampiran akreditasi Hydrometer harus punya dua pita CMC.');

        return Equipment::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Hydrometer Uji Paritas',
            'nama_alat_kemampuan' => 'Hydrometer',
            'satuan' => 'g/ml',
            'range_min' => 0.600,
            'range_max' => 2.000,
            'resolusi' => self::nilai(self::w($varian)['masukan']['resolusi']),
        ]);
    }

    /** @return list<array<string, mixed>> keluaran `hitungan` profil, urut titik. */
    private function hitungProfil(string $varian): array
    {
        $konteksSesi = [
            'spesifikasi_alat' => [
                HydrometerMentah::KUNCI_SESI => self::blokPreCondition($varian) + ['satuan_densitas' => 'g/ml'],
            ],
        ] + self::lingkungan($varian);

        $titik = [];

        foreach (self::w($varian)['titik'] as $t) {
            $titik[] = [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => self::nilai($t['titik_ukur']),
                'pembacaan' => [],
                'standard' => null,
                'konteks' => $konteksSesi + [
                    HydrometerMentah::KONTEKS_MASSA => array_map('floatval', $t['massa']['nilai']),
                    HydrometerMentah::KONTEKS_SUHU => array_map('floatval', $t['suhu']['nilai']),
                ],
            ];
        }

        $hasil = (new HydrometerProfile)->hitungPerGrup($titik, $this->alat($varian));

        $this->assertNotNull($hasil, "{$varian}: profil tidak menghitung");
        $this->assertSame([], $hasil['belum_dihitung'], "{$varian}: titik belum dihitung");
        $this->assertCount(3, $hasil['hitungan'], "{$varian}: jumlah titik profil");

        return $hasil['hitungan'];
    }

    #[DataProvider('varian')]
    public function test_jalur_profil_menghasilkan_aktual_koreksi_dan_budget_yang_sama_dengan_kalkulator(string $varian): void
    {
        $w = self::w($varian);
        $kalkulator = $this->hitung($varian)['titik'];

        foreach ($this->hitungProfil($varian) as $i => $h) {
            $c = $w['titik'][$i];
            $s = $w['sertifikat']['titik'][$i];
            $ke = "{$varian} titik ".($i + 1);

            $this->assertSamaExcel($s['aktual'], (float) $h['rata_rata'], "{$ke} profil: aktual");
            $this->assertSamaExcel($s['koreksi'], (float) $h['koreksi'], "{$ke} profil: koreksi");
            $this->assertSame((float) $kalkulator[$i]['faktor_cakupan_k'], (float) $h['faktor_cakupan_k'], "{$ke} profil: k = kalkulator");
            $this->assertSame((float) $kalkulator[$i]['ketidakpastian_gabungan'], (float) $h['ketidakpastian_gabungan'], "{$ke} profil: uc = kalkulator");

            if ($varian === 'ringan' && $i === self::SKALA_STEM_RUSAK) {
                continue;
            }

            $this->assertSamaExcel($c['agregat']['uc'], (float) $h['ketidakpastian_gabungan'], "{$ke} profil: uc");
            $this->assertKSamaExcel($c['agregat']['k'], (float) $h['faktor_cakupan_k'], "{$ke} profil: k");
        }
    }

    /**
     * Berat: titik 1,8-2,0 g/mL jatuh di luar kedua pita (0,6-1,0 dan 1,1-1,7), jadi aplikasi tidak memakai
     * lantai CMC dan U95-nya = U hitung — sama dengan master, yang lantai 0,0007-nya di bawah U hitung.
     */
    public function test_u95_berat_sama_dengan_sel_sertifikat_karena_tanpa_lantai_cmc(): void
    {
        foreach ($this->hitungProfil('berat') as $i => $h) {
            $this->assertUSamaExcel(self::w('berat')['sertifikat']['titik'][$i]['u95'], (float) $h['ketidakpastian_diperluas'], 'berat titik '.($i + 1).' U95');
        }
    }

    // ------------------------------------------------------------------ selisih terkunci (menunggu keputusan TM)

    /**
     * B7-3 (kedua varian): `NILAI U95%!J139` (ci Stem Diameter skala 3) = `C57`, milik skala 1, seharusnya
     * `C126`. Aplikasi memakai C126 dan menulis `hydrometer_ci_stem_per_skala` di jejak audit
     * (`docs/pertanyaan-lab-hydrometer.md` §14). Dikunci apa adanya.
     */
    #[Group('menunggu-keputusan-tm')]
    #[DataProvider('varian')]
    public function test_b7_3_ci_stem_skala_3_aplikasi_pakai_c126_bukan_j139(string $varian): void
    {
        $x = self::w($varian)['titik'][self::SKALA_STEM_RUSAK];
        $t = $this->hitung($varian)['titik'][self::SKALA_STEM_RUSAK];

        $indeks = array_search(self::STEM, array_keys($x['komponen']), true);
        $ciApp = (float) $t['komponen_budget'][$indeks]['ci'];
        $c126 = $x['ci_turunan'][self::STEM];
        $j139 = $x['komponen'][self::STEM]['ci'];

        // Aplikasi = C126 (rujukan yang benar).
        $this->assertSamaExcel($c126, $ciApp, "SELISIH B7-3 {$varian}: ci stem aplikasi vs {$c126['sel']}");

        // Master mencetak J139 = C57; selisihnya sebesar yang tercatat di fixture, dan TIDAK nol.
        // Penyebutnya nilai C126 (definisi yang sama dengan generator: |J139 - C126| / |C126|).
        $selisihApp = abs(self::nilai($j139) - $ciApp) / abs($ciApp);
        $this->assertGreaterThan(0.05, $selisihApp, "SELISIH B7-3 {$varian}: ci stem aplikasi harus berbeda dari {$j139['sel']}");
        $this->assertSamaHitungUlang((float) $c126['selisih_relatif_terhadap_ci_komponen'], $selisihApp, "SELISIH B7-3 {$varian}: selisih relatif terhadap {$j139['sel']}", rel: 1e-9);
    }

    /**
     * B7-3 dampak hilir, ringan: ui stem skala 3 TIDAK nol (diameter 0,708/0,710/0,709), jadi uc, veff, k, dan
     * U ikut berbeda dari master. Aplikasi sama dengan skenario "master memakai C126" (dihitung ulang, bukan cache);
     * cetaknya tertutup lantai CMC sehingga sertifikat yang sudah terbit tidak berubah.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_3_dampak_hilir_ringan_skala_3_sama_dengan_skenario_c126(): void
    {
        $x = self::w('ringan')['titik'][self::SKALA_STEM_RUSAK];
        $t = $this->hitung('ringan')['titik'][self::SKALA_STEM_RUSAK];
        $skenario = $x['skenario_ci_stem_C126'];
        $a = $x['agregat'];

        $this->assertSamaHitungUlang($skenario['uc'], (float) $t['ketidakpastian_gabungan'], 'SELISIH B7-3 ringan skala 3: uc vs skenario C126');
        $this->assertSamaHitungUlang($skenario['veff'], (float) $t['derajat_kebebasan_efektif'], 'SELISIH B7-3 ringan skala 3: veff vs skenario C126');
        $this->assertSamaHitungUlang($skenario['k'], (float) $t['faktor_cakupan_k'], 'SELISIH B7-3 ringan skala 3: k vs skenario C126', rel: 0.0, mutlak: self::TOL_K);
        $this->assertSamaHitungUlang($skenario['u_hitung'], (float) $t['ketidakpastian_diperluas'], 'SELISIH B7-3 ringan skala 3: U vs skenario C126', rel: self::TOL_K_REL);

        // Dan berbeda dari yang tersimpan di master (`NILAI U95%!K144:K146`).
        $this->assertGreaterThan(1e-3, abs((float) $t['ketidakpastian_gabungan'] - self::nilai($a['uc'])) / self::nilai($a['uc']), "SELISIH B7-3: uc harus berbeda dari {$a['uc']['sel']}");
        $this->assertGreaterThan(1e-3, abs((float) $t['derajat_kebebasan_efektif'] - self::nilai($a['veff'])) / self::nilai($a['veff']), "SELISIH B7-3: veff harus berbeda dari {$a['veff']['sel']}");
        $this->assertGreaterThan(1e-6, abs((float) $t['faktor_cakupan_k'] - self::nilai($a['k'])), "SELISIH B7-3: k harus berbeda dari {$a['k']['sel']}");
        $this->assertGreaterThan(1e-3, abs((float) $t['ketidakpastian_diperluas'] - self::nilai($a['u_hitung'])) / self::nilai($a['u_hitung']), "SELISIH B7-3: U harus berbeda dari {$a['u_hitung']['sel']}");

        // Tertutup lantai: master mencetak 0,0007 dan U hitung aplikasi di bawahnya.
        $this->assertLessThan(self::nilai($a['cmc_ketikan']), (float) $t['ketidakpastian_diperluas']);
        $this->assertLessThan(self::nilai($a['cmc_ketikan']), self::nilai($a['u_hitung']));
    }

    /**
     * B7-3 berat: ci stem skala 3 juga berbeda, tetapi ui stem = 0 (diameter 0,615 tiga kali), jadi uc, veff,
     * k, dan U sama dengan master. Selisihnya hanya di kolom ci.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_3_berat_ui_stem_nol_sehingga_u_tidak_berubah(): void
    {
        $x = self::w('berat')['titik'][self::SKALA_STEM_RUSAK];
        $t = $this->hitung('berat')['titik'][self::SKALA_STEM_RUSAK];
        $indeks = array_search(self::STEM, array_keys($x['komponen']), true);

        $this->assertSamaExcel($x['komponen'][self::STEM]['ui'], (float) $t['komponen_budget'][$indeks]['u'], 'berat skala 3: ui stem');
        $this->assertSame(0.0, self::nilai($x['komponen'][self::STEM]['ui']), 'berat skala 3: ui stem di master harus nol');
        $this->assertSame(0.0, (float) $t['komponen_budget'][$indeks]['u'], 'berat skala 3: ui stem di aplikasi harus nol');
    }

    /**
     * B7-2: tiga koefisien (massa di udara, massa di cairan, tegangan permukaan) memakai penyebut `$O$55` (skala 1).
     * Master ringan sama (O55 di semua skala), jadi tidak ada selisih. Master berat memperbaiki koefisien tegangan
     * permukaan jadi per skala (`F92`, `F126` memakai O90, O124), aplikasi tetap O55 (DITIRU dari master ringan,
     * `docs/pertanyaan-lab-hydrometer.md` §8). U tidak berubah karena ui tegangan berat = 0.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_2_ci_tegangan_permukaan_berat_skala_2_dan_3_memakai_penyebut_skala_1(): void
    {
        $w = self::w('berat');
        $hasil = $this->hitung('berat')['titik'];

        foreach ([1, 2] as $i) {
            $x = $w['titik'][$i];
            $indeks = array_search(self::TEGANGAN, array_keys($x['komponen']), true);
            $ciApp = (float) $hasil[$i]['komponen_budget'][$indeks]['ci'];
            $turunan = $x['ci_turunan'][self::TEGANGAN];
            $ciExcel = $x['komponen'][self::TEGANGAN]['ci'];
            $ke = 'berat skala '.($i + 1);

            $this->assertTrue($turunan['master_memakai_penyebut_skala_sendiri'], "{$ke}: master berat memakai penyebut skala sendiri");

            // Aplikasi = skenario "penyebut O55" (dihitung ulang dari sel master, bukan cache).
            $this->assertSamaHitungUlang((float) $turunan['skenario_ci_penyebut_skala1']['nilai'], $ciApp, "SELISIH B7-2 {$ke}: ci tegangan aplikasi vs skenario O55");

            // Dan berbeda dari yang dicetak master berat.
            $this->assertGreaterThan(0.05, abs($ciApp - self::nilai($ciExcel)) / abs(self::nilai($ciExcel)), "SELISIH B7-2 {$ke}: ci tegangan aplikasi harus berbeda dari {$ciExcel['sel']}");

            // Dampak hilir nol: ui tegangan = 0 di kedua sisi.
            $this->assertSame(0.0, self::nilai($x['komponen'][self::TEGANGAN]['ui']), "{$ke}: ui tegangan di master harus nol");
            $this->assertSame(0.0, (float) $hasil[$i]['komponen_budget'][$indeks]['u'], "{$ke}: ui tegangan di aplikasi harus nol");
        }
    }

    /** B7-2 ringan: master ringan sudah memakai O55 di semua skala, jadi tidak ada selisih (ci sama persis di tes budget). */
    public function test_b7_2_master_ringan_memakai_penyebut_skala_1_di_semua_skala(): void
    {
        foreach (self::w('ringan')['titik'] as $i => $x) {
            $this->assertFalse($x['ci_turunan'][self::TEGANGAN]['master_memakai_penyebut_skala_sendiri'], 'ringan skala '.($i + 1));
        }
    }

    /**
     * B7-1: master mengetik 0,0007 di `NILAI U95%!L79/L113/L147` untuk SEMUA skala. Aplikasi memakai pita akreditasi
     * yang memuat titiknya: 0,00051 untuk 0,60-1,00 g/mL. Hydrometer ringan (0,61-0,65) tercetak 0,00051 di aplikasi,
     * 0,00070 di sertifikat master. Disengaja dan tercatat (`docs/pertanyaan-lab-hydrometer.md` §7,
     * jejak `hydrometer_cmc_pita_akreditasi`); dikunci apa adanya.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_b7_1_lantai_cmc_ringan_aplikasi_0_00051_master_0_0007(): void
    {
        $w = self::w('ringan');
        $kalkulator = $this->hitung('ringan')['titik'];

        foreach ($this->hitungProfil('ringan') as $i => $h) {
            $u95 = $w['sertifikat']['titik'][$i]['u95'];
            $ke = 'ringan titik '.($i + 1);

            // Lantai pita yang mengikat: U hitung di bawahnya, jadi hasil = lantai.
            $this->assertLessThan(0.00051, (float) $kalkulator[$i]['ketidakpastian_diperluas'], "SELISIH B7-1 {$ke}: U hitung di bawah kedua lantai");
            $this->assertEqualsWithDelta(0.00051, (float) $h['ketidakpastian_diperluas'], 1e-15, "SELISIH B7-1 {$ke}: U95 aplikasi = pita 0,60-1,00");
            $this->assertEqualsWithDelta(0.0007, self::nilai($u95), 1e-15, "SELISIH B7-1 {$ke}: U95 master {$u95['sel']}");

            $titikUkur = self::nilai($w['titik'][$i]['titik_ukur']);
            $this->assertSame('0.00051', $this->cetak('ringan', $titikUkur, 'u95', (float) $h['ketidakpastian_diperluas'], $u95['format']), "SELISIH B7-1 {$ke}: cetak aplikasi");
            $this->assertSame('0.00070', $u95['teks'], "SELISIH B7-1 {$ke}: cetak master");
        }
    }
}
