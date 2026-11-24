<?php

namespace Tests\Unit;

use App\Models\Standard;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter spectrophotometer#15 (`spectrophotometer.max_stdev_holmium`) dan
 * conductivity_meter#18 (`conductivity.nilai_acuan_larutan_1412`) pada
 * kalkulator/profil murni. Jalur penuh lewat DB (simpan, preview, validator,
 * hitung ulang): `ParameterMetodeSesiSpektroTest`.
 *
 * Angka acuan disalin dari cache sel workbook (bukan keluaran kode):
 *
 *  Spectrophotometer (`Master Olah Data_Spectrofotometer.xlsm`):
 *    PERHITUNGAN!M36 = MAX(M27:M35) = 0,15588457268125408 (M26 tidak ikut)
 *    'PERHITUNGAN U95%'!J17 uc 0,1359196779955751, J18 veff 3,4642618700507275,
 *    J19 k 3,182446305283709, J20 U95 0,43255707705236945
 *  Conductivity (`Master Olah Data_Conductivity.xlsm`):
 *    PERHITUNGAN!J26 = ((2E-13*K33^2)+(27*K33)+738)/1000, K33 = suhu larutan rata-rata
 */
class ParameterMetodeSpektroDanKonduktivitasTest extends TestCase
{
    use PenetapanParameterUji;

    private const M36 = 0.15588457268125408;

    private const J17_UC = 0.1359196779955751;

    private const J18_VEFF = 3.4642618700507275;

    private const J19_K = 3.182446305283709;

    private const J20_U95 = 0.43255707705236945;

    /** Pembacaan master Holmium (`SpectrophotometerBudgetTest::MASTER`), titik 1 diganti. */
    private const HOLMIUM = [
        [279.6, [280, 280, 280]],
        [287.7, [288, 288, 288]],
        [334.0, [333.74, 333.74, 333.74]],
        [360.9, [360.35, 360.59, 360.6]],
        [418.6, [418.6, 418.34, 418.34]],
        [445.8, [445.44, 445.69, 445.69]],
        [453.6, [453.55, 453.29, 453.29]],
        [460.0, [459.13, 459.37, 459.37]],
        [536.3, [536.63, 536.37, 536.37]],
        [637.9, [637.45, 637.18, 637.18]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ParameterKeputusanMetode::lupakan();
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    /**
     * @param  list<float>  $titik1  pembacaan titik 279,6 nm
     * @param  array<string, mixed>  $tambahan
     * @return array<string, mixed>
     */
    private function hitungHolmium(array $titik1, array $tambahan = []): array
    {
        $titik = [];

        foreach (self::HOLMIUM as $i => [$nominal, $bacaan]) {
            $titik[] = ['titik_ke' => $i + 1, 'titik_ukur' => $nominal, 'pembacaan' => $i === 0 ? $titik1 : $bacaan];
        }

        return (new SpectrophotometerCalculator)->hitungGrup($titik, [
            'grup' => SpectrophotometerCalculator::GRUP_HOLMIUM,
            'satuan' => 'nm',
            'nama_standar' => 'Filter uji',
            'u_standar' => 0.1,
            'k_standar' => 2.0,
            'resolusi' => 0.01,
            'cmc' => 0.4,
            ...$tambahan,
        ]);
    }

    public function test_tanpa_titik_pertama_cocok_workbook_walau_stdev_titik_pertama_terbesar(): void
    {
        // STDEV titik 279,6 nm = 2,0 (jauh di atas titik lain). Workbook tidak membaca M26.
        $hasil = $this->hitungHolmium([278.0, 280.0, 282.0], ['stdev_maks_kecualikan_nm' => [279.6]]);

        $this->assertEqualsWithDelta(self::M36, $hasil['standar_deviasi_maks'], 1e-12);
        $this->assertEqualsWithDelta(self::J17_UC, $hasil['ketidakpastian_gabungan'], 1e-12);
        $this->assertEqualsWithDelta(self::J18_VEFF, $hasil['derajat_kebebasan_efektif'], self::J18_VEFF * 1e-12);
        $this->assertEqualsWithDelta(self::J19_K, $hasil['faktor_cakupan_k'], 1e-12);
        $this->assertEqualsWithDelta(self::J20_U95, $hasil['ketidakpastian_diperluas'], 1e-12);
        $this->assertSame(3, $hasil['jumlah_pengulangan']);

        $kode = array_column($hasil['catatan_audit'], 'kode');
        $this->assertContains('max_stdev_tanpa_titik_pertama', $kode, 'selisih dari bawaan wajib tercatat di jejak');
    }

    public function test_bawaan_semua_titik_memakai_stdev_titik_pertama_bila_terbesar(): void
    {
        $hasil = $this->hitungHolmium([278.0, 280.0, 282.0]);

        // STDEV([278, 280, 282]) = 2 persis; hitung tangan, bukan dari kode.
        $this->assertEqualsWithDelta(2.0, $hasil['standar_deviasi_maks'], 1e-12);
        $this->assertGreaterThan(self::J20_U95, $hasil['ketidakpastian_diperluas']);
        $this->assertNotContains('max_stdev_tanpa_titik_pertama', array_column($hasil['catatan_audit'], 'kode'));
    }

    public function test_titik_pertama_dikenali_lewat_nilai_bukan_posisi(): void
    {
        // Lembar kertas Rev.5 tanpa 279,6: yang pertama menurut urutan adalah 287,7 dan TIDAK boleh dibuang.
        $titik = [];

        foreach (array_slice(self::HOLMIUM, 1) as $i => [$nominal, $bacaan]) {
            $titik[] = ['titik_ke' => $i + 1, 'titik_ukur' => $nominal, 'pembacaan' => $i === 0 ? [286.0, 288.0, 290.0] : $bacaan];
        }

        $hasil = (new SpectrophotometerCalculator)->hitungGrup($titik, [
            'grup' => SpectrophotometerCalculator::GRUP_HOLMIUM, 'satuan' => 'nm', 'nama_standar' => 'Filter uji',
            'u_standar' => 0.1, 'k_standar' => 2.0, 'resolusi' => 0.01, 'cmc' => 0.4,
            'stdev_maks_kecualikan_nm' => [279.6],
        ]);

        $this->assertEqualsWithDelta(2.0, $hasil['standar_deviasi_maks'], 1e-12, '287,7 nm tetap ikut MAX STDEV');
        $this->assertNotContains('max_stdev_tanpa_titik_pertama', array_column($hasil['catatan_audit'], 'kode'));
    }

    public function test_bila_hanya_titik_yang_dikecualikan_terisi_tetap_dipakai_bukan_nol(): void
    {
        $hasil = (new SpectrophotometerCalculator)->hitungGrup(
            [['titik_ke' => 1, 'titik_ukur' => 279.6, 'pembacaan' => [278.0, 280.0, 282.0]]],
            [
                'grup' => SpectrophotometerCalculator::GRUP_HOLMIUM, 'satuan' => 'nm', 'nama_standar' => 'Filter uji',
                'u_standar' => 0.1, 'k_standar' => 2.0, 'resolusi' => 0.01, 'cmc' => 0.4,
                'stdev_maks_kecualikan_nm' => [279.6],
            ],
        );

        // MAX atas rentang kosong di Excel = 0 (sel kosong dibaca nol); itu tidak ditiru.
        $this->assertEqualsWithDelta(2.0, $hasil['standar_deviasi_maks'], 1e-12);
    }

    public function test_profil_spektro_meneruskan_pilihan_lewat_gerbang_tanggal(): void
    {
        $this->pakaiPenetapan('spectrophotometer.max_stdev_holmium', 'tanpa_titik_pertama');
        $gerbang = app(ParameterKeputusanMetode::class);

        $profil = new SpectrophotometerProfile;
        $spek = new \ReflectionMethod($profil, 'spekStdevMaks');

        $this->assertSame(
            ['stdev_maks_kecualikan_nm' => [279.6]],
            $gerbang->untukTanggal('2026-12-02', fn (): array => $spek->invoke($profil, SpectrophotometerCalculator::GRUP_HOLMIUM)),
        );
        $this->assertSame([], $gerbang->untukTanggal('2026-11-30', fn (): array => $spek->invoke($profil, SpectrophotometerCalculator::GRUP_HOLMIUM)), 'sesi lama: bawaan');
        $this->assertSame([], $gerbang->untukTanggal('2026-12-02', fn (): array => $spek->invoke($profil, SpectrophotometerCalculator::GRUP_DIDYNIUM)), 'Didynium dan %T memuat semua titik di workbook (M49, M67)');
    }

    // ------------------------------------------------------------------ Conductivity

    private function standar1412(): Standard
    {
        return new Standard(['nama' => 'Conductivity Std Solution 1412 µS/cm', 'koefisien_suhu' => ConductivityProfile::KOEF_SUHU['1412']]);
    }

    /** Polinomial `J26` dihitung tangan (dalam µS/cm: J26 x 1000). */
    private static function j26(float $suhu): float
    {
        return (0.0000000000002 * $suhu ** 2) + (27 * $suhu) + 738;
    }

    public function test_polinomial_suhu_cocok_sel_j26_dan_1413_pada_25_c(): void
    {
        $this->pakaiPenetapan('conductivity.nilai_acuan_larutan_1412', 'polinomial_suhu');
        $profil = new ConductivityProfile;

        app(ParameterKeputusanMetode::class)->untukTanggal('2026-12-02', function () use ($profil): void {
            foreach ([20.0, 24.92, 25.0, 25.3, 27.5] as $suhu) {
                $this->assertEqualsWithDelta(
                    self::j26($suhu),
                    $profil->nilaiAcuanStandar($this->standar1412(), $suhu, 1412.0),
                    1e-9,
                    "J26 x 1000 @ {$suhu} C",
                );
            }

            // Angka yang membuat TM bertanya: pada 25 C polinomial 1413,0 (bukan 1412).
            $this->assertEqualsWithDelta(1413.0, $profil->nilaiAcuanStandar($this->standar1412(), 25.0, 1412.0), 1e-9);

            // Tanpa suhu larutan: jatuh ke jalur induk (null -> nominal), bukan 738 dari sel kosong.
            $this->assertNull($profil->nilaiAcuanStandar($this->standar1412(), null, 1412.0));
        });
    }

    public function test_polinomial_hanya_untuk_titik_tengah_dan_bawaan_tetap_konstanta(): void
    {
        $this->pakaiPenetapan('conductivity.nilai_acuan_larutan_1412', 'polinomial_suhu');
        $profil = new ConductivityProfile;
        $gerbang = app(ParameterKeputusanMetode::class);

        // Titik 25 uS/cm dan 111 mS/cm tidak tersentuh walau pilihan aktif.
        $std25 = new Standard(['nama' => '25', 'koefisien_suhu' => ConductivityProfile::KOEF_SUHU['25']]);
        $std111 = new Standard(['nama' => '111', 'koefisien_suhu' => ConductivityProfile::KOEF_SUHU['111']]);

        $gerbang->untukTanggal('2026-12-02', function () use ($profil, $std25, $std111): void {
            $this->assertSame(25.0, $profil->nilaiAcuanStandar($std25, 25.0, 25.0));
            $this->assertSame($std111->nilaiPadaSuhu(25.0), $profil->nilaiAcuanStandar($std111, 25.0, 111.0));
        });

        // Sesi lama atau tanpa lingkup: tetap konstanta 1412.
        $this->assertSame(1412.0, $gerbang->untukTanggal('2026-11-30', fn (): ?float => $profil->nilaiAcuanStandar($this->standar1412(), 25.0, 1412.0)));
        $this->assertSame(1412.0, $profil->nilaiAcuanStandar($this->standar1412(), 25.0, 1412.0));
    }
}
