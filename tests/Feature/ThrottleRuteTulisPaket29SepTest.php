<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
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
        'POST api/calibrations/{calibration}/tarik-pengajuan' => 'pengesahan-balik',
        'POST api/calibrations/{calibration}/kembalikan-dari-pengesahan' => 'pengesahan-balik',
    ];

    public function test_keenam_rute_tulis_menyebut_limiter_yang_terdaftar(): void
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

    public function test_jalan_pulang_pengesahan_kena_429_sesudah_dua_puluh_ketukan(): void
    {
        $admin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'aktif',
        ]);

        // Id yang tidak ada: throttle jalan sebelum pengikatan model, dan 404
        // tetap menghitung satu ketukan. Yang diuji embernya, bukan isinya.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($admin)
                ->postJson('/api/calibrations/999999/tarik-pengajuan')
                ->assertNotFound();
        }

        $this->actingAs($admin)
            ->postJson('/api/calibrations/999999/tarik-pengajuan')
            ->assertStatus(429);
    }
}
