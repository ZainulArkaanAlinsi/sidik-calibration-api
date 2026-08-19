<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use Tests\TestCase;

/**
 * Spectrophotometer dengan data yang **BUKAN** sesi master.
 *
 * Kembarannya `ViscometerDataLainTest`, dan alasannya sama: seluruh test
 * spektro yang lain mengadu keluaran ke workbook master, jadi tidak satu pun
 * bisa menjawab "apakah masih benar untuk sesi lain".
 *
 * Alat ini punya dua hal yang tidak dimiliki enam alat lain, dan dua-duanya
 * justru paling mungkin salah di data baru:
 *
 *  - **Budget lahir per KELOMPOK, bukan per titik.** Sepuluh titik Holmium
 *    berbagi satu `uc` / `veff` / `U95`, dan Type A-nya diambil dari
 *    `MAX. STDEV` seluruh kelompok — bukan STDEV titik itu sendiri. Titik yang
 *    masuk kelompok yang salah membawa U95 kelompok lain ke sertifikat, dan
 *    itu tidak kelihatan salah.
 *  - **Lantai CMC.** Lab tidak boleh mengklaim ketidakpastian lebih baik dari
 *    kemampuan terakreditasinya, jadi yang dilaporkan `max(U_hitung, CMC)`.
 *    Di sesi master U hitung menang; di sesi yang alatnya lebih stabil, CMC
 *    yang menang — cabang yang tidak pernah dilewati satu test pun.
 */
class SpektroDataLainTest extends TestCase
{
    private SpectrophotometerProfile $profil;

    private SpectrophotometerCalculator $kalkulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profil = new SpectrophotometerProfile;
        $this->kalkulator = new SpectrophotometerCalculator;
    }

    /**
     * @param  list<array{int, float, list<float>}>  $titik
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function hitung(string $grup, array $titik, array $ganti = []): array
    {
        return $this->kalkulator->hitungGrup(
            array_map(
                static fn (array $t): array => [
                    'titik_ke' => $t[0],
                    'titik_ukur' => $t[1],
                    'pembacaan' => $t[2],
                ],
                $titik,
            ),
            [
                'grup' => $grup,
                'satuan' => $ganti['satuan'] ?? 'nm',
                'nama_standar' => 'Filter Standard 1',
                'u_standar' => $ganti['u_standar'] ?? 0.3,
                'k_standar' => $ganti['k_standar'] ?? 2.0,
                'resolusi' => $ganti['resolusi'] ?? 0.01,
                'cmc' => $ganti['cmc'] ?? 0.5,
            ],
        );
    }

    /**
     * Titik masuk kelompok yang benar — termasuk dua titik yang cuma beda
     * 1,4 % (453,6 & 460,0 nm) dan angka %T yang nilainya berimpit dengan
     * panjang gelombang.
     */
    public function test_pengelompokan_titik_tidak_pernah_nyasar(): void
    {
        $holmium = [279.6, 287.7, 334.0, 360.9, 418.6, 445.8, 453.6, 460.0, 536.3, 637.9];
        $didynium = [475.2, 513.7, 529.7, 572.7, 585.7, 684.9, 738.5, 748.0, 806.1];
        $transmitan = [0.0, 9.9, 20.0, 30.1, 100.0];

        foreach ($holmium as $t) {
            $this->assertSame(
                SpectrophotometerCalculator::GRUP_HOLMIUM,
                $this->profil->grupTitik($t),
                "Titik {$t} nm nyasar.",
            );
        }

        foreach ($didynium as $t) {
            $this->assertSame(
                SpectrophotometerCalculator::GRUP_DIDYNIUM,
                $this->profil->grupTitik($t),
                "Titik {$t} nm nyasar.",
            );
        }

        foreach ($transmitan as $t) {
            $this->assertSame(
                SpectrophotometerCalculator::GRUP_TRANSMITAN,
                $this->profil->grupTitik($t),
                "Titik {$t} %T nyasar.",
            );
        }

        // Titik yang tidak ada di daftar mana pun TIDAK dipaksa masuk yang
        // terdekat — 300 nm cuma 21 nm dari 279,6 tapi tetap bukan anggota.
        foreach ([300.0, 400.0, 650.0, 1000.0, 55.5] as $asing) {
            $this->assertNull(
                $this->profil->grupTitik($asing),
                "Titik {$asing} nggak ada di daftar tapi dikasih kelompok.",
            );
        }
    }

    /**
     * Type A kelompok diambil dari **STDEV terbesar**, bukan dari titik
     * pertama, terakhir, atau rata-ratanya.
     *
     * Diuji dengan menaruh titik ber-STDEV terbesar di tiga posisi berbeda:
     * hasilnya harus identik. Implementasi yang mengambil titik pertama lolos
     * di master hanya karena di sana kebetulan titik pertama yang terbesar.
     */
    public function test_type_a_pakai_stdev_terbesar_di_posisi_mana_pun(): void
    {
        $tenang = [279.60, 279.61, 279.60, 279.61];   // sebaran kecil
        $ramai = [287.50, 287.90, 287.60, 288.10];    // sebaran besar

        $depan = $this->hitung('holmium', [
            [1, 287.7, $ramai],
            [2, 334.0, $tenang],
            [3, 360.9, $tenang],
        ]);
        $tengah = $this->hitung('holmium', [
            [1, 334.0, $tenang],
            [2, 287.7, $ramai],
            [3, 360.9, $tenang],
        ]);
        $belakang = $this->hitung('holmium', [
            [1, 334.0, $tenang],
            [2, 360.9, $tenang],
            [3, 287.7, $ramai],
        ]);

        $stdevRamai = $this->stdevSampel($ramai);

        foreach ([$depan, $tengah, $belakang] as $i => $h) {
            $this->assertEqualsWithDelta(
                $stdevRamai,
                $h['standar_deviasi_maks'],
                1e-12,
                "MAX STDEV salah waktu titik terbesar di posisi ke-{$i}.",
            );
        }

        $this->assertEqualsWithDelta(
            $depan['ketidakpastian_diperluas'],
            $tengah['ketidakpastian_diperluas'],
            1e-12,
        );
        $this->assertEqualsWithDelta(
            $depan['ketidakpastian_diperluas'],
            $belakang['ketidakpastian_diperluas'],
            1e-12,
        );
    }

    /**
     * Semua titik satu kelompok membawa U95 yang sama — itu memang bentuk
     * sertifikatnya (satu baris per blok), dan yang beda cuma rata-rata,
     * koreksi, dan STDEV per titik.
     */
    public function test_satu_kelompok_satu_u95_tapi_rata_rata_tetap_per_titik(): void
    {
        $h = $this->hitung('holmium', [
            [1, 279.6, [279.55, 279.60, 279.58]],
            [2, 334.0, [333.70, 333.78, 333.74]],
            [3, 637.9, [637.40, 637.50, 637.45]],
        ]);

        $this->assertCount(3, $h['titik']);

        // Rata-rata per titik dihitung ulang di sini, bukan disalin.
        $harusnya = [
            (279.55 + 279.60 + 279.58) / 3,
            (333.70 + 333.78 + 333.74) / 3,
            (637.40 + 637.50 + 637.45) / 3,
        ];

        foreach ($h['titik'] as $i => $t) {
            $this->assertEqualsWithDelta($harusnya[$i], $t['rata_rata'], 1e-9);
        }

        // Tiga rata-rata yang jelas berbeda, satu U95 untuk seluruh kelompok.
        $this->assertNotEqualsWithDelta($harusnya[0], $harusnya[2], 1.0);
        $this->assertIsFloat($h['ketidakpastian_diperluas']);
    }

    /**
     * Lantai CMC — cabang yang tidak pernah dilewati sesi master.
     *
     * Alat yang sangat stabil menghasilkan U hitung di bawah CMC lab. Yang
     * dilaporkan harus CMC, dan sumbernya ditandai supaya bisa ditelusuri.
     */
    public function test_alat_stabil_dilaporkan_pakai_cmc_bukan_u_hitung(): void
    {
        // Pembacaan nyaris tanpa sebaran + sertifikat standar sangat bagus.
        $stabil = $this->hitung(
            'holmium',
            [
                [1, 279.6, [279.600, 279.600, 279.601]],
                [2, 334.0, [334.000, 334.000, 334.001]],
            ],
            ['u_standar' => 0.001, 'cmc' => 0.5],
        );

        $this->assertLessThan(
            0.5,
            $stabil['ketidakpastian_diperluas'],
            'Prasyarat test: U hitung harus di bawah CMC.',
        );
        $this->assertSame('cmc', $stabil['sumber_u95']);
        $this->assertEqualsWithDelta(0.5, $stabil['u95_sertifikat'], 1e-12);

        // Kebalikannya: alat berisik → U hitung menang, CMC tidak dipakai.
        $berisik = $this->hitung(
            'holmium',
            [
                [1, 279.6, [278.9, 280.4, 279.1]],
                [2, 334.0, [333.2, 334.9, 333.6]],
            ],
            ['u_standar' => 0.3, 'cmc' => 0.5],
        );

        $this->assertGreaterThan(0.5, $berisik['ketidakpastian_diperluas']);
        $this->assertSame('hitung', $berisik['sumber_u95']);
        $this->assertEqualsWithDelta(
            $berisik['ketidakpastian_diperluas'],
            $berisik['u95_sertifikat'],
            1e-12,
        );
    }

    /**
     * U95 yang dilaporkan tidak pernah turun saat sebaran pembacaan naik.
     *
     * Sifat yang harus berlaku untuk data apa pun: alat yang makin tidak
     * konsisten tidak boleh menghasilkan sertifikat yang mengklaim lebih
     * pasti. Dijaga dengan deret sebaran yang menaik, bukan tiga kasus pilihan.
     */
    public function test_u95_naik_monoton_mengikuti_sebaran(): void
    {
        $sebelumnya = null;

        foreach ([0.001, 0.01, 0.05, 0.1, 0.3, 0.8, 1.5] as $sebar) {
            $h = $this->hitung(
                'holmium',
                [
                    [1, 279.6, [279.6 - $sebar, 279.6, 279.6 + $sebar]],
                    [2, 334.0, [334.0, 334.0, 334.0]],
                ],
                // CMC dikecilkan supaya lantainya tidak menutupi kenaikannya.
                ['cmc' => 0.0001, 'u_standar' => 0.001],
            );

            $u = $h['ketidakpastian_diperluas'];

            if ($sebelumnya !== null) {
                $this->assertGreaterThan(
                    $sebelumnya,
                    $u,
                    "U95 nggak naik waktu sebaran dinaikin ke {$sebar}.",
                );
            }

            $sebelumnya = $u;
        }
    }

    /**
     * Bahan budget yang tidak masuk akal ditolak dengan pesan, bukan diam-diam
     * menghasilkan angka.
     */
    public function test_bahan_budget_cacat_ditolak_bukan_dikarang(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->hitung('holmium', [[1, 279.6, [279.6, 279.6]]], ['u_standar' => -1.0]);
    }

    public function test_kelompok_tanpa_titik_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->hitung('holmium', []);
    }

    /** STDEV sampel (pembagi n−1) — pembanding independen. */
    private function stdevSampel(array $nilai): float
    {
        $n = count($nilai);
        $rata = array_sum($nilai) / $n;
        $jumlah = 0.0;

        foreach ($nilai as $v) {
            $jumlah += ($v - $rata) ** 2;
        }

        return sqrt($jumlah / ($n - 1));
    }
}
