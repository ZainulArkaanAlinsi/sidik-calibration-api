<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\BuretProfile;
use App\Services\Calibration\Profiles\GelasUkurProfile;
use App\Services\Calibration\Profiles\LabuUkurProfile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\Profiles\PipetUkurProfile;
use App\Services\Calibration\Profiles\PipetVolumeProfile;
use App\Services\Calibration\Profiles\VolumetricGlasswareProfile;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Rekonsiliasi **workbook lab Rev.7** (`SIDIK-IK-CAL-0510_Rev.7`, template
 * Fixed) — Labu Ukur LU-200/250/500-1 dan Pipet Volume PV 0,5/2/3/4-1-26
 * (berkasnya bernama "Pipet Ukur", tapi `INPUT DATA!E6 = 5` = Pipet Volume).
 *
 * Keputusan pemilik 8 Okt 2026: workbook lab adalah ACUAN, server yang
 * disesuaikan. Masukan di bawah PERSIS sel `INPUT DATA` ketujuh workbook
 * (`H33/J33/L33`, `H34/J34/L34`, `H39:M39`, `E22:F25`); suhu awal = akhir di
 * ketujuhnya, jadi tiga bacaan server setara enam sel workbook. Nama pelanggan
 * sengaja tidak disalin.
 *
 * Yang diadu, toleransi 1e-9 relatif: V20, deviasi, ketujuh u_i·c_i, u_c,
 * ν_eff, k, U, dan U cetak sesudah lantai CMC.
 *
 *  - Cabang `master` sakelar butir D (ρ air ulangan 1 & 2 pada 25,5 °C,
 *    `PERHITUNGAN!H40`/`J40`) diadu ke **nilai cache Excel**.
 *  - Cabang `ukur` diadu ke replika Python rumus workbook yang sama dengan
 *    `H40`/`J40` dihitung dari suhu terkoreksi (hitungan benar). Replika itu
 *    memulangkan cache Excel dengan selisih ≤ 3·10⁻¹¹ relatif saat `H40`/`J40`
 *    diisi 25,5.
 */
class VolumetrikRev7WorkbookTest extends TestCase
{
    private const TOLERANSI_RELATIF = 1e-9;

    /** @return array<string, array<string, mixed>> */
    private static function kasus(): array
    {
        return [
            'LU-200' => [
                'nominal' => 200.0, 'kelas' => 'A', 'toleransi_ml' => 0.15, 'neraca' => 'Electronic Balance Fujitsu',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [199.026, 199.027, 199.031], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.044,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 199.85125467654402, 'deviasi' => -0.14874532345598368, 'uici' => [0.0002898530891816235, 0.011989369920047782, -0.0043928174154384755, 0.0017047085010760959, -0.002018206162966927, 0.00011538417473779574, 0.0073421633732844716],
                    'uc' => 0.01496748858145858, 'veff' => 104.98943882312507, 'k' => 1.9830375264837292, 'u' => 0.029681091534249082, 'u95' => 0.044],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 199.85388300835908, 'deviasi' => -0.1461169916409233, 'uici' => [0.0002898569011671786, 0.011989707696750057, -0.00439294117435076, 0.0017047309204479236, -0.0020182327052844927, 0.00011538569220586424, 0.0073421633732844716],
                    'uc' => 0.014967801690954656, 'veff' => 104.98783696778746, 'k' => 1.9830375264229898, 'u' => 0.029681712441220566, 'u95' => 0.044],
            ],
            'LU-250' => [
                'nominal' => 250.0, 'kelas' => 'A', 'toleransi_ml' => 0.15, 'neraca' => 'Electronic Balance Fujitsu',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [248.823, 248.833, 248.831], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.044,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 249.85825034623147, 'deviasi' => -0.1417496537685281, 'uici' => [0.0002898530891816235, 0.01498936294308122, -0.005491992908867798, 0.0021312624937911435, -0.0025232038774689863, 0.00014425572691194693, 0.0073421633732844716],
                    'uc' => 0.017881946558002043, 'veff' => 94.3254808757702, 'k' => 1.985523441866606, 'u' => 0.035505024077118925, 'u95' => 0.044],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 249.86153634205726, 'deviasi' => -0.13846365794273652, 'uici' => [0.0002898569011671786, 0.014989785238632855, -0.005492147634868085, 0.002131290522962278, -0.002523237061233771, 0.0001442576240825059, 0.0073421633732844716],
                    'uc' => 0.0178823561643659, 'veff' => 94.32406931366022, 'k' => 1.985523441866604, 'u' => 0.035505837360156264, 'u95' => 0.044],
            ],
            'LU-500' => [
                'nominal' => 500.0, 'kelas' => 'A', 'toleransi_ml' => 0.25, 'neraca' => 'Electronic Balance Fujitsu',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [498.184, 498.184, 498.184], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.077,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 500.2446764263289, 'deviasi' => 0.2446764263289083, 'uici' => [0.0002898530891816235, 0.030010411923192138, -0.010995595349864348, 0.004267030266596126, -0.0050517415594364375, 0.00028881639622351646, 0.0119876679767515],
                    'uc' => 0.03477250629767554, 'veff' => 86.52544920093679, 'k' => 1.987934206239018, 'u' => 0.06912545470581087, 'u95' => 0.077],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 500.251255364252, 'deviasi' => 0.25125536425201744, 'uici' => [0.0002898569011671786, 0.030011257406986614, -0.010995905128940447, 0.004267086384189302, -0.005051807997113217, 0.00028882019457506615, 0.0119876679767515],
                    'uc' => 0.03477335055256655, 'veff' => 86.52434535748169, 'k' => 1.9879342062390202, 'u' => 0.06912713302898758, 'u95' => 0.077],
            ],
            'PV-0.5' => [
                'nominal' => 0.5, 'kelas' => 'A', 'toleransi_ml' => 0.005, 'neraca' => 'Analytical Balance',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [0.4927, 0.4935, 0.4929], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.002,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 0.49507270466479786, 'deviasi' => -0.004927295335202142, 'uici' => [2.8985308918162353e-05, 2.970013775070622e-05, -1.0881913163265217e-05, 4.222913934959994e-06, -4.999512188644112e-06, 2.8583035695927813e-07, 0.0003272248120686042],
                    'uc' => 0.0003300903661213666, 'veff' => 51.762147902358954, 'k' => 2.007583770315835, 'u' => 0.0006626840617628675, 'u95' => 0.002],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 0.495079215583844, 'deviasi' => -0.00492078441615601, 'uici' => [2.8985690116717862e-05, 2.970097449314169e-05, -1.0882219739571333e-05, 4.222969472359874e-06, -4.999577939429448e-06, 2.8583411604011126e-07, 0.0003272248120686042],
                    'uc' => 0.00033009048669880973, 'veff' => 51.762222666889514, 'k' => 2.007583770315836, 'u' => 0.0006626843038321857, 'u95' => 0.002],
            ],
            'PV-2' => [
                'nominal' => 2.0, 'kelas' => 'A', 'toleransi_ml' => 0.01, 'neraca' => 'Analytical Balance',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [1.9816, 1.9827, 1.9815], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.0034,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 1.9901313551456667, 'deviasi' => -0.009868644854333253, 'uici' => [2.8985308918162353e-05, 0.00011939089922124878, -4.3743951920858854e-05, 1.6975594398272687e-05, -2.0097423819376757e-05, 1.1490028641798907e-06, 0.0006365474356653112],
                    'uc' => 0.0006503029321697772, 'veff' => 54.394979067306, 'k' => 2.0048792881880577, 'u' => 0.0013037788797551496, 'u95' => 0.0034],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 1.9901575282390773, 'deviasi' => -0.009842471760922678, 'uici' => [2.8985690116717862e-05, 0.00011939426282288003, -4.3745184319885894e-05, 1.6975817651786453e-05, -2.0097688129443317e-05, 1.1490179752222928e-06, 0.0006365474356653112],
                    'uc' => 0.0006503036636258695, 'veff' => 54.3952160715712, 'k' => 2.0048792881880564, 'u' => 0.0013037803462363185, 'u95' => 0.0034],
            ],
            'PV-3' => [
                'nominal' => 3.0, 'kelas' => 'A', 'toleransi_ml' => 0.015, 'neraca' => 'Analytical Balance',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [2.9724, 2.9753, 2.9731], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.0034,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 2.9858999281818193, 'deviasi' => -0.014100071818180737, 'uici' => [2.8985308918162353e-05, 0.00017912851656176057, -6.563137782898813e-05, 2.5469387215868506e-05, -3.0153233947979442e-05, 1.7239101131514631e-06, 0.0009281944807707777],
                    'uc' => 0.000948862753266517, 'veff' => 54.52740882075068, 'k' => 2.0048792881880577, 'u' => 0.0019023552813571352, 'u95' => 0.0034],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 2.985939197066023, 'deviasi' => -0.014060802933976824, 'uici' => [2.8985690116717862e-05, 0.00017913356315219954, -6.563322686280031e-05, 2.546972217499017e-05, -3.0153630506431083e-05, 1.7239327850521427e-06, 0.0009281944807707777],
                    'uc' => 0.0009488638671619549, 'veff' => 54.527656214529664, 'k' => 2.0048792881880564, 'u' => 0.0019023575145830265, 'u95' => 0.0034],
            ],
            'PV-4' => [
                'nominal' => 4.0, 'kelas' => 'A', 'toleransi_ml' => 0.015, 'neraca' => 'Analytical Balance',
                'kosong' => [0.0, 0.0, 0.0], 'isi' => [3.9685, 3.969, 3.9682], 'suhu' => [25.2, 25.3, 25.2],
                'lingkungan' => [20.9, 20.6, 51.0, 50.0, 1001.2, 1001.5],
                'cmc' => 0.0039,
                // Cache workbook: PERHITUNGAN!H60/H61, PERHITUNGAN U95%!I36:I42, I44, I45, I46, I47, J49.
                'master' => ['v20' => 3.984982151259336, 'deviasi' => -0.015017848740663808, 'uici' => [2.8985308918162353e-05, 0.00023906492462888448, -8.75916392048453e-05, 3.3991445091916164e-05, -4.024250710860672e-05, 2.3007304988507054e-06, 0.0009281944807707777],
                    'uc' => 0.0009643597081957916, 'veff' => 58.00048078870353, 'k' => 2.001717484145235, 'u' => 0.0019303756889007132, 'u95' => 0.0039],
                // Replika Python rumus workbook dengan H40/J40 dari suhu terkoreksi (bukan 25,5).
                'ukur' => ['v20' => 3.985034559513603, 'deviasi' => -0.014965440486396808, 'uici' => [2.8985690116717862e-05, 0.00023907165980866535, -8.759410692543736e-05, 3.399189212837195e-05, -4.024303635553051e-05, 2.3007607567701656e-06, 0.0009281944807707777],
                    'uc' => 0.0009643616513851276, 'veff' => 58.00091918639781, 'k' => 2.0017174841452356, 'u' => 0.0019303795786167826, 'u95' => 0.0039],
            ],
        ];
    }

    /** @return array<string, array{string}> */
    public static function kodeKasus(): array
    {
        $kode = array_keys(self::kasus());

        return array_combine($kode, array_map(static fn (string $k): array => [$k], $kode));
    }

    /** @return array<string, mixed> */
    private function blok(array $c): array
    {
        [$ta, $tb, $ha, $hb, $pa, $pb] = $c['lingkungan'];

        return [
            'kelas' => $c['kelas'], 'toleransi_ml' => $c['toleransi_ml'], 'resolusi_ml' => null,
            'neraca' => $c['neraca'],
            'suhu_awal' => $ta, 'suhu_akhir' => $tb,
            'kelembaban_awal' => $ha, 'kelembaban_akhir' => $hb,
            'tekanan_awal' => $pa, 'tekanan_akhir' => $pb,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function titik(array $c): array
    {
        return [[
            'titik_ke' => 1,
            'nominal' => $c['nominal'],
            'kosong' => $c['kosong'],
            'isi' => $c['isi'],
            'suhu' => $c['suhu'],
        ]];
    }

    /** @return array<string, mixed> */
    private function hitung(string $kode, string $suhuDensitasAir): array
    {
        $c = self::kasus()[$kode];
        $hasil = (new V)->hitungSesi(
            V::KELUARGA_FIXED, $this->titik($c), $this->blok($c), null, V::parameterRev7($suhuDensitasAir),
        );

        $this->assertTrue($hasil['boleh_terbit'], (string) json_encode($hasil['ditolak']));

        return $hasil['titik'][0];
    }

    private function samaRelatif(float $harap, float $nyata, string $label): void
    {
        $this->assertLessThanOrEqual(
            self::TOLERANSI_RELATIF,
            abs($nyata - $harap) / max(abs($harap), 1e-300),
            sprintf('%s: harap %.17g, server %.17g', $label, $harap, $nyata),
        );
    }

    /** @param  array<string, mixed>  $h */
    private function aduKe(string $kode, string $cabang, array $h): void
    {
        $c = self::kasus()[$kode];
        $e = $c[$cabang];

        $this->samaRelatif($e['v20'], $h['v20'], "$kode V20");
        $this->samaRelatif($e['deviasi'], $h['deviasi'], "$kode deviasi");

        // Tujuh komponen, bukan delapan — `PERHITUNGAN U95%!I36:I42`.
        $this->assertCount(7, $h['komponen_budget'], "$kode jumlah komponen budget");
        $this->assertNotContains('Repeated measurements', array_column($h['komponen_budget'], 'nama'));
        foreach ($h['komponen_budget'] as $i => $k) {
            $this->samaRelatif(
                $e['uici'][$i], $k['u'] * $k['ci'], sprintf('%s u·c baris %d (%s)', $kode, 36 + $i, $k['nama']),
            );
        }

        $a = $h['agregat'];
        $this->samaRelatif($e['uc'], $a['ketidakpastian_gabungan'], "$kode u_c");
        $this->samaRelatif($e['veff'], $a['derajat_kebebasan_efektif'], "$kode ν_eff");
        $this->samaRelatif($e['k'], $a['faktor_cakupan_k'], "$kode k");
        $this->samaRelatif($e['u'], $a['ketidakpastian_diperluas'], "$kode U");

        // U cetak = MAX(U, CMC) — `PERHITUNGAN U95%!J49`. CMC dari tabel
        // standar server, wajib sama dengan `J48` workbook.
        $jenis = str_starts_with($kode, 'LU') ? 'Labu Ukur' : 'Pipet Volume';
        $cmc = (new TabelStandarVolumetric)->cmc($jenis, $c['nominal']);
        $this->assertSame($c['cmc'], $cmc, "$kode CMC J48");
        $this->samaRelatif($e['u95'], max($a['ketidakpastian_diperluas'], $cmc), "$kode U cetak J49");
    }

    #[DataProvider('kodeKasus')]
    public function test_cabang_master_sama_dengan_cache_workbook(string $kode): void
    {
        $h = $this->hitung($kode, V::SUHU_DENSITAS_AIR_MASTER);
        $this->aduKe($kode, 'master', $h);

        // Pembanding di jejak audit = hitungan benar.
        $r = $h['pembanding_master']['rev7'];
        $this->assertSame(V::SUHU_DENSITAS_AIR_UKUR, $r['suhu_densitas_air_lain']);
        $this->samaRelatif(self::kasus()[$kode]['ukur']['v20'], $r['v20_lain'], "$kode V20 pembanding ukur");
        $this->samaRelatif(self::kasus()[$kode]['ukur']['u'], $r['u95_lain'], "$kode U pembanding ukur");
    }

    #[DataProvider('kodeKasus')]
    public function test_cabang_ukur_sama_dengan_hitungan_benar(string $kode): void
    {
        $h = $this->hitung($kode, V::SUHU_DENSITAS_AIR_UKUR);
        $this->aduKe($kode, 'ukur', $h);

        // Pembanding di jejak audit = angka workbook kalau master ditiru.
        $r = $h['pembanding_master']['rev7'];
        $this->assertSame(V::SUHU_DENSITAS_AIR_MASTER, $r['suhu_densitas_air_lain']);
        $this->samaRelatif(self::kasus()[$kode]['master']['v20'], $r['v20_lain'], "$kode V20 pembanding master");
        $this->samaRelatif(self::kasus()[$kode]['master']['u'], $r['u95_lain'], "$kode U pembanding master");
        // Rev.7 `I45 = I44^4/K43` dan `K43` = SUM — pembanding K4 lama tidak berlaku.
        $this->assertNull($h['pembanding_master']['veff_dibagi_baris_akhir']);
    }

    /**
     * Butir D: keputusan pemilik 8 Okt 2026 (terakhir) — angka ikut workbook,
     * `master` (25,5 °C di `H40`/`J40`). `ukur` tetap dihitung sebagai
     * pembanding dan memicu peringatan kalau menggeser angka cetak.
     */
    public function test_sakelar_suhu_densitas_air_bawaan_master(): void
    {
        $this->assertSame(V::SUHU_DENSITAS_AIR_MASTER, V::SUHU_DENSITAS_AIR_REV7);
        $this->assertSame(V::SUHU_DENSITAS_AIR_MASTER, V::parameterRev7()['suhu_densitas_air']);
    }

    /**
     * Parameter BAWAAN (tanpa memilih cabang) = cache workbook, dan angka
     * CETAK-nya — desimal sertifikat profil per nominal — sama dengan teks
     * `SERTIFIKAT!N21`, `S21`, `Q22` ketujuh workbook (format selnya sendiri).
     */
    #[DataProvider('kodeKasus')]
    public function test_bawaan_sama_dengan_workbook_sampai_angka_cetak(string $kode): void
    {
        $c = self::kasus()[$kode];
        $hasil = (new V)->hitungSesi(V::KELUARGA_FIXED, $this->titik($c), $this->blok($c), null, V::parameterRev7());
        $h = $hasil['titik'][0];
        $this->aduKe($kode, 'master', $h);

        // [N21 Actual, S21 Correction (= V20 − Nominal), Q22 U95].
        $cetakWorkbook = [
            'LU-200' => ['199.85', '-0.15', '0.044'],
            'LU-250' => ['249.86', '-0.14', '0.044'],
            'LU-500' => ['500.24', '0.24', '0.077'],
            'PV-0.5' => ['0.495', '-0.005', '0.0020'],
            'PV-2' => ['1.99', '-0.01', '0.0034'],
            'PV-3' => ['2.986', '-0.014', '0.0034'],
            'PV-4' => ['3.9850', '-0.0150', '0.0039'],
        ][$kode];

        $profil = str_starts_with($kode, 'LU') ? new LabuUkurProfile : new PipetVolumeProfile;
        $d = $profil->desimalSertifikatTitik($c['nominal']) ?? $profil->desimalSertifikat();
        $u95 = max($h['agregat']['ketidakpastian_diperluas'], $c['cmc']);

        $this->assertSame($cetakWorkbook, [
            number_format($h['v20'], $d, '.', ''),
            number_format($h['deviasi'], $d, '.', ''),
            number_format($u95, $profil->desimalU95(), '.', ''),
        ], "$kode angka cetak");
    }

    /**
     * Hanya Labu Ukur & Pipet Volume yang ikut Rev.7. Profil lain yang berbagi
     * kalkulator tidak menerima parameter apa pun — angkanya tidak bergeser.
     */
    public function test_hanya_labu_ukur_dan_pipet_volume_memakai_rev7(): void
    {
        $parameter = static fn (VolumetricGlasswareProfile $p): array => (new ReflectionMethod($p, 'parameterHitung'))->invoke($p);

        $this->assertSame(V::parameterRev7(), $parameter(new LabuUkurProfile));
        $this->assertSame(V::parameterRev7(), $parameter(new PipetVolumeProfile));

        foreach ([new PicnometerProfile, new BuretProfile, new GelasUkurProfile, new PipetUkurProfile] as $p) {
            $this->assertSame([], $parameter($p), $p::class);
        }
    }

    /** `SERTIFIKAT!N21/S21` & `Q22` workbook Rev.7 (PV per nominal); `V23` (k) format `0` tetap. */
    public function test_desimal_cetak_mengikuti_workbook(): void
    {
        $labu = new LabuUkurProfile;
        $this->assertSame(2, $labu->desimalSertifikat());
        $this->assertSame(3, $labu->desimalU95());
        $this->assertSame(0, $labu->desimalFaktorCakupan());

        $pipet = new PipetVolumeProfile;
        $this->assertSame(3, $pipet->desimalSertifikat());
        $this->assertSame(4, $pipet->desimalU95());
        $this->assertSame(0, $pipet->desimalFaktorCakupan());
        // Per nominal — `SERTIFIKAT!N21/S21` keempat workbook PV.
        $this->assertSame(3, $pipet->desimalSertifikatTitik(0.5));
        $this->assertSame(2, $pipet->desimalSertifikatTitik(2.0));
        $this->assertSame(3, $pipet->desimalSertifikatTitik(3.0));
        $this->assertSame(4, $pipet->desimalSertifikatTitik(4.0));
        $this->assertNull($pipet->desimalSertifikatTitik(1.0), 'nominal lain ikut desimalSertifikat() = 3');
        $this->assertNull($labu->desimalSertifikatTitik(500.0));

        $picno = new PicnometerProfile;
        $this->assertSame(4, $picno->desimalSertifikat(), 'Picnometer tidak ikut Rev.7');
        $this->assertSame(4, $picno->desimalU95());
    }

    /** Tanpa parameter = workbook lama persis: ρ_AT 7,95, γ_A 9,9e-6, delapan komponen. */
    public function test_tanpa_parameter_perilaku_lama_utuh(): void
    {
        $c = self::kasus()['LU-200'];
        $h = (new V)->hitungSesi(V::KELUARGA_FIXED, $this->titik($c), $this->blok($c))['titik'][0];

        $this->assertCount(8, $h['komponen_budget']);
        $this->assertSame(7.95, $h['masukan_budget']['rho_anak_timbangan']);
        $this->assertSame(V::GAMMA_KELAS_A, $h['masukan_budget']['gamma']);
        $this->assertNotNull($h['pembanding_master']['veff_dibagi_baris_akhir']);
        $this->assertArrayNotHasKey('rev7', $h['pembanding_master']);
    }

    public function test_parameter_rev7_ditolak_untuk_graduated(): void
    {
        $c = self::kasus()['LU-200'];
        $this->expectException(InvalidArgumentException::class);
        (new V)->hitungSesi(V::KELUARGA_GRADUATED, $this->titik($c), $this->blok($c), null, V::parameterRev7());
    }

    public function test_sakelar_suhu_densitas_air_asing_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new V)->hitungSesi(V::KELUARGA_FIXED, [], [], null, V::parameterRev7('tebakan'));
    }
}
