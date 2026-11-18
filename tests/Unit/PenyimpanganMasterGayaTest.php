<?php

namespace Tests\Unit;

use App\Models\Equipment;
use App\Services\Calibration\Profiles\GayaProfile;
use App\Services\Calibration\Profiles\LoadCellProfile;
use App\Services\Calibration\Profiles\ProvingRingProfile;
use App\Services\Calibration\Profiles\UtmProfile;
use App\Support\GayaMentah as M;
use App\Support\PenyimpanganMaster;
use Database\Seeders\LoadCellSeeder;
use Database\Seeders\ProvingRingSeeder;
use Database\Seeders\UtmSeeder;
use ReflectionMethod;
use Tests\Concerns\MenjagaAngkaSebelumJejak;
use Tests\TestCase;

/**
 * PK-9: catatan `penyimpangan_master` Gaya (UTM, Load Cell) dan Proving Ring.
 *
 *  - **PK5-1** (UTM, Load Cell): sesi arah TARIK memakai drift kolom Tarik, master selalu kolom
 *    Tekan (`Tabel_Drift` = `STANDAR_LOADCELL!$Q$5:$R$7`). Angka "versi master" di catatan harus
 *    sama dengan yang tertulis di `docs/pertanyaan-lab-adu-cetak-b.md` PK5-1.
 *  - **G14** (Proving Ring): master membaca pita CMC 0,22 kN, sistem pita menurut kapasitas.
 *
 * Masukan sesi = masukan seeder (`UtmSeeder`, `LoadCellSeeder`, `ProvingRingSeeder`), dihitung
 * lewat `hitungPerGrup()` yang sama dengan `POST /calibrations`. Lantai CMC di-stub (kelas anonim
 * di bawah) supaya test tidak butuh basis data; nilainya angka lampiran akreditasi di dokumen.
 */
class PenyimpanganMasterGayaTest extends TestCase
{
    use MenjagaAngkaSebelumJejak;

    private const LINGKUNGAN_UTM = ['suhu_awal' => 24.5, 'suhu_akhir' => 24.3, 'kelembaban_awal' => 55, 'kelembaban_akhir' => 54];

    private const LINGKUNGAN_LC = ['suhu_awal' => 27.7, 'suhu_akhir' => 27.7, 'kelembaban_awal' => 70, 'kelembaban_akhir' => 70];

    private const LINGKUNGAN_PR = ['suhu_awal' => 23.1, 'suhu_akhir' => 23.1, 'kelembaban_awal' => 57, 'kelembaban_akhir' => 57];

    /** U95 lantai CMC lampiran: UTM Tarik 2,1 kgf, Load Cell 0,29 kN-an, Proving Ring 2,3 kgf. */
    private const CMC_UTM_KN = 2.1 * 0.00981;

    private const CMC_LOAD_CELL_KN = 0.30;

    private const CMC_PROVING_RING_KN = 0.022563;

    /** @return array<string, mixed> */
    private function blokUtm(string $arah = M::ARAH_PULL): array
    {
        return [
            'satuan' => 'kgf', 'tipe_beban' => $arah, 'standar' => '5kN', 'suhu_sertifikat_standar' => 23.15,
            'kapasitas' => 500.0, 'resolusi_uut' => 0.1, 'resolusi_standar' => 0.0000981, 'kapasitas_standar' => 5.0,
            'preload_zero' => [0.0, 0.0, 0.0], 'preload_max' => [999.1, 998.9, 999.1],
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
        ];
    }

    /** @return array<string, mixed> */
    private function blokLoadCell(string $arah = M::ARAH_PULL): array
    {
        return [
            'satuan' => 'kN', 'tipe_beban' => $arah, 'standar' => '100kN', 'suhu_sertifikat_standar' => 23.45,
            'kapasitas' => 100.0, 'resolusi_uut' => 0.01, 'resolusi_standar' => 0.001, 'kapasitas_standar' => 100.0,
            'preload_zero' => [0.0, 0.0, 0.0], 'preload_max' => [68.86, 70.89, 71.86],
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
        ];
    }

    /** @return array<string, mixed> */
    private function blokProvingRing(): array
    {
        return [
            'satuan' => 'kgf', 'tipe_beban' => M::ARAH_PUSH, 'standar' => '3000kN', 'suhu_sertifikat_standar' => 28.6,
            'kapasitas' => 500.0, 'kapasitas_dial_mm' => 25.0, 'resolusi_dial_mm' => 0.002,
            'resolusi_standar' => 0.1, 'kapasitas_standar' => 3000.0,
            'preload_zero' => [0.0, 0.0, 0.0], 'preload_max' => [219.0, 219.0, 219.0],
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
        ];
    }

    /**
     * Masukan `hitungPerGrup()` persis seperti seeder: bacaan per titik + blok sesi + lingkungan.
     *
     * @param  class-string  $seeder
     * @param  array<string, mixed>  $blok
     * @param  array<string, mixed>  $lingkungan
     * @return list<array<string, mixed>>
     */
    private function siapHitung(string $seeder, array $blok, array $lingkungan): array
    {
        $objek = new $seeder;
        $titik = (new ReflectionMethod($seeder, 'titik'))->invoke($objek);
        $siap = [];

        foreach ($titik as $i => $t) {
            // Tiga seeder menyimpan bacaan dengan bentuk beda; urutan bacaannya = urutan seeder.
            if (isset($t['posisi'])) {
                $deretan = $t['posisi'];
            } elseif (isset($t['up'])) {
                $deretan = [M::PERAN_UP => $t['up'], M::PERAN_DOWN => $t['down']];
            } else {
                $deretan = (new ReflectionMethod($seeder, 'posisi'))->invoke($objek, $t['a'], $t['b']);
            }

            $bacaan = [];

            foreach ($deretan as $deret) {
                foreach ($deret as $nilai) {
                    $bacaan[] = (float) $nilai;
                }
            }

            $siap[] = [
                'titik_ke' => $i + 1,
                'titik_ukur' => $t['titik_ukur'],
                'pembacaan' => [],
                'standard' => null,
                'konteks' => ['bacaan' => $bacaan, 'spesifikasi_alat' => [M::KUNCI_SESI => $blok], ...$lingkungan],
            ];
        }

        return $siap;
    }

    private function profil(string $kelas, float $cmcKn): GayaProfile|ProvingRingProfile
    {
        // Kelas anonim: lantai CMC distub (tanpa basis data); selebihnya persis kelas aslinya.
        return match ($kelas) {
            'utm' => new class($cmcKn) extends UtmProfile
            {
                public function __construct(private readonly float $cmc) {}

                protected function cmcKn(Equipment $equipment, array $blok, string $arah): ?float
                {
                    return $this->cmc;
                }
            },
            'load_cell' => new class($cmcKn) extends LoadCellProfile
            {
                public function __construct(private readonly float $cmc) {}

                protected function cmcKn(Equipment $equipment, array $blok, string $arah): ?float
                {
                    return $this->cmc;
                }
            },
            default => new class($cmcKn) extends ProvingRingProfile
            {
                public function __construct(private readonly float $cmc) {}

                protected function cmcKn(Equipment $equipment, array $blok, string $arah): ?float
                {
                    return $this->cmc;
                }
            },
        };
    }

    /** @return list<array<string, mixed>> */
    private function hitung(string $kelas, array $siap, float $cmcKn): array
    {
        $hasil = $this->profil($kelas, $cmcKn)->hitungPerGrup($siap, new Equipment);

        $this->assertNotNull($hasil);
        $this->assertSame([], $hasil['belum_dihitung'], "{$kelas}: seluruh titik harus terhitung.");

        return $hasil['hitungan'];
    }

    /** @return array<string, string> */
    private function catatan(array $titik): array
    {
        return array_intersect_key(
            $titik['type_b_components']['penyimpangan_master'],
            array_flip(['drift_tarik_vs_tekan', 'pita_cmc_dari_baris_salah']),
        );
    }

    /** Jejak tanpa catatan PK-9: yang dibandingkan ke golden. */
    private function tanpaCatatanBaru(array $titik): array
    {
        unset($titik['calculated_at']);

        foreach (['drift_tarik_vs_tekan', 'pita_cmc_dari_baris_salah'] as $kunci) {
            unset($titik['type_b_components']['penyimpangan_master'][$kunci]);
        }

        return $titik;
    }

    // ------------------------------------------------------------ angka tidak berubah

    public function test_angka_hasil_hitung_gaya_identik_dengan_sebelum_catatan_ada(): void
    {
        $sekarang = [];

        foreach ([
            'utm_tarik' => ['utm', UtmSeeder::class, $this->blokUtm(), self::LINGKUNGAN_UTM, self::CMC_UTM_KN],
            'utm_tekan' => ['utm', UtmSeeder::class, $this->blokUtm(M::ARAH_PUSH), self::LINGKUNGAN_UTM, self::CMC_UTM_KN],
            'utm_tanpa_lantai' => ['utm', UtmSeeder::class, $this->blokUtm(), self::LINGKUNGAN_UTM, 0.0],
            'load_cell_tarik' => ['load_cell', LoadCellSeeder::class, $this->blokLoadCell(), self::LINGKUNGAN_LC, self::CMC_LOAD_CELL_KN],
            'load_cell_tekan' => ['load_cell', LoadCellSeeder::class, $this->blokLoadCell(M::ARAH_PUSH), self::LINGKUNGAN_LC, self::CMC_LOAD_CELL_KN],
            'proving_ring' => ['proving_ring', ProvingRingSeeder::class, $this->blokProvingRing(), self::LINGKUNGAN_PR, self::CMC_PROVING_RING_KN],
            'proving_ring_pita_master' => ['proving_ring', ProvingRingSeeder::class, $this->blokProvingRing(), self::LINGKUNGAN_PR, 0.22],
        ] as $nama => [$kelas, $seeder, $blok, $lingkungan, $cmc]) {
            foreach ($this->hitung($kelas, $this->siapHitung($seeder, $blok, $lingkungan), $cmc) as $h) {
                $sekarang[$nama.':'.$h['titik_ke']] = $this->tanpaCatatanBaru($h);
            }
        }

        $this->cocokkanGoldenAngka('gaya', $sekarang);
    }

    // ------------------------------------------------------------ PK5-1

    public function test_pk51_utm_tarik_mencatat_drift_tekan_master_dengan_angka_dokumen(): void
    {
        $titik = $this->hitung('utm', $this->siapHitung(UtmSeeder::class, $this->blokUtm(), self::LINGKUNGAN_UTM), self::CMC_UTM_KN)[0];
        $catatan = $this->catatan($titik);

        $this->assertArrayHasKey('drift_tarik_vs_tekan', $catatan);
        $this->assertArrayNotHasKey('pita_cmc_dari_baris_salah', $catatan);
        $teks = $catatan['drift_tarik_vs_tekan'];

        // Dokumen PK5-1: drift master 0,069282 (R5) vs sistem 0 (S5); k 1,97166 vs 1,96986;
        // veff 204 vs 242; U hitung 0,4190 % vs 0,3965 %; tidak konservatif.
        $this->assertStringContainsString('STANDAR_LOADCELL!$Q$5:$R$7', $teks);
        $this->assertStringContainsString('PK5-1', $teks);
        // (Di dokumen urutannya (sistem, master): k 1,97166 vs 1,96986, veff 204 vs 242.)
        $this->assertMatchesRegularExpression('/Master: drift Tekan 0,069282 .*U hitung 0,4189\d* % f\.s, k 1,96986, v_eff 241,8/', $teks);
        $this->assertMatchesRegularExpression('/Sistem: drift Tarik 0 .*U hitung 0,3964\d* % f\.s, k 1,97166, v_eff 204,3/', $teks);
        $this->assertStringContainsString('TIDAK konservatif', $teks);
        // Lantai CMC menang di kedua sisi pada sesi contoh: cetak sama, tersimpan beda.
        $this->assertStringContainsString('Angka CETAK U95 SAMA', $teks);
    }

    public function test_pk51_load_cell_tarik_mencatat_angka_dokumen(): void
    {
        $titik = $this->hitung('load_cell', $this->siapHitung(LoadCellSeeder::class, $this->blokLoadCell(), self::LINGKUNGAN_LC), self::CMC_LOAD_CELL_KN)[0];
        $teks = $this->catatan($titik)['drift_tarik_vs_tekan'] ?? '';

        // Dokumen PK5-1: drift master 0,031834 (R6) vs sistem 0,034272 (S6); k 2,0796 vs 2,0860.
        // (Di dokumen urutannya (sistem, master): k 2,0796 vs 2,0860; veff 21,28 vs 20,95.)
        $this->assertMatchesRegularExpression('/Master: drift Tekan 0,03183\d* .*k 2,0(85|86)\d*, v_eff 20,9/', $teks);
        $this->assertMatchesRegularExpression('/Sistem: drift Tarik 0,03427\d* .*k 2,0796\d*, v_eff 21,2/', $teks);
    }

    public function test_pk51_tidak_muncul_di_sesi_tekan(): void
    {
        foreach ([
            ['utm', UtmSeeder::class, $this->blokUtm(M::ARAH_PUSH), self::LINGKUNGAN_UTM, self::CMC_UTM_KN],
            ['load_cell', LoadCellSeeder::class, $this->blokLoadCell(M::ARAH_PUSH), self::LINGKUNGAN_LC, self::CMC_LOAD_CELL_KN],
        ] as [$kelas, $seeder, $blok, $lingkungan, $cmc]) {
            foreach ($this->hitung($kelas, $this->siapHitung($seeder, $blok, $lingkungan), $cmc) as $h) {
                $this->assertArrayNotHasKey('drift_tarik_vs_tekan', $this->catatan($h), "{$kelas} tekan titik {$h['titik_ke']}");
            }
        }
    }

    // ------------------------------------------------------------ G14

    public function test_g14_proving_ring_500_kgf_mencatat_pita_master_022_kn(): void
    {
        $siap = $this->siapHitung(ProvingRingSeeder::class, $this->blokProvingRing(), self::LINGKUNGAN_PR);

        foreach ($this->hitung('proving_ring', $siap, self::CMC_PROVING_RING_KN) as $h) {
            $teks = $this->catatan($h)['pita_cmc_dari_baris_salah'] ?? '';
            $this->assertNotSame('', $teks, "titik {$h['titik_ke']}");

            // Dokumen G14/PK5-2: master 0,22 kN (22,4 kgf), sistem 0,022563 kN (2,3 kgf); tidak konservatif.
            $this->assertStringContainsString('22,4', $teks);
            $this->assertStringContainsString('0,22 kN', $teks);
            $this->assertStringContainsString('2,3 kgf (0,022563 kN)', $teks);
            $this->assertStringContainsString('TIDAK konservatif', $teks);
            $this->assertStringContainsString('G14', $teks);
            $this->assertStringContainsString('`P38`', $teks);
        }
    }

    public function test_g14_tidak_muncul_bila_pita_sistem_sama_dengan_pita_master(): void
    {
        $siap = $this->siapHitung(ProvingRingSeeder::class, $this->blokProvingRing(), self::LINGKUNGAN_PR);

        foreach ($this->hitung('proving_ring', $siap, 0.22) as $h) {
            $this->assertArrayNotHasKey('pita_cmc_dari_baris_salah', $this->catatan($h));
        }
    }

    public function test_catatan_gaya_memakai_susunan_baku_penyimpangan_master(): void
    {
        $titik = $this->hitung('utm', $this->siapHitung(UtmSeeder::class, $this->blokUtm(), self::LINGKUNGAN_UTM), self::CMC_UTM_KN)[0];
        $teks = $this->catatan($titik)['drift_tarik_vs_tekan'];

        $this->assertStringStartsWith('PENYIMPANGAN DARI MASTER.', $teks);

        foreach (['Sumber:', 'Master:', 'Sistem:', 'Arah:'] as $penanda) {
            $this->assertStringContainsString($penanda, $teks);
        }

        $this->assertSame([], PenyimpanganMaster::dari($titik['type_b_components']), 'Jejak Gaya berbentuk peta, bukan daftar baris.');
    }
}
