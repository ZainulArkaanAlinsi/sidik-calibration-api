<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\TabelStandarPutaran;
use App\Services\Calibration\TabelStandarWaktu;
use App\Support\WaktuMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Set point di luar JANGKAUAN sertifikat kalibrator ditolak dengan alasan yang
 * kebaca — bukan dihitung dari baris terdekat.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `nominalTerdekat()` di kedua tabel standar memungut nominal berjarak terkecil
 * **tanpa batas**. Jadi set point berapa pun mendapat padanan, betapa pun
 * jauhnya. Terukur sebelum perbaikan:
 *
 *     Stopwatch  sp = 7200 s   -> nominal 3600 s, koreksi −20 ms, U95 0,81 s
 *     Stopwatch  sp = 86400 s  -> nominal 3600 s  (jaraknya 23x lipat)
 *     Stopwatch  sp = 1 s      -> nominal 5 s, koreksi +30 ms (3% penunjukan)
 *     Tachometer sp = 500000 rpm -> nominal 30000 rpm, koreksi −2,0 rpm
 *
 * Semuanya terbit lengkap dengan U95 dan lantai CMC, tanpa satu pun peringatan.
 *
 * Yang paling menyesatkan: DUA penjagaan yang memang ditulis untuk ini jadi kode
 * mati. `WaktuCalculator` memeriksa `koreksiMs($nominal) === null` dan
 * `u95Sertifikat($nominal) === null`, `PutaranCalculator` memeriksa
 * `koreksi($nominal) === null` — tapi `$nominal`-nya diambil dari daftar itu
 * sendiri, jadi ketiganya selalu ketemu dan cabang penolakannya tidak punya
 * jalan masuk. Cabang yang tak terjangkau itu bahkan menyimpan kesalahan
 * fatalnya sendiri (`end()` atas nilai balik fungsi → "Only variables should be
 * passed by reference"), yang baru terbit setelah jalannya dibuka.
 *
 * Lembar kedua kelompok ini `titik_bisa_diubah`, jadi ini bukan kemungkinan
 * teoretis: teknisi memang boleh mengetik set point di luar saran.
 */
class NominalDiLuarSertifikatDitolakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Set point di luar jangkauan sertifikat kalibrator, atas dan bawah.
     *
     * @return array<string, array{string, float}>
     */
    public static function diLuarJangkauan(): array
    {
        return [
            // Tabel rpm 60–30000; tabel waktu 5–3600 detik.
            'Tachometer di atas' => ['0140-CAL-424', 500000.0],
            'Tachometer di bawah' => ['0140-CAL-424', 30.0],
            'Centrifuge di atas' => ['0133-CAL-324', 120000.0],
            'Centrifuge di bawah' => ['0133-CAL-324', 10.0],
        ];
    }

    #[DataProvider('diLuarJangkauan')]
    public function test_titik_putaran_di_luar_jangkauan_masuk_belum_dihitung(
        string $nomorSesi,
        float $setPoint,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->with('equipment')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);
        $standar = Standard::find($sesi->standard_id);

        $hasil = $profil->hitungPerGrup([[
            'titik_ke' => 1,
            'titik_ukur' => $setPoint,
            'pembacaan' => [$setPoint, $setPoint, $setPoint],
            'standard' => $standar,
        ]], $sesi->equipment);

        $this->assertSame(
            [], $hasil['hitungan'],
            "Set point {$setPoint} rpm di luar jangkauan sertifikat tetap dihitung — "
            .'koreksinya hasil ekstrapolasi dari baris terdekat.',
        );

        $alasan = $hasil['belum_dihitung'][0]['alasan'] ?? '';

        $this->assertStringContainsString(
            'di luar jangkauan sertifikat', $alasan,
            "Titiknya ditolak tapi alasannya nggak menyebut sebabnya: {$alasan}",
        );
        $this->assertStringContainsString(
            '60', $alasan,
            "Alasannya nggak menyebut jangkauan sertifikatnya: {$alasan}",
        );
    }

    /**
     * Set point Timer di luar 5–3600 detik ditolak juga.
     *
     * @return array<string, array{float}>
     */
    public static function waktuDiLuarJangkauan(): array
    {
        return [
            'dua jam' => [7200.0],
            'sehari' => [86400.0],
            'satu detik' => [1.0],
        ];
    }

    #[DataProvider('waktuDiLuarJangkauan')]
    public function test_titik_waktu_di_luar_jangkauan_masuk_belum_dihitung(float $setPointDetik): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '015-CAL-424')->with('equipment')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);
        $ms = $setPointDetik * 1000.0;

        $hasil = $profil->hitungPerGrup([[
            'titik_ke' => 1,
            'titik_ukur' => $setPointDetik,
            'pembacaan' => [],
            'standard' => Standard::find($sesi->standard_id),
            'konteks' => [
                WaktuMentah::PERAN_STANDAR => [$ms, $ms + 10, $ms - 10],
                WaktuMentah::PERAN_UUT => [$ms + 5, $ms + 15, $ms - 5],
            ],
        ]], $sesi->equipment);

        $this->assertSame(
            [], $hasil['hitungan'],
            "Set point {$setPointDetik} detik di luar jangkauan sertifikat tetap dihitung.",
        );

        $this->assertStringContainsString(
            'di luar jangkauan sertifikat',
            $hasil['belum_dihitung'][0]['alasan'] ?? '',
            'Alasan penolakannya nggak kebaca: '.($hasil['belum_dihitung'][0]['alasan'] ?? '(kosong)'),
        );
    }

    /**
     * Dan set point DI DALAM jangkauan tetap dihitung seperti biasa.
     *
     * Tanpa ini, "tolak semuanya" jadi hijau — dan lembar yang menolak setiap
     * titik jauh lebih buruk daripada lembar yang mengekstrapolasi.
     */
    public function test_titik_di_dalam_jangkauan_tetap_dihitung(): void
    {
        $waktu = new TabelStandarWaktu;
        $putaran = new TabelStandarPutaran;

        // Ujung-ujungnya INKLUSIF: nominal terkecil & terbesar itu baris
        // sertifikat yang nyata, bukan batas terbuka.
        $this->assertSame(5.0, $waktu->nominalTerdekat(5.0));
        $this->assertSame(3600.0, $waktu->nominalTerdekat(3600.0));
        $this->assertSame(600.0, $waktu->nominalTerdekat(900.0), 'Seri waktu diputus ke BAWAH.');

        $this->assertSame(60.0, $putaran->nominalTerdekat(60.0));
        $this->assertSame(30000.0, $putaran->nominalTerdekat(30000.0));
        $this->assertSame(200.0, $putaran->nominalTerdekat(180.0));
    }
}
