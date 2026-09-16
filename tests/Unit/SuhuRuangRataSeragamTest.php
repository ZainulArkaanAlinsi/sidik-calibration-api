<?php

namespace Tests\Unit;

use App\Support\HeightGaugeMentah;
use App\Support\MicrometerMentah;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dua kelas, satu kunci konteks: `suhu_ruang_rata`.
 *
 * Jalur SIMPAN Height Gauge memakai `HeightGaugeMentah::rataSuhuRuang()`,
 * sementara `HitungUlangSesi` dan `CalibrationValidator` mengisi kunci yang
 * sama lewat `MicrometerMentah::rataSuhuRuang()` — satu kunci dipakai dua
 * keluarga alat, jadi tidak bisa dipisah tanpa membelah konteksnya.
 *
 * Hari ini keduanya memulangkan angka yang sama persis, jadi tidak ada sesi
 * yang bergeser. Yang berbahaya HARI ESOK: begitu salah satu diubah — dan
 * `docs/pertanyaan-lab-height-gauge.md` §10 memang masih terbuka soal suhu UUT
 * vs suhu ruangan — sesi lama akan DIHITUNG ULANG dengan rumus suhu yang beda
 * dari waktu sertifikatnya terbit, tanpa satu pun error.
 *
 * Test ini yang berbunyi duluan kalau itu terjadi.
 */
class SuhuRuangRataSeragamTest extends TestCase
{
    /**
     * @return list<array{float|null, float|null}>
     */
    public static function pasangan(): array
    {
        return [
            [20.5, 21.5],
            [20.0, 20.0],
            [null, 21.0],
            [21.0, null],
            [null, null],
            [-5.25, 30.75],
        ];
    }

    #[DataProvider('pasangan')]
    public function test_kedua_kelas_memulangkan_suhu_rata_yang_sama(?float $awal, ?float $akhir): void
    {
        $micrometer = MicrometerMentah::rataSuhuRuang($awal, $akhir);
        $heightGauge = HeightGaugeMentah::rataSuhuRuang($awal, $akhir);

        $this->assertSame(
            $micrometer,
            $heightGauge,
            'Rumus suhu ruangan rata-rata Micrometer & Height Gauge berbeda, padahal jalur simpan '
            .'dan jalur hitung ulang mengisi kunci konteks `suhu_ruang_rata` yang SAMA dari dua '
            .'kelas ini. Selama bedanya dibiarkan, sesi yang dihitung ulang tidak lagi menghasilkan '
            .'angka yang sama dengan sertifikat yang sudah terbit.',
        );
    }
}
