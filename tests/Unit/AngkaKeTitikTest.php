<?php

namespace Tests\Unit;

use App\Support\Angka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Angka::keTitik` — koma desimal jadi titik, pemisah ribuan `Angka` dibuang,
 * dan titik desimal yang SUDAH ada tidak pernah disentuh. Dulu `0.001`
 * terbaca pemisah ribuan dan tercetak `0001` (Note Timer `183!M32`).
 */
class AngkaKeTitikTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function kasus(): array
    {
        return [
            'titik desimal yang sudah ada' => ['0.001', '0.001'],
            'ribuan + koma desimal' => ['1.000,5', '1000.5'],
            'koma desimal saja' => ['0,008', '0.008'],
            'teks master berkoma' => ['S(0,001)', 'S(0.001)'],
            'teks master bertitik' => ['S(0.001)', 'S(0.001)'],
            'ribuan bulat Angka' => ['10.000', '10000'],
            'ribuan bertingkat' => ['12.345.678,9', '12345678.9'],
            'negatif' => ['-1.000,25', '-1000.25'],
            'titik desimal lebih dari tiga digit' => ['1.23456', '1.23456'],
            'kalimat campuran' => [
                'Suhu 20,2 °C, kapasitas 1.000 g, resolusi 0.001 mm; Class A/±0,15 ml',
                'Suhu 20.2 °C, kapasitas 1000 g, resolusi 0.001 mm; Class A/±0.15 ml',
            ],
            'koma pemisah daftar tidak disentuh' => ['Speed (rpm) : 6, 12, 30', 'Speed (rpm) : 6, 12, 30'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_ke_titik(string $masuk, string $harapan): void
    {
        $this->assertSame($harapan, Angka::keTitik($masuk));
    }

    public function test_hasil_angka_selalu_sama_dengan_angka_bertitik(): void
    {
        foreach ([0.001, 0.5, 1.0, 999.999, 1000.0, 1003.36, 1234567.891, -0.004, -2500.5] as $n) {
            foreach ([0, 1, 3, 5] as $d) {
                $this->assertSame(number_format($n, $d, '.', ''), Angka::keTitik(number_format($n, $d, ',', '.')), "{$n} @ {$d}");
            }
        }
    }
}
