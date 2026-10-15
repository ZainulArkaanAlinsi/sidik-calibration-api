<?php

namespace Tests\Unit;

use App\Support\Angka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Angka::hasil()` membulatkan seperti TAMPILAN Excel (15 angka penting dulu,
 * lalu setengah menjauhi nol), karena master lab adalah workbook Excel dan
 * yang tercetak di sertifikatnya adalah tampilan sel, bukan nilai biner.
 */
class AngkaTampilanExcelTest extends TestCase
{
    /**
     * Nilai yang jauh dari titik tengah: hasilnya identik dengan
     * `number_format()` yang dipakai sebelumnya.
     *
     * @return array<string, array{float, int, string}>
     */
    public static function nilaiBiasa(): array
    {
        return [
            'positif 1 desimal' => [14.34625, 1, '14,3'],
            'negatif 1 desimal' => [-0.65375, 1, '-0,7'],
            'dua desimal' => [67.032, 2, '67,03'],
            'tanpa ribuan' => [1234.567, 2, '1234,57'],
            'nol desimal' => [1.9616, 0, '2'],
            'carry ke ratusan' => [99.96, 1, '100,0'],
            'kecil' => [0.000123456, 6, '0,000123'],
            'tie biner pasti' => [2.675, 2, '2,68'],
            'tie 0,15' => [0.15, 1, '0,2'],
            'tie negatif' => [-0.05, 1, '-0,1'],
            'nol' => [0.0, 1, '0,0'],
        ];
    }

    #[DataProvider('nilaiBiasa')]
    public function test_nilai_biasa_tidak_berubah(float $nilai, int $desimal, string $harap): void
    {
        $this->assertSame($harap, Angka::hasil($nilai, $desimal));
    }

    /**
     * Nilai tepat-tengah secara desimal yang tersimpan SEDIKIT di bawah tengah
     * (½ × selisih dua pembacaan). Excel menampilkannya `0,2`; pembulatan
     * biner eksak dulu mencetak `0,1`.
     */
    public function test_nilai_di_bawah_tengah_tercetak_seperti_excel(): void
    {
        $this->assertSame('0,1', number_format(0.1499999999999996, 1, ',', ''), 'Perilaku lama sebagai pembanding.');
        $this->assertSame('0,2', Angka::hasil(0.1499999999999996, 1));
        $this->assertSame('-0,2', Angka::hasil(-0.1499999999999996, 1));
    }

    public function test_tanda_nol_tetap_dihormati(): void
    {
        $this->assertSame('-0,0', Angka::hasil(-0.04, 1));
        $this->assertSame('0,0', Angka::hasil(-0.04, 1, tandaNol: false));
    }
}
