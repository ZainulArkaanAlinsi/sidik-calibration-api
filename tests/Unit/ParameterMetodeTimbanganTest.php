<?php

namespace Tests\Unit;

use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\TimbanganCalculator;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter `timbangan.sumber_ui_koreksi_kg` (lembar centang TM, butir B1).
 *
 * Master kg memasukkan `U95% of Correction` ke budget Weighing MENTAH (`ui = U`, kelebihan
 * sekitar 2x); kode memakai `ui = U / k` sejak 16 Sep 2026 (GUM 4.3.3). Dua pilihan:
 *
 *  - `bagi_k` (bawaan): perilaku kode, tidak berubah.
 *  - `master`: U mentah, HANYA varian kg. Gram (sudah ÷k di master) dan substitusi (master
 *    memakai ÷√3, yang bukan pengembalian ke u baku) tidak ikut berubah.
 *
 * Angka acuan: `_budget.penimbangan` di `database/data/sesi-master-timbangan.json`, salinan
 * cache workbook master kg (`_sumber` berkas itu), yang juga dipakai `TimbanganMasterTest`.
 * Mode `master` harus mengembalikan SEMUA komponen, uc, veff, k, dan U kg ke angka master;
 * itu bukti bahwa pilihan ini setara workbook, bukan sekadar "angkanya berubah".
 *
 * Penahanan sertifikat timbangan (`profil_ditahan`) tidak disentuh pilihan ini: lihat
 * `ProfilDitahanTest`.
 */
class ParameterMetodeTimbanganTest extends TestCase
{
    use PenetapanParameterUji;

    private const KUNCI = 'timbangan.sumber_ui_koreksi_kg';

    private const TOLERANSI = 5e-6;

    private const BERLAKU = '2026-12-01';

    private const SESUDAH = '2026-12-05';

    private const SEBELUM = '2026-11-30';

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

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $isi = null;

        return $isi ??= json_decode((string) file_get_contents(database_path('data/sesi-master-timbangan.json')), true);
    }

    /** @return array<string, mixed> */
    private function hitung(string $tag, ?string $tanggal = null): array
    {
        $sesi = self::fixture()[$tag];
        unset($sesi['_harap'], $sesi['_budget']);

        if ($tanggal === null) {
            return (new TimbanganCalculator)->hitung($sesi);
        }

        return app(ParameterKeputusanMetode::class)->untukTanggal($tanggal, fn (): array => (new TimbanganCalculator)->hitung($sesi));
    }

    /** @param  array<string, mixed>  $titik */
    private function komponenUKoreksi(array $titik): array
    {
        $baris = array_values(array_filter(
            $titik['budget_penimbangan'],
            static fn (array $k): bool => $k['sumber'] === 'uncertainty_of_correction',
        ));

        $this->assertCount(1, $baris);

        return $baris[0];
    }

    private function dekat(float $dapat, float $harap, string $pesan): void
    {
        $this->assertEqualsWithDelta($harap, $dapat, self::TOLERANSI * max(abs($harap), 1e-9) + 1e-12, $pesan);
    }

    public function test_bawaan_kg_membagi_u_koreksi_dengan_k_tanpa_lingkup_dan_sebelum_penetapan(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'master', self::BERLAKU);

        $tanpaLingkup = $this->hitung('kg');
        $sebelum = $this->hitung('kg', self::SEBELUM);

        $this->assertSame($tanpaLingkup, $sebelum, 'Sesi bertanggal sebelum penetapan berlaku tidak boleh bergeser.');

        foreach ($tanpaLingkup['titik'] as $i => $t) {
            $komp = $this->komponenUKoreksi($t);

            $this->assertSame($t['u95_koreksi_hitung'] / $t['k_koreksi'], $komp['u'], 'kg titik '.($i + 1).': bawaan ui = U / k');
            $this->assertSame('Uncertainty of Correction', $komp['keterangan'], 'Bawaan tidak menambah catatan apa pun di keterangan.');
        }
    }

    public function test_pilihan_master_mengembalikan_budget_penimbangan_kg_ke_angka_workbook(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'master', self::BERLAKU);

        $hasil = $this->hitung('kg', self::SESUDAH);
        $master = self::fixture()['kg']['_budget']['penimbangan'];
        $diadu = 0;

        foreach ($hasil['titik'] as $i => $t) {
            $harap = $master[$i];
            $ke = 'kg titik '.($i + 1);

            $this->assertSame(count($harap['komponen']), count($t['budget_penimbangan']), "{$ke}: jumlah komponen");

            foreach ($t['budget_penimbangan'] as $j => $komp) {
                $m = $harap['komponen'][$j];
                $this->dekat($komp['u'] * $komp['ci'], (float) $m['ui'] * (float) ($m['ci'] ?: 1), "{$ke} ui x ci [{$j}] {$komp['keterangan']}");
                $this->dekat((float) $komp['vi'], (float) $m['vi'], "{$ke} vi [{$j}] {$komp['keterangan']}");
                $diadu += 2;
            }

            $this->dekat($t['uc_penimbangan'], (float) $harap['uc'], "{$ke} uc");
            $this->dekat($t['veff_penimbangan'], (float) $harap['veff'], "{$ke} veff");
            $this->dekat($t['k_penimbangan'], (float) $harap['k'], "{$ke} k");
            $this->dekat($t['u95_penimbangan_hitung'], (float) $harap['U'], "{$ke} U");
            $diadu += 4;

            $komp = $this->komponenUKoreksi($t);
            $this->assertSame($t['u95_koreksi_hitung'], $komp['u'], "{$ke}: master memakai U mentah");
            $this->assertStringContainsString(self::KUNCI, $komp['keterangan'], "{$ke}: jejak menyebut parameternya");
        }

        $this->assertGreaterThan(50, $diadu, 'Fixture curiga kosong: terlalu sedikit angka yang diadu.');
    }

    public function test_pilihan_master_hanya_menggeser_budget_penimbangan(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'master', self::BERLAKU);

        $bawaan = $this->hitung('kg');
        $master = $this->hitung('kg', self::SESUDAH);
        $kunciPenimbangan = ['budget_penimbangan', 'u95_penimbangan_hitung', 'uc_penimbangan', 'veff_penimbangan', 'k_penimbangan', 'u95_penimbangan'];

        $tanpa = static fn (array $titik): array => array_map(
            static fn (array $t): array => array_diff_key($t, array_flip($kunciPenimbangan)),
            $titik,
        );

        $this->assertSame($tanpa($bawaan['titik']), $tanpa($master['titik']), 'Koreksi, budget koreksi, dan U koreksi tidak boleh bergeser.');
        $this->assertSame($bawaan['lop'], $master['lop'], 'Limit of Performance memakai U koreksi, bukan budget penimbangan.');
        $this->assertSame($bawaan['keterulangan'], $master['keterulangan']);
        $this->assertSame($bawaan['eksentrisitas'], $master['eksentrisitas']);
        $this->assertSame($bawaan['histeresis'], $master['histeresis']);

        // Arah: U mentah >= U/k, jadi budget penimbangan tidak pernah mengecil.
        foreach ($bawaan['titik'] as $i => $t) {
            $this->assertGreaterThan($t['u95_penimbangan_hitung'], $master['titik'][$i]['u95_penimbangan_hitung'], 'kg titik '.($i + 1));
        }
    }

    /** Gram dan substitusi tidak punya `turunanUKoreksi` mentah, jadi pilihan master tidak menyentuhnya. */
    public function test_varian_gram_dan_substitusi_tidak_ikut_berubah(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'master', self::BERLAKU);

        foreach (['gram', 'sub'] as $tag) {
            $this->assertSame(
                $this->hitung($tag),
                $this->hitung($tag, self::SESUDAH),
                "{$tag}: pilihan khusus kg tidak boleh mengubah varian lain.",
            );
        }
    }
}
