<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kalibrator yang di-soft-delete tidak boleh mematikan `kalibrasi:hitung-ulang`.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `Standard` memakai `SoftDeletes`, dan relasi `belongsTo` menyaring baris
 * terhapus. Jadi sesi yang kalibratornya dipensiunkan tetap menyimpan
 * `standard_id`-nya, tapi `$baris->standard` memulangkan **null** — dan itu
 * keadaan yang wajar, bukan data rusak: alat standar memang dipensiunkan, dan
 * sesi lamanya tetap harus bisa dihitung ulang.
 *
 * Kedua profil kelompok Waktu dan Frekuensi dulu membaca `$standar->id` tanpa
 * penjagaan. Akibatnya dua-duanya sekaligus:
 *
 *  1. `POST /api/calibrations/preview` memulangkan **HTTP 500** berikut jejak
 *     tumpukan — membocorkan path internal ke pemanggil.
 *  2. `kalibrasi:hitung-ulang` **MATI TOTAL**. Perintahnya tidak punya satu pun
 *     `try`/`catch` di dalam perulangannya, jadi yang hilang bukan satu sesi
 *     yang bermasalah — tapi SEMUA sesi sesudahnya di baris perintah yang sama,
 *     tanpa satu pun disebutkan gagal.
 *
 * Yang ditegakkan: perintahnya selesai, sesi lain sesudahnya tetap terhitung,
 * dan titik sesi yang kalibratornya hilang tetap terbit — cuma `standard_id`-nya
 * yang kosong, karena memang tidak ada lagi yang bisa ditunjuk.
 */
class KalibratorTerhapusTidakMematikanHitungUlangTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi ter-seed tiap alat kelompok ini, berikut sesi PEMBANDING yang
     * disebut SESUDAHNYA di baris perintah yang sama. Pembandingnya yang
     * membuktikan kerusakannya menular: kalau perintahnya mati, dia ikut hilang.
     *
     * Pembandingnya diambil dari kelompok yang sama, bukan alat sembarang:
     * `kalibrasi:hitung-ulang` baru mendukung alat berjalur per-KELOMPOK, jadi
     * sesi pH sebagai pembanding akan merah karena alasannya sendiri dan
     * menyembunyikan yang sedang diuji.
     *
     * @return array<string, array{string, string}>
     */
    public static function sesiKelompokWaktuFrekuensi(): array
    {
        return [
            //                     diuji            pembanding sesudahnya
            'Centrifuge' => ['0133-CAL-324', '0140-CAL-424'],
            'Tachometer' => ['0140-CAL-424', '015-CAL-424'],
            'Timer/Stopwatch' => ['015-CAL-424', '0133-CAL-324'],
        ];
    }

    #[DataProvider('sesiKelompokWaktuFrekuensi')]
    public function test_hitung_ulang_selesai_walau_kalibratornya_terhapus(
        string $nomorSesi,
        string $nomorPembanding,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::query()->where('nomor_sesi', $nomorSesi)->firstOrFail();
        $pembanding = CalibrationSession::query()->where('nomor_sesi', $nomorPembanding)->firstOrFail();

        // Titik pembandingnya dikosongkan dulu supaya "dia ikut terhitung"
        // benar-benar dibuktikan perintah ini, bukan sisa hasil seeder.
        $pembanding->uncertaintyCalculations()->delete();

        $titikSebelum = $sesi->uncertaintyCalculations()->count();
        $this->assertGreaterThan(0, $titikSebelum, "Sesi {$nomorSesi} nggak punya titik buat dihitung ulang.");

        // Kalibratornya dipensiunkan — persis yang terjadi waktu alat standar
        // diganti dan yang lama ditarik dari peredaran.
        Standard::whereKey($sesi->standard_id)->firstOrFail()->delete();

        $this->artisan('kalibrasi:hitung-ulang', [
            'sesi' => [$nomorSesi, $pembanding->nomor_sesi],
        ])->assertSuccessful();

        $sesi->refresh()->load('uncertaintyCalculations');

        $this->assertCount(
            $titikSebelum, $sesi->uncertaintyCalculations,
            "Sesi {$nomorSesi} kehilangan titik sesudah kalibratornya dihapus.",
        );

        // `standard_id` kosong itu jawaban yang BENAR di sini — kalibratornya
        // memang sudah tidak ada. Yang salah cuma meledak sebelum sampai sini.
        foreach ($sesi->uncertaintyCalculations as $titik) {
            $this->assertNull(
                $titik->standard_id,
                'Titik masih menunjuk kalibrator yang sudah dihapus.',
            );
        }

        // Dan sesi yang disebut SESUDAHNYA tetap terhitung: itu yang hilang
        // diam-diam waktu perintahnya mati di tengah jalan.
        $this->assertGreaterThan(
            0, $pembanding->refresh()->uncertaintyCalculations()->count(),
            'Sesi pembanding sesudahnya nggak ikut terhitung — perintahnya mati di tengah.',
        );
    }
}
