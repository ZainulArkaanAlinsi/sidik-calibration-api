<?php

namespace Tests\Unit;

use App\Models\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ngunci kurva suhu buffer pH ke angka sertifikat ASLI.
 *
 * Ini bukan test "kira-kira bener". Tiga nilai yang dicek di bawah adalah
 * `titik_ukur` yang BENERAN dicetak di sertifikat 012-CAL-524 (PT Tirta Gracia
 * Semesta Mandiri, sesi 2405.13.A) dan yang sekarang tersimpan di
 * `uncertainty_calculations`. Kalau rumus atau koefisiennya kesenggol, angkanya
 * meleset dan test ini yang teriak duluan — sebelum sertifikat salah kecetak.
 *
 * Suhu yang dipakai suhu LARUTAN dari sheet `PERHITUNGAN` (After Adjustment),
 * bukan suhu ruang 21,4 °C.
 */
class StandardSuhuTest extends TestCase
{
    /**
     * Koefisien dari `Master Olah Data_pH for trial.xlsm`, sheet
     * `Nilai koefisien Sensitifitas`.
     *
     * @return array<string, array{0: array{float, float, float}, 1: float, 2: float}>
     */
    public static function bufferProvider(): array
    {
        return [
            // [a, b, c], suhu larutan, nilai yang tercetak di sertifikat
            'pH 4.01 @ 22,18 °C' => [[0.00003, -0.0023, 4.0455], 22.18, 4.009244572],
            'pH 7.00 @ 22,2 °C' => [[0.00008, -0.0076, 7.1182], 22.2, 6.9889072],
            'pH 10.01 @ 22,1 °C' => [[0.00009, -0.0148, 10.262], 22.1, 9.9788769],
        ];
    }

    /**
     * @param  array{float, float, float}  $koef
     */
    #[DataProvider('bufferProvider')]
    public function test_kurva_suhu_reproduksi_nilai_di_sertifikat_asli(
        array $koef,
        float $suhuLarutan,
        float $nilaiSertifikat,
    ): void {
        $standar = new Standard([
            'koef_suhu_a' => $koef[0],
            'koef_suhu_b' => $koef[1],
            'koef_suhu_c' => $koef[2],
        ]);

        // Toleransi 1e-9: yang dikejar kecocokan sampai digit terakhir yang
        // dicetak di sertifikat, bukan "mendekati".
        $this->assertEqualsWithDelta(
            $nilaiSertifikat,
            $standar->nilaiPadaSuhu($suhuLarutan),
            1e-9,
        );
    }

    /**
     * Suhu ruang (21,4 °C) sengaja diuji BEDA hasilnya dari suhu larutan
     * (22,18 °C). Kalau suatu saat ada yang ngasih suhu ruang ke fungsi ini,
     * nilai standarnya meleset ~0,0008 pH — kecil, tapi langsung kebawa ke
     * error tiap titik dan ke keputusan PASS/FAIL yang mepet.
     */
    public function test_suhu_ruang_beda_hasil_dari_suhu_larutan(): void
    {
        $buffer4 = new Standard([
            'koef_suhu_a' => 0.00003,
            'koef_suhu_b' => -0.0023,
            'koef_suhu_c' => 4.0455,
        ]);

        $this->assertNotEqualsWithDelta(
            $buffer4->nilaiPadaSuhu(22.18),
            $buffer4->nilaiPadaSuhu(21.4),
            1e-6,
        );
    }

    /** Standar panjang/massa nggak punya kurva suhu — jangan ngarang nilai. */
    public function test_standar_tanpa_koefisien_balikin_null(): void
    {
        $gaugeBlock = new Standard(['nama' => 'Gauge Block Set Grade 0']);

        $this->assertFalse($gaugeBlock->sensitifSuhu());
        $this->assertNull($gaugeBlock->nilaiPadaSuhu(22.18));
    }
}
