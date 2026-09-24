<?php

namespace Tests\Unit;

use App\Services\Calibration\GayaCalculator;
use Tests\TestCase;

/**
 * Pemeriksaan PER TITIK dari panduan §8.2 — yang menyorot, bukan memblokir.
 *
 * ## Kenapa yang paling penting justru butir 3
 *
 * Panduan menyebutnya sendiri: "nomor 3 yang paling sering menyelamatkan".
 * Salah ketik satu digit — `6,02` yang mestinya `5,02` — lolos SEMUA
 * pemeriksaan rentang, karena nilainya masuk akal untuk alat 100 kN. Yang
 * membongkarnya cuma membandingkannya ke sebelas bacaan tetangganya di titik
 * yang sama.
 *
 * Dan itu harus memakai MAD, bukan simpangan baku. Satu nilai yang meleset
 * jauh ikut menggelembungkan STDEV-nya sendiri, jadi z-skor berbasis STDEV
 * justru MENGECIL dan si salah ketik lolos. Median dan MAD tidak ikut
 * tergeser. `test_stdev_akan_melewatkan_yang_ditangkap_mad` membuktikan itu
 * dengan angka, bukan menyatakannya.
 *
 * ## Yang ditandai tidak pernah dibuang
 *
 * ISO/IEC 17025 klausul 7.5.2 dan AGENTS.md §Peran butir 5: nilai yang diisi
 * teknisi tidak pernah dihapus. Jadi yang diuji di sini "muncul sebagai
 * temuan", dan test terakhir mengunci bahwa bacaannya tetap utuh di hasil.
 */
class GayaValidasiTitikTest extends TestCase
{
    /**
     * Satu titik, parameter sesi apa adanya dari sesi contoh Load Cell.
     *
     * @param  array<int, float>  $bacaan
     * @return array<string, mixed>
     */
    private function hitung(array $bacaan, float $nominal = 5.0): array
    {
        return GayaCalculator::hitungTitik(
            $nominal,
            $bacaan,
            'kN',
            '100kN',
            'Pull',
            23.45,
            27.7,
        );
    }

    /** @return array<int, float> dua belas bacaan wajar di sekitar 5 kN */
    private static function wajar(): array
    {
        return [
            5.02, 5.02, 5.02,
            5.02, 5.02, 5.02,
            5.02, 5.02, 5.02,
            5.03, 5.03, 5.03,
        ];
    }

    public function test_median_ganjil_dan_genap(): void
    {
        $this->assertSame(3.0, GayaCalculator::median([5.0, 1.0, 3.0]));
        $this->assertSame(2.5, GayaCalculator::median([4.0, 1.0, 2.0, 3.0]));
        $this->assertSame(0.0, GayaCalculator::median([]));
    }

    public function test_salah_ketik_satu_digit_ditandai(): void
    {
        $bacaan = self::wajar();
        $bacaan[6] = 6.02;   // mestinya 5,02 — satu digit meleset

        $temuan = $this->hitung($bacaan)['temuan'];

        $this->assertNotEmpty(array_filter(
            $temuan,
            static fn (string $t): bool => str_contains($t, 'Bacaan ke-7'),
        ), 'Bacaan ke-7 yang meleset satu digit tidak ditandai. Isi temuan: '.implode(' | ', $temuan));
    }

    /**
     * Bukti bahwa MAD memang diperlukan, bukan sekadar pilihan gaya.
     *
     * Dengan bacaan yang sama, z-skor berbasis simpangan baku TIDAK melewati
     * 3,5 — jadi pemeriksa berbasis STDEV akan meluluskan si salah ketik.
     */
    public function test_stdev_akan_melewatkan_yang_ditangkap_mad(): void
    {
        $bacaan = self::wajar();
        $bacaan[6] = 6.02;

        $this->assertNotEmpty(
            GayaCalculator::menyimpangMad($bacaan),
            'MAD gagal menangkap salah ketiknya.',
        );

        $rata = array_sum($bacaan) / count($bacaan);
        $varian = array_sum(array_map(
            static fn (float $x): float => ($x - $rata) ** 2,
            $bacaan,
        )) / (count($bacaan) - 1);
        $zStdev = abs(6.02 - $rata) / sqrt($varian);

        $this->assertLessThan(
            3.5,
            $zStdev,
            'Premis test ini runtuh: kalau z-STDEV sudah melewati 3,5, MAD tidak lagi jadi pembeda '
            .'dan docblock kelas ini perlu ditulis ulang.',
        );
    }

    public function test_bacaan_wajar_tidak_menghasilkan_temuan_palsu(): void
    {
        $temuan = $this->hitung(self::wajar())['temuan'];

        $this->assertEmpty(array_filter(
            $temuan,
            static fn (string $t): bool => str_contains($t, 'menyimpang jauh'),
        ), 'Peringatan palsu melatih admin berhenti membacanya.');
    }

    /**
     * Semua bacaan identik memulangkan MAD nol — dan pembagi nol di sana
     * melahirkan tak-hingga kalau tidak dijaga.
     */
    public function test_bacaan_identik_tidak_meledak(): void
    {
        $this->assertSame([], GayaCalculator::menyimpangMad(array_fill(0, 12, 5.02)));
    }

    public function test_kurang_dari_tiga_bacaan_tidak_dinilai(): void
    {
        $this->assertSame([], GayaCalculator::menyimpangMad([1.0, 99.0]));
    }

    public function test_bacaan_negatif_pada_beban_positif_ditandai(): void
    {
        $bacaan = self::wajar();
        $bacaan[3] = -5.02;

        $temuan = $this->hitung($bacaan)['temuan'];

        $this->assertNotEmpty(array_filter(
            $temuan,
            static fn (string $t): bool => str_contains($t, 'Bacaan negatif'),
        ), 'Isi temuan: '.implode(' | ', $temuan));
    }

    public function test_rrpe_besar_disorot(): void
    {
        $bacaan = self::wajar();
        $bacaan[0] = 5.30;   // sebaran ~6 % dari nominal 5 kN

        $temuan = $this->hitung($bacaan)['temuan'];

        $this->assertNotEmpty(array_filter(
            $temuan,
            static fn (string $t): bool => str_contains($t, 'RRPE'),
        ), 'Isi temuan: '.implode(' | ', $temuan));
    }

    public function test_rrpe_wajar_tidak_disorot(): void
    {
        $temuan = $this->hitung(self::wajar())['temuan'];

        $this->assertEmpty(array_filter(
            $temuan,
            static fn (string $t): bool => str_contains($t, 'RRPE'),
        ));
    }

    /** Menandai TIDAK PERNAH mengubah angkanya — ISO/IEC 17025 klausul 7.5.2. */
    public function test_bacaan_yang_ditandai_tetap_utuh(): void
    {
        $bacaan = self::wajar();
        $bacaan[6] = 6.02;

        $hasil = $this->hitung($bacaan);

        $this->assertCount(12, $hasil['bacaan_kn']);
        $this->assertEqualsWithDelta(6.02, $hasil['bacaan_kn'][6], 1e-9,
            'Bacaan yang ditandai ikut berubah — data teknisi tidak boleh disentuh.');
    }
}
