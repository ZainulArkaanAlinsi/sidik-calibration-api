<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\BuretProfile;
use App\Services\Calibration\Profiles\GelasUkurProfile;
use App\Services\Calibration\Profiles\LabuUkurProfile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\Profiles\PipetUkurProfile;
use App\Services\Calibration\Profiles\PipetVolumeProfile;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use App\Support\VolumetricGlasswareMentah as M;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §49 tahap 2 — **enam bacaan suhu air** Labu Ukur & Pipet Volume (workbook
 * Rev.7 `INPUT DATA!H39:M39`: X1 awal, X1 akhir, X2 awal, X2 akhir, X3 awal,
 * X3 akhir).
 *
 * Yang dijaga:
 *
 *  - rekonsiliasi enam bacaan awal ≠ akhir ke angka REPLIKA INDEPENDEN rumus
 *    workbook (bukan dihitung dari kode ini) — [harapanReplika];
 *  - enam bacaan KEMBAR (awal = akhir — persis isi ketujuh workbook) memberi
 *    angka yang IDENTIK bit per bit dengan payload tiga bacaan, di kedua
 *    cabang sakelar suhu densitas air;
 *  - bentuk rantai sel: tiap bacaan dikoreksi sendiri, ρ air ulangan dari
 *    RATA-RATA suhu (`L40`), rentang `O35 − P35` tanpa `M39`;
 *  - hanya profil Rev.7 yang menerima enam; profil lain dan panjang lain
 *    ditolak dengan alasan yang kebaca.
 *
 * Bentuk lembar & jalur HTTP: `tests/Feature/VolumetrikEnamSuhuAirTest.php`.
 */
class VolumetrikEnamSuhuAirTest extends TestCase
{
    /**
     * Ketujuh kasus workbook — sumbernya SATU: `VolumetrikRev7WorkbookTest`
     * (masukan persis sel `INPUT DATA` ketujuh workbook).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function kasus(): array
    {
        return (new ReflectionMethod(VolumetrikRev7WorkbookTest::class, 'kasus'))->invoke(null);
    }

    /** @return array<string, array{string, string}> */
    public static function kasusDanCabang(): array
    {
        $hasil = [];
        foreach (array_keys(self::kasus()) as $kode) {
            foreach ([V::SUHU_DENSITAS_AIR_MASTER, V::SUHU_DENSITAS_AIR_UKUR] as $cabang) {
                $hasil["$kode $cabang"] = [$kode, $cabang];
            }
        }

        return $hasil;
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

    /**
     * @param  list<float>  $suhu
     * @return array<string, mixed>
     */
    private function hitung(array $c, array $suhu, array $parameter): array
    {
        return (new V)->hitungSesi(V::KELUARGA_FIXED, [[
            'titik_ke' => 1,
            'nominal' => $c['nominal'],
            'kosong' => $c['kosong'],
            'isi' => $c['isi'],
            'suhu' => $suhu,
        ]], $this->blok($c), null, $parameter);
    }

    /** @param  list<float>  $tiga */
    private static function kembar(array $tiga): array
    {
        return [$tiga[0], $tiga[0], $tiga[1], $tiga[1], $tiga[2], $tiga[2]];
    }

    /**
     * Sidik jari angka satu titik — semua nilai yang sampai ke
     * `uncertainty_calculations` dan jejak audit.
     *
     * @param  array<string, mixed>  $h
     * @return array<string, mixed>
     */
    private static function sidikJari(array $h): array
    {
        $r = $h['pembanding_master']['rev7'];

        return [
            'v20' => $h['v20'],
            'deviasi' => $h['deviasi'],
            'stdev_v20' => $h['stdev_v20'],
            'v20_per_ulangan' => $h['v20_per_ulangan'],
            'rho_air_per_ulangan' => $h['rho_air_per_ulangan'],
            'suhu_terkoreksi_per_ulangan' => $h['suhu_terkoreksi_per_ulangan'],
            'suhu_rata_rata' => $h['suhu_rata_rata'],
            'rho_air_rata_rata' => $h['rho_air_rata_rata'],
            'rentang_suhu' => $h['rentang_suhu'],
            'masukan_budget' => $h['masukan_budget'],
            'komponen_budget' => $h['komponen_budget'],
            'agregat' => $h['agregat'],
            'u_keterulangan' => $r['u_keterulangan'],
            'u95_dengan_keterulangan' => $r['u95_dengan_keterulangan'],
            'v20_lain' => $r['v20_lain'],
            'u95_lain' => $r['u95_lain'],
        ];
    }

    #[DataProvider('kasusDanCabang')]
    public function test_enam_bacaan_kembar_identik_dengan_tiga_bacaan(string $kode, string $cabang): void
    {
        $c = self::kasus()[$kode];
        $parameter = V::parameterRev7($cabang);

        $tiga = $this->hitung($c, $c['suhu'], $parameter);
        $enam = $this->hitung($c, self::kembar($c['suhu']), $parameter);

        $this->assertTrue($tiga['boleh_terbit'], (string) json_encode($tiga['ditolak']));
        $this->assertTrue($enam['boleh_terbit'], (string) json_encode($enam['ditolak']));

        // assertSame — bit per bit, bukan toleransi.
        $this->assertSame(self::sidikJari($tiga['titik'][0]), self::sidikJari($enam['titik'][0]), "$kode $cabang");

        // Dan karena identik, cabang `master` enam bacaan kembar = cache workbook.
        if ($cabang === V::SUHU_DENSITAS_AIR_MASTER) {
            $this->assertEqualsWithDelta($c['master']['v20'], $enam['titik'][0]['v20'], abs($c['master']['v20']) * 1e-9);
            $this->assertEqualsWithDelta($c['master']['u'], $enam['titik'][0]['agregat']['ketidakpastian_diperluas'], $c['master']['u'] * 1e-9);
        }

        // Pembanding `M39` hanya ada untuk enam bacaan; untuk kembar sama dengan yang dipakai.
        $r6 = $enam['titik'][0]['pembanding_master']['rev7'];
        $this->assertSame($enam['titik'][0]['rentang_suhu'], $r6['rentang_suhu_o35']);
        $this->assertSame($r6['rentang_suhu_o35'], $r6['rentang_suhu_dengan_m39']);
        $this->assertSame($enam['titik'][0]['agregat']['ketidakpastian_diperluas'], $r6['u95_rentang_dengan_m39']);
        $this->assertArrayNotHasKey('rentang_suhu_dengan_m39', $tiga['titik'][0]['pembanding_master']['rev7'], 'tiga bacaan tidak berubah');
        $this->assertCount(3, $tiga['titik'][0]['suhu_terkoreksi_per_bacaan']);
        $this->assertCount(6, $enam['titik'][0]['suhu_terkoreksi_per_bacaan']);
    }

    /**
     * Bentuk rantai sel `PERHITUNGAN!H35:P40` dengan awal ≠ akhir — relasi,
     * bukan angka workbook. `M39` sengaja bacaan TERTINGGI supaya `O35` yang
     * melewatkannya terlihat.
     */
    public function test_rantai_sel_enam_bacaan_awal_tidak_sama_akhir(): void
    {
        $c = self::kasus()['LU-250'];
        $bacaan = [25.2, 25.3, 25.3, 25.4, 25.2, 26.0];
        $tabel = new TabelStandarVolumetric;

        foreach ([V::SUHU_DENSITAS_AIR_MASTER, V::SUHU_DENSITAS_AIR_UKUR] as $cabang) {
            $hasil = $this->hitung($c, $bacaan, V::parameterRev7($cabang));
            $this->assertTrue($hasil['boleh_terbit'], (string) json_encode($hasil['ditolak']));
            $h = $hasil['titik'][0];

            // H39:M39 — tiap bacaan dikoreksi dari titik tabelnya SENDIRI.
            $terkoreksi = array_map(static fn (float $b): float => $tabel->koreksiSuhu($b)['terkoreksi_c'], $bacaan);
            $this->assertSame($terkoreksi, $h['suhu_terkoreksi_per_bacaan']);
            $this->assertCount(6, $h['koreksi_suhu']);

            // L40 = AVERAGE(L39:M39); ρ air ulangan dari SUHU rata-rata, bukan rata-rata ρ.
            $suhuUlangan = [];
            for ($i = 0; $i < 3; $i++) {
                $suhuUlangan[] = ($terkoreksi[2 * $i] + $terkoreksi[2 * $i + 1]) / 2;
            }
            $this->assertSame($suhuUlangan, $h['suhu_terkoreksi_per_ulangan']);
            $rhoX3 = V::densitasAirSuling($suhuUlangan[2]);
            $this->assertSame($rhoX3, $h['rho_air_per_ulangan'][2]);
            $this->assertNotSame(
                (V::densitasAirSuling($terkoreksi[4]) + V::densitasAirSuling($terkoreksi[5])) / 2,
                $h['rho_air_per_ulangan'][2],
                'ρ air X3 wajib dari rata-rata suhu (L40 → L45), bukan rata-rata dua ρ',
            );

            // H40/J40: master = 25,5 angka mati; ukur = rata-rata awal/akhir ulangannya.
            $rhoX12 = $cabang === V::SUHU_DENSITAS_AIR_MASTER
                ? [V::densitasAirSuling(25.5), V::densitasAirSuling(25.5)]
                : [V::densitasAirSuling($suhuUlangan[0]), V::densitasAirSuling($suhuUlangan[1])];
            $this->assertSame($rhoX12, array_slice($h['rho_air_per_ulangan'], 0, 2));

            // N35 = AVERAGE(H39:M39) — rata-rata keenam bacaan terkoreksi.
            $this->assertEqualsWithDelta(array_sum($terkoreksi) / 6, $h['suhu_rata_rata'], 1e-12);
            $this->assertSame($h['suhu_rata_rata'], $h['masukan_budget']['suhu_air'], 'U95!J7 = N35');

            // O35 − P35: MAX lima pertama (M39 TIDAK ikut) − MIN keenamnya.
            $this->assertSame(max(array_slice($terkoreksi, 0, 5)) - min($terkoreksi), $h['rentang_suhu']);
            $r = $h['pembanding_master']['rev7'];
            $this->assertSame(max($terkoreksi) - min($terkoreksi), $r['rentang_suhu_dengan_m39']);
            $this->assertGreaterThan($h['rentang_suhu'], $r['rentang_suhu_dengan_m39'], 'M39 tertinggi tapi tidak ikut O35');
            $this->assertGreaterThan($h['agregat']['ketidakpastian_diperluas'], $r['u95_rentang_dengan_m39']);
        }
    }

    public function test_hanya_labu_ukur_dan_pipet_volume_menerima_enam_bacaan(): void
    {
        $this->assertSame([3, 6], (new LabuUkurProfile)->jumlahBacaanSuhuDiterima());
        $this->assertSame([3, 6], (new PipetVolumeProfile)->jumlahBacaanSuhuDiterima());

        foreach ([new PicnometerProfile, new BuretProfile, new GelasUkurProfile, new PipetUkurProfile] as $p) {
            $this->assertSame([3], $p->jumlahBacaanSuhuDiterima(), $p::class);
        }

        $this->assertSame([3], V::jumlahBacaanSuhuDiterima());
        $this->assertSame([3, 6], V::jumlahBacaanSuhuDiterima(V::parameterRev7()));
    }

    /** Parameter bawaan (Picnometer & Graduated): enam suhu DITOLAK, pesan lama utuh. */
    public function test_tanpa_parameter_rev7_enam_bacaan_ditolak(): void
    {
        $c = self::kasus()['LU-200'];
        $hasil = $this->hitung($c, self::kembar($c['suhu']), []);

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertSame([[
            'titik_ke' => 1,
            'alasan' => 'Titik ke-1: deret suhu berisi 6 angka, harus tepat 3.',
        ]], $hasil['ditolak']);
    }

    public function test_rev7_panjang_suhu_selain_tiga_atau_enam_ditolak(): void
    {
        $c = self::kasus()['PV-2'];

        foreach ([[25.2], [25.2, 25.3, 25.2, 25.3], [25.2, 25.2, 25.3, 25.3, 25.2], array_fill(0, 7, 25.2)] as $suhu) {
            $hasil = $this->hitung($c, $suhu, V::parameterRev7());
            $this->assertFalse($hasil['boleh_terbit'], count($suhu).' bacaan lolos');
            $this->assertStringContainsString('harus tepat 3 atau 6', $hasil['ditolak'][0]['alasan']);
        }
    }

    /**
     * Angka harapan enam bacaan awal ≠ akhir dari **replika independen** rumus
     * workbook Rev.7 (Python, tidak memakai kode PHP;
     * `_agents_workspace/volumetrik-6-suhu/01-harapan.json`, 9 Okt 2026).
     * Replika itu memulangkan cache Excel LU-250 & PV-2 dengan selisih
     * relatif ≤ 1,1·10⁻¹⁵ saat diberi enam suhu asli workbook. Suhu di bawah
     * BUKAN isi workbook — diubah supaya awal ≠ akhir dan `M39` tertinggi.
     * Masukan lain persis `INPUT DATA` workbook (LU-250-1, PV 2-1-26).
     *
     * Kunci: `n35` tair, `h21` = `O35 − P35`, `h21_m39` = rentang bila `M39`
     * ikut, `h25` u suhu (kolom U), `n45` ρ air rata-rata, `uici` tujuh
     * `PERHITUNGAN U95%!I36:I42`, `u95` = `J49` (MAX(U, CMC)).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function harapanReplika(): array
    {
        return [
            'LU-250' => [
                'suhu' => [25.2, 25.6, 25.3, 25.9, 25.4, 26.3],
                'master' => [
                    'n35' => 25.94169567372578, 'h21' => 0.6999999999999993, 'h21_m39' => 1.1000000000000014, 'h25' => 0.41476901202155064, 'n45' => 0.9968580525690797,
                    'rho_air' => [0.996917559087585, 0.996917559087585, 0.9967390395320689],
                    'v20' => 249.8726359565497, 'deviasi' => -0.1273640434503136,
                    'uici' => [0.00028986866610481674, 0.014991154247490805, -0.005492649229696937, 0.0021313770294418216, -0.00307896962873468, 0.00014426403236616074, 0.0073421633732844716],
                    'uc' => 0.01797050144552118, 'veff' => 96.09154515293555, 'k' => 1.9849843115310182, 'u' => 0.03567116343970502, 'u95' => 0.044,
                ],
                'ukur' => [
                    'n35' => 25.94169567372578, 'h21' => 0.6999999999999993, 'h21_m39' => 1.1000000000000014, 'h25' => 0.41476901202155064, 'n45' => 0.9968010716150285,
                    'rho_air' => [0.9968585224579511, 0.9968056528550652, 0.9967390395320689],
                    'v20' => 249.88693658529164, 'deviasi' => -0.11306341470836401,
                    'uici' => [0.0002898852557732373, 0.014992992223491576, -0.0054933226506556024, 0.0021314990117128714, -0.003079145843314559, 0.000144272288837942, 0.0073421633732844716],
                    'uc' => 0.017972285543971872, 'veff' => 96.08514820744885, 'k' => 1.9849843115310182, 'u' => 0.035674704847139874, 'u95' => 0.044,
                ],
            ],
            'PV-2' => [
                'suhu' => [25.2, 25.4, 25.1, 25.6, 25.3, 25.8],
                'master' => [
                    'n35' => 25.72502900705911, 'h21' => 0.5, 'h21_m39' => 0.6999999999999993, 'h25' => 0.3899145205469185, 'n45' => 0.9968846745280803,
                    'rho_air' => [0.996917559087585, 0.996917559087585, 0.9968189054090711],
                    'v20' => 1.990192723583294, 'deviasi' => -0.00980727641670609,
                    'uici' => [2.8986154399869046e-05, 0.00011939858690987997, -4.37467686337244e-05, 1.6976089564791857e-05, -2.2213256676366335e-05, 1.1490382949806324e-06, 0.0006365474356653112],
                    'uc' => 0.0006503734112023881, 'veff' => 54.41852412008214, 'k' => 2.0048792881880564, 'u' => 0.001303920181707882, 'u95' => 0.0034,
                ],
                'ukur' => [
                    'n35' => 25.72502900705911, 'h21' => 0.5, 'h21_m39' => 0.6999999999999993, 'h25' => 0.3899145205469185, 'n45' => 0.9968584676777384,
                    'rho_air' => [0.9968848164272448, 0.9968716811968997, 0.9968189054090711],
                    'v20' => 1.9902451067373406, 'deviasi' => -0.009754893262659436,
                    'uici' => [2.898691733411815e-05, 0.00011940531912135168, -4.3749235266749696e-05, 1.697653638640076e-05, -2.2213841343586033e-05, 1.1490685384084665e-06, 0.0006365474356653112],
                    'uc' => 0.0006503748787760322, 'veff' => 54.418999824175394, 'k' => 2.0048792881880564, 'u' => 0.0013039231240158848, 'u95' => 0.0034,
                ],
            ],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function kasusReplika(): array
    {
        $hasil = [];
        foreach (array_keys(self::harapanReplika()) as $kode) {
            foreach ([V::SUHU_DENSITAS_AIR_MASTER, V::SUHU_DENSITAS_AIR_UKUR] as $cabang) {
                $hasil["$kode $cabang"] = [$kode, $cabang];
            }
        }

        return $hasil;
    }

    private function samaRelatif(float $harap, float $nyata, string $label): void
    {
        $this->assertLessThanOrEqual(
            1e-9,
            abs($nyata - $harap) / max(abs($harap), 1e-300),
            sprintf('%s: harap %.17g, server %.17g', $label, $harap, $nyata),
        );
    }

    #[DataProvider('kasusReplika')]
    public function test_enam_bacaan_awal_beda_akhir_sama_dengan_replika_workbook(string $kode, string $cabang): void
    {
        $c = self::kasus()[$kode];
        $e = self::harapanReplika()[$kode][$cabang];
        $hasil = $this->hitung($c, self::harapanReplika()[$kode]['suhu'], V::parameterRev7($cabang));

        $this->assertTrue($hasil['boleh_terbit'], (string) json_encode($hasil['ditolak']));
        $h = $hasil['titik'][0];

        $this->samaRelatif($e['n35'], $h['suhu_rata_rata'], "$kode N35");
        $this->samaRelatif($e['n35'], $h['masukan_budget']['suhu_air'], "$kode U95!J7");
        $this->samaRelatif($e['h21'], $h['rentang_suhu'], "$kode H21 = O35 − P35");
        $this->samaRelatif($e['h21_m39'], $h['pembanding_master']['rev7']['rentang_suhu_dengan_m39'], "$kode rentang bila M39 ikut");
        $this->samaRelatif($e['h25'], $h['masukan_budget']['u_suhu'], "$kode H25 u suhu");
        foreach ($e['rho_air'] as $i => $rho) {
            $this->samaRelatif($rho, $h['rho_air_per_ulangan'][$i], "$kode ρ air ulangan ".($i + 1));
        }
        $this->samaRelatif($e['n45'], $h['rho_air_rata_rata'], "$kode N45");
        $this->samaRelatif($e['v20'], $h['v20'], "$kode V20 H60");
        $this->samaRelatif($e['deviasi'], $h['deviasi'], "$kode deviasi H61");

        $this->assertCount(7, $h['komponen_budget']);
        foreach ($h['komponen_budget'] as $i => $k) {
            $this->samaRelatif($e['uici'][$i], $k['u'] * $k['ci'], sprintf('%s u·c baris %d (%s)', $kode, 36 + $i, $k['nama']));
        }

        $a = $h['agregat'];
        $this->samaRelatif($e['uc'], $a['ketidakpastian_gabungan'], "$kode u_c");
        $this->samaRelatif($e['veff'], $a['derajat_kebebasan_efektif'], "$kode ν_eff");
        $this->samaRelatif($e['k'], $a['faktor_cakupan_k'], "$kode k");
        $this->samaRelatif($e['u'], $a['ketidakpastian_diperluas'], "$kode U");

        $nominal = $c['nominal'];
        $cmc = (new TabelStandarVolumetric)->cmc(str_starts_with($kode, 'LU') ? 'Labu Ukur' : 'Pipet Volume', $nominal);
        $u95 = max($a['ketidakpastian_diperluas'], $cmc);
        $this->samaRelatif($e['u95'], $u95, "$kode U cetak J49");

        // Angka CETAK persis — desimal sertifikat profil (N21/S21/Q22).
        $profil = str_starts_with($kode, 'LU') ? new LabuUkurProfile : new PipetVolumeProfile;
        $d = $profil->desimalSertifikatTitik($nominal) ?? $profil->desimalSertifikat();
        $cetak = static fn (float $v20, float $dev, float $u) => [
            number_format($v20, $d, '.', ''),
            number_format($dev, $d, '.', ''),
            number_format($u, $profil->desimalU95(), '.', ''),
        ];
        $this->assertSame($cetak($e['v20'], $e['deviasi'], $e['u95']), $cetak($h['v20'], $h['deviasi'], $u95), "$kode angka cetak");

        // Cabang lain di jejak = replika cabang lain.
        $lain = self::harapanReplika()[$kode][$cabang === V::SUHU_DENSITAS_AIR_MASTER ? V::SUHU_DENSITAS_AIR_UKUR : V::SUHU_DENSITAS_AIR_MASTER];
        $this->samaRelatif($lain['v20'], $h['pembanding_master']['rev7']['v20_lain'], "$kode V20 cabang lain");
        $this->samaRelatif($lain['u'], $h['pembanding_master']['rev7']['u95_lain'], "$kode U cabang lain");
    }

    /** Jalur hitung ulang membaca keenam baris, urut `sensor_ke` — bukan urut simpan. */
    public function test_mentah_meneruskan_enam_suhu_urut_sensor_ke(): void
    {
        $nilai = [1 => 25.1, 2 => 25.2, 3 => 25.3, 4 => 25.4, 5 => 25.5, 6 => 25.6];
        $baris = collect([6, 2, 4, 1, 5, 3])->map(static fn (int $ke): object => (object) [
            'peran_sensor' => M::PERAN_SUHU, 'sensor_ke' => $ke, 'pembacaan_ke' => $ke, 'pembacaan' => $nilai[$ke],
        ])->push((object) ['peran_sensor' => M::PERAN_KOSONG, 'sensor_ke' => 1, 'pembacaan_ke' => 1, 'pembacaan' => 0]);

        $this->assertSame([25.1, 25.2, 25.3, 25.4, 25.5, 25.6], M::dari($baris)[M::KONTEKS_SUHU]);
    }
}
