<?php

namespace Tests\Unit;

use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\PenyimpanganMaster;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MenjagaAngkaSebelumJejak;
use Tests\TestCase;

/**
 * PK-9: catatan `penyimpangan_master.timbangan.*` di jejak sesi (PK4-3).
 *
 * Tiga penyimpangan yang sudah tercatat di dokumen tapi tidak ada di jejak:
 *
 *  - **T2** (kg & substitusi, tiap titik): `ui` "Uncertainty of Correction" di budget
 *    Weighing = U/k; master kg memakai U mentah, master substitusi U/akar 3.
 *  - **T6** (gram, titik 9): drift Mass 2 & 3 dihitung benar; master menunjuk `FC!B80/B81`.
 *  - **T7** (substitusi, titik 9): Weight Standard dari berkasnya sendiri; master membaca cache
 *    tautan luar `[3]`.
 *
 * Yang dijaga: (1) catatan ADA bila kondisi terpenuhi dan TIDAK ada bila tidak; (2) angka
 * pembanding "versi master" yang dihitung catatan = sel master di fixture; (3) seluruh angka hasil
 * hitung identik dengan keluaran SEBELUM catatan ada (golden `timbangan.json`).
 */
class PenyimpanganMasterTimbanganTest extends TestCase
{
    use MenjagaAngkaSebelumJejak;

    private const TOL = 5e-6;

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/database/data/sesi-master-timbangan.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, array{string}> */
    public static function varian(): array
    {
        return ['kg' => ['kg'], 'gram' => ['gram'], 'substitusi' => ['sub']];
    }

    /**
     * @param  array<string, mixed>|null  $sesi  default: sesi master dari fixture
     * @return list<array<string, mixed>>
     */
    private function hitung(string $tag, ?array $sesi = null): array
    {
        $s = $sesi ?? self::fixture()[$tag];
        $spek = [
            'varian_master' => $s['varian'],
            'kapasitas' => $s['kapasitas'],
            'resolusi' => $s['resolusi'],
            'satuan' => $s['satuan'],
            'tipe_display' => $s['tipe_display'],
            'tipe_timbangan' => $s['tipe_timbangan'],
            'keterulangan' => $s['keterulangan'],
            'eksentrisitas' => $s['eksentrisitas'],
            'histeresis' => $s['histeresis'],
        ];

        $titik = array_map(static fn (array $a): array => [
            'titik_ke' => $a['titik_ke'],
            'titik_ukur' => array_sum($a['nominal']),
            'pembacaan' => [],
            'standard' => null,
            'standard_id' => null,
            'konteks' => [
                'nominal' => $a['nominal'],
                'z1' => $a['baca']['z1'] ?? null,
                'm' => $a['baca']['m'] ?? null,
                'm_aksen' => $a['baca']['m_aksen'] ?? null,
                'z2' => $a['baca']['z2'] ?? null,
                'spesifikasi_alat' => $spek,
            ],
        ], $s['akurasi']);

        $hasil = app(CalibrationProfileRegistry::class)->untukKode('timbangan')
            ->hitungPerGrup($titik, new Equipment);

        $this->assertNotNull($hasil);
        $this->assertSame([], $hasil['belum_dihitung'], 'Sesi fixture harus terhitung penuh.');

        return $hasil['hitungan'];
    }

    /**
     * @param  array<string, mixed>  $titik
     * @return list<array<string, mixed>>
     */
    private function catatan(array $titik): array
    {
        return PenyimpanganMaster::dari($titik['type_b_components']);
    }

    // ------------------------------------------------------------ angka tidak berubah

    public function test_angka_hasil_hitung_tiga_varian_identik_dengan_sebelum_catatan_ada(): void
    {
        $sekarang = [];

        foreach (['kg', 'gram', 'sub'] as $tag) {
            foreach ($this->hitung($tag) as $h) {
                unset($h['calculated_at']);
                $h['type_b_components'] = array_values(array_filter(
                    $h['type_b_components'],
                    static fn (array $b): bool => ! str_starts_with((string) ($b['sumber'] ?? ''), PenyimpanganMaster::AWALAN),
                ));
                $sekarang[$tag.':'.$h['titik_ke']] = $h;
            }
        }

        $this->cocokkanGoldenAngka('timbangan', $sekarang);
    }

    // ------------------------------------------------------------ T2

    #[DataProvider('varian')]
    public function test_t2_hanya_ada_di_kg_dan_substitusi_pada_setiap_titik(string $tag): void
    {
        foreach ($this->hitung($tag) as $h) {
            $ada = array_values(array_filter(
                $this->catatan($h),
                static fn (array $b): bool => $b['sumber'] === 'penyimpangan_master.timbangan.ui_koreksi_di_budget_weighing',
            ));

            $this->assertCount($tag === 'gram' ? 0 : 1, $ada, "{$tag} titik {$h['titik_ke']}: catatan T2.");
        }
    }

    /**
     * Angka "versi master" di catatan T2 = sel U95-Weighing master di fixture (kecuali titik 9
     * substitusi yang juga kena T7 dan dibahas di test sendiri).
     */
    #[DataProvider('varian')]
    public function test_t2_u95_weighing_versi_master_sama_dengan_sel_master(string $tag): void
    {
        if ($tag === 'gram') {
            $this->markTestSkipped('Gram memakai U/k seperti sistem: tidak ada penyimpangan T2.');
        }

        $harap = self::fixture()[$tag]['_budget']['penimbangan'];
        $dicek = 0;

        foreach ($this->hitung($tag) as $i => $h) {
            if ($tag === 'sub' && $h['titik_ke'] === 9) {
                continue;
            }

            $angka = $this->catatan($h)[0]['penyimpangan']['angka'];

            $this->assertEqualsWithDelta(
                (float) $harap[$i]['U'],
                $angka['u95_weighing_hitung_master'],
                self::TOL * max(abs((float) $harap[$i]['U']), 1e-9) + 1e-12,
                "{$tag} titik {$h['titik_ke']}: U95 Weighing versi master di catatan beda dari sel master.",
            );
            $dicek++;
        }

        $this->assertGreaterThanOrEqual(9, $dicek);
    }

    public function test_t2_kg_titik_1_menyebut_sel_master_nilai_dan_arah(): void
    {
        $t = $this->hitung('kg')[0];
        $c = $this->catatan($t)[0];

        $this->assertStringContainsString('SERTIFIKAT!H49:H53,W49:W53', $c['keterangan']);
        $this->assertStringContainsString('T2', $c['keterangan']);
        $this->assertStringContainsString('Master:', $c['keterangan']);
        $this->assertStringContainsString('Sistem:', $c['keterangan']);
        $this->assertStringContainsString('Arah:', $c['keterangan']);

        // Doc PK4-2/T2: ui 0,01967 (master) vs 0,01003 (sistem); tercetak 0,0425 vs 0,033 (lantai CMC).
        $a = $c['penyimpangan']['angka'];
        $this->assertEqualsWithDelta(0.01967, $a['ui_master'], 5e-5);
        $this->assertEqualsWithDelta(0.01003, $a['ui_sistem'], 5e-5);
        $this->assertEqualsWithDelta(0.0425, $a['u95_weighing_cetak_master'], 5e-5);
        $this->assertEqualsWithDelta(0.033, $a['u95_weighing_cetak_sistem'], 5e-5);
        $this->assertSame(PenyimpanganMaster::ARAH_TIDAK_KONSERVATIF, $c['penyimpangan']['arah']);
        $this->assertEqualsWithDelta(0.033, (float) $c['nilai'], 5e-5);
    }

    // ------------------------------------------------------------ T6

    public function test_t6_gram_hanya_di_titik_9_dan_versi_master_sama_dengan_sel_master(): void
    {
        $adaDi = [];

        foreach ($this->hitung('gram') as $i => $h) {
            foreach ($this->catatan($h) as $c) {
                $this->assertSame('penyimpangan_master.timbangan.gram_titik9_drift_mass_2_3', $c['sumber']);
                $adaDi[] = $h['titik_ke'];

                $harapMaster = (float) self::fixture()['gram']['_budget']['koreksi'][$i]['U'];
                $a = $c['penyimpangan']['angka'];

                $this->assertEqualsWithDelta(
                    $harapMaster,
                    $a['u95_koreksi_hitung_master'],
                    self::TOL * $harapMaster + 1e-12,
                    'T6: U95 Correction "versi master" harus sama dengan sel master (drift Mass 2 dan 3 = 0).',
                );
                // Sistem LEBIH BESAR (doc T6: 0,00057460 -> 0,00057477 g, +0,03 %).
                $this->assertGreaterThan($a['u95_koreksi_hitung_master'], $a['u95_koreksi_hitung_sistem']);
                $this->assertSame(PenyimpanganMaster::ARAH_KONSERVATIF, $c['penyimpangan']['arah']);
                $this->assertStringContainsString('FC!B80', $c['keterangan']);
                $this->assertStringContainsString('T6', $c['keterangan']);
            }
        }

        $this->assertSame([9], $adaDi);
    }

    public function test_t6_tidak_muncul_bila_drift_mass_2_dan_3_titik_9_memang_nol(): void
    {
        $sesi = self::fixture()['gram'];
        // Titik 9 hanya satu keping: tidak ada Mass 2 / Mass 3, jadi master dan sistem sama-sama nol.
        $sesi['akurasi'][8]['nominal'] = [$sesi['akurasi'][8]['nominal'][0]];

        foreach ($this->hitung('gram', $sesi) as $h) {
            $this->assertSame([], $this->catatan($h), "titik {$h['titik_ke']}");
        }
    }

    // ------------------------------------------------------------ T7

    public function test_t7_substitusi_hanya_di_titik_9_dan_versi_master_sama_dengan_sel_master(): void
    {
        $adaDi = [];

        foreach ($this->hitung('sub') as $i => $h) {
            foreach ($this->catatan($h) as $c) {
                if ($c['sumber'] !== 'penyimpangan_master.timbangan.substitusi_titik9_weight_standard') {
                    continue;
                }

                $adaDi[] = $h['titik_ke'];
                $a = $c['penyimpangan']['angka'];
                $harapMaster = (float) self::fixture()['sub']['_budget']['koreksi'][$i]['U'];

                $this->assertEqualsWithDelta(
                    $harapMaster,
                    $a['u95_koreksi_hitung_master'],
                    self::TOL * $harapMaster + 1e-12,
                    'T7: U95 Correction "versi master" harus sama dengan sel master (ui Weight Standard cache 2,4055e-5).',
                );
                $this->assertEqualsWithDelta(2.4055e-5, $a['ui_weight_standard_master'], 1e-12);
                $this->assertGreaterThan($a['u95_koreksi_hitung_master'], $a['u95_koreksi_hitung_sistem']);
                $this->assertStringContainsString("'[3]PERHITUNGAN FC'!F74:G76", $c['keterangan']);
                $this->assertStringContainsString('T7', $c['keterangan']);
                // Di sesi contoh lantai CMC 0,52 menang di kedua sisi: cetak tidak bergerak.
                $this->assertSame(PenyimpanganMaster::ARAH_NETRAL, $c['penyimpangan']['arah']);
            }
        }

        $this->assertSame([9], $adaDi);
    }

    public function test_t7_dan_t6_tidak_muncul_di_varian_lain(): void
    {
        foreach (['kg', 'gram'] as $tag) {
            foreach ($this->hitung($tag) as $h) {
                foreach ($this->catatan($h) as $c) {
                    $this->assertStringNotContainsString('substitusi_titik9', $c['sumber'], "{$tag} titik {$h['titik_ke']}");
                }
            }
        }

        foreach ($this->hitung('sub') as $h) {
            foreach ($this->catatan($h) as $c) {
                $this->assertStringNotContainsString('gram_titik9', $c['sumber']);
            }
        }
    }
}
