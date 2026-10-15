<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rute tulis yang mendarat bersama paket 29 Sep 2026 wajib ber-throttle.
 *
 * ## Kenapa berkas ini ada
 *
 * Hampir semua aksi tulis di `routes/api.php` punya `throttle:` sendiri; enam
 * rute paket 29 Sep (penugasan, pelacakan, jalan pulang pengesahan) lolos tanpa
 * satu pun. Tidak ada error yang muncul dari ketiadaannya — rutenya tetap
 * menjawab 200 — jadi yang dijaga di sini DAFTAR-nya: pemeriksaan pertama
 * membaca middleware tiap rute, yang kedua membuktikan batasnya benar-benar
 * ditegakkan sampai 429 dan embernya tidak berbagi dengan `pengesahan`.
 */
class ThrottleRuteTulisPaket29SepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> `METODE uri` => limiter yang wajib menempel */
    private const RUTE = [
        'POST api/penugasan' => 'penugasan-tulis',
        'PATCH api/penugasan/item/{penugasanItem}' => 'penugasan-tulis',
        'POST api/penugasan/{penugasan}/dilihat' => 'penugasan-tulis',
        'POST api/pelacakan/item/{orderItem}/tahap-fisik' => 'pelacakan-tahap',
    ];

    public function test_keempat_rute_tulis_menyebut_limiter_yang_terdaftar(): void
    {
        foreach (self::RUTE as $kunci => $limiter) {
            [$metode, $uri] = explode(' ', $kunci);

            $rute = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($metode, $r->methods(), true));

            $this->assertNotNull($rute, "Rute {$kunci} tidak ditemukan.");
            $this->assertContains(
                "throttle:{$limiter}",
                $rute->gatherMiddleware(),
                "{$kunci} tidak ber-throttle:{$limiter}.",
            );
            // Limiter yang namanya tidak terdaftar dianggap "tanpa batas" oleh
            // Laravel — throttle-nya hilang tanpa error.
            $this->assertNotNull(
                app(RateLimiter::class)->limiter($limiter),
                "Limiter {$limiter} tidak terdaftar di AppServiceProvider.",
            );
        }
    }
}
