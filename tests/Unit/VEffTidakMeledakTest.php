<?php

namespace Tests\Unit;

use App\Services\GumCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pembacaan yang semuanya sama persis nggak boleh bikin `v_eff` meledak.
 *
 * ## Kenapa test ini ada
 *
 * Alat stabil yang dibaca lima kali ngasih angka yang sama lima kali — itu
 * kejadian paling biasa di lapangan, bukan kasus tepi. Tapi `1,74` nggak bisa
 * diwakili persis dalam biner, jadi rata-ratanya meleset satu bit dan standar
 * deviasinya keluar 2,48e-16 — BUKAN nol.
 *
 * Sisa sekecil itu lolos penjaga `typeA > 0`, terus dipangkat empat di
 * penyebut Welch-Satterthwaite sampai `v_eff`-nya 1,1e59. Kolom
 * `uncertainty_calculations.derajat_kebebasan_efektif` itu `decimal(20,8)` —
 * maksimum ~1e12 — jadi MySQL nolak barisnya dan SELURUH pengiriman lembar
 * kerja balik HTTP 500. Diuji 20 Agu 2026 di Chlorin Meter asli (equipment 9)
 * dengan lima kali 1,74 mg/L: kerjaan teknisi ilang, tanpa satu pun pesan yang
 * nyebut sebabnya.
 *
 * Suite-nya HIJAU selama bug itu hidup, karena test jalan di SQLite yang
 * tipenya longgar dan nerima 1,1e59 tanpa protes. Makanya yang dikunci di sini
 * BUKAN "bisa disimpen", tapi angkanya sendiri — itu yang kebawa ke MySQL.
 */
class VEffTidakMeledakTest extends TestCase
{
    private function stdev(array $pembacaan): float
    {
        $metode = new ReflectionMethod(GumCalculator::class, 'standarDeviasiSampel');
        $metode->setAccessible(true);

        return $metode->invoke(
            app(GumCalculator::class),
            $pembacaan,
            array_sum($pembacaan) / count($pembacaan),
        );
    }

    /**
     * Titik ukur dari sembilan profil yang ada. `1.74` & `1.83` (Chlorin) itu
     * yang beneran meledak; sisanya ikut dikunci supaya perubahan cara ngitung
     * rata-rata nggak diam-diam mindahin masalahnya ke titik lain.
     *
     * @return array<string, array{float}>
     */
    public static function titikSemuaProfil(): array
    {
        return [
            'chlorine 1,74' => [1.74],
            'chlorine 1,83' => [1.83],
            'pH 4' => [4.0],
            'pH 7' => [7.0],
            'pH 10,01' => [10.01],
            'DO 8,77' => [8.77],
            'turbidity 1' => [1.0],
            'turbidity 100' => [100.0],
            'turbidity 1000' => [1000.0],
            'refracto 1,33659' => [1.33659],
            'conductivity 1412' => [1412.0],
            'conductivity 1,412' => [1.412],
            'visco 99,65' => [99.65],
            'visco 59003' => [59003.0],
        ];
    }

    #[DataProvider('titikSemuaProfil')]
    public function test_pembacaan_identik_ngasih_stdev_nol_bulat(float $titik): void
    {
        $this->assertSame(
            0.0,
            $this->stdev(array_fill(0, 5, $titik)),
            "Lima pembacaan {$titik} yang sama persis harus ngasih standar deviasi nol bulat. ".
            'Sisa pembulatan biner di sini bakal dipangkat empat di penyebut Welch-Satterthwaite '.
            'dan bikin v_eff nembus batas kolom decimal(20,8).',
        );
    }

    public function test_sebaran_yang_beneran_ada_tetap_kehitung(): void
    {
        // Ambangnya relatif (1e-12 × skala), jadi sebaran sebeneran — sekecil
        // apa pun yang masih masuk akal buat resolusi alat — nggak boleh ikut
        // kepangkas jadi nol.
        $this->assertGreaterThan(0.0, $this->stdev([1.74, 1.75, 1.74, 1.74, 1.75]));
        $this->assertGreaterThan(0.0, $this->stdev([59003.0, 59003.1, 59003.0, 59002.9, 59003.0]));

        // Resolusi terhalus di repo ini 1e-5 (Refractometer n20D). Sebaran
        // satu resolusi harus tetap kebaca.
        $this->assertGreaterThan(0.0, $this->stdev([1.33659, 1.33660, 1.33659, 1.33659, 1.33660]));
    }

    /**
     * Batas kolom `decimal(20,8)`: 12 digit di depan koma. Dikunci sebagai
     * angka supaya kalau ada yang naikin `MAKS_V_EFF` tanpa ngubah migrasinya,
     * yang gagal test-nya — bukan pengiriman lembar kerja di lapangan.
     */
    public function test_v_eff_nggak_pernah_lewat_batas_kolom(): void
    {
        $metode = new ReflectionMethod(GumCalculator::class, 'agregasiBudget');
        $metode->setAccessible(true);

        // Satu komponen ber-vi raksasa: v_eff = vi persis, jadi ini cara
        // paling langsung buat mancing angka di luar batas.
        $hasil = $metode->invoke(app(GumCalculator::class), [
            ['u' => 0.0455, 'ci' => 1.0, 'vi' => 1.0e59],
        ], null);

        $this->assertLessThanOrEqual(
            999_999_999_999.0,
            $hasil['derajat_kebebasan_efektif'],
            'v_eff yang disimpen harus muat di decimal(20,8), kalau nggak MySQL nolak seluruh sesinya.',
        );

        // Yang dipotong cuma jejak auditnya — `k` harus tetap nempel di
        // t(0,975; ∞) = 1,95996, jadi angka sertifikatnya nggak berubah.
        // Delta 1e-4 nampung galat `StudentTDistribution` di derajat kebebasan
        // sebesar ini; bedanya 5e-5, jauh di bawah desimal mana pun yang
        // kecetak di sertifikat.
        $this->assertEqualsWithDelta(1.959964, $hasil['faktor_cakupan_k'], 1e-4);
    }
}
