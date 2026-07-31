<?php

namespace Tests\Unit;

use App\Support\Angka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * U95 harus kebaca 2 angka penting, apa pun resolusi alatnya.
 *
 * Kolom hasil lain ngikut desimal alat, dan itu bener — nulis lebih banyak
 * dari yang bisa dibaca alatnya itu ngaku-ngaku presisi. Ketidakpastian beda:
 * dipaksa ikut desimal alat, `0,0234` kecetak `0,02` dan kehilangan setengah
 * nilainya. Di sertifikat terakreditasi itu langsung ngubah kesimpulan
 * PASS/FAIL waktu pelanggan ngebandingin sama toleransinya.
 */
class AngkaKetidakpastianTest extends TestCase
{
    /** @return array<string, array{float, int, string}> */
    public static function kasus(): array
    {
        return [
            // U yang udah besar NGGAK berubah — desimal alat udah cukup.
            'besar, 2 desimal cukup' => [0.2199435, 2, '0,22'],
            'satu desimal cukup' => [1.7117242, 1, '1,7'],

            // Ini yang dulu rusak: kecetak "0,03" dan "0,02".
            'kecil, naik ke 3 desimal' => [0.02658849, 2, '0,027'],
            'CMC pH 4' => [0.02343221, 2, '0,023'],
            'CMC pH 7' => [0.02110895, 2, '0,021'],
            'CMC pH 10' => [0.031, 2, '0,031'],

            // Alat 3 desimal, U lebih kecil lagi → naik ke 4.
            'sangat kecil' => [0.00234, 3, '0,0023'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_u95_selalu_kebaca_dua_angka_penting(
        float $nilai,
        int $desimalAlat,
        string $harap,
    ): void {
        $this->assertSame($harap, Angka::ketidakpastian($nilai, $desimalAlat));
    }

    public function test_desimal_alat_nggak_pernah_diturunin(): void
    {
        // Alat 4 desimal, U besar: dua angka penting cuma butuh 2 desimal,
        // tapi yang dipakai tetap 4 — desimal alat itu LANTAI, bukan target.
        $this->assertSame('0,2199', Angka::ketidakpastian(0.2199435, 4));
    }

    public function test_null_dan_nol_nggak_bikin_meledak(): void
    {
        // `log10(0)` itu -INF; tanpa penjaga, nol bikin desimalnya ngawur.
        $this->assertSame('—', Angka::ketidakpastian(null, 2));
        $this->assertSame('0,00', Angka::ketidakpastian(0.0, 2));
    }
}
