<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEMENTARA — dipakai buat MEMBUKTIKAN dugaan, bukan buat di-commit apa adanya.
 *
 * Dugaan: `throttle:N,1` (limiter ANGKA) buat request yang SUDAH LOGIN bikin
 * kuncinya cuma dari id user — nama route-nya nggak ikut. Kalau benar, semua
 * endpoint ber-throttle angka di dalam `auth:sanctum` berbagi SATU ember per
 * user, dan endpoint berjatah kecil ikut habis gara-gara endpoint berjatah besar.
 */
class JatahThrottleTidakBerbagiEmberTest extends TestCase
{
    use RefreshDatabase;

    public function test_jatah_export_20_tidak_ikut_habis_gara_gara_preview_120(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'aktif',
        ]);

        // 21 panggilan ke endpoint berjatah 120/menit. Body sengaja kosong —
        // yang diuji middleware throttle, yang jalan SEBELUM validasi.
        for ($i = 0; $i < 21; $i++) {
            $this->actingAs($admin)->postJson('/api/calibrations/preview', []);
        }

        // Endpoint LAIN, jatahnya sendiri 20/menit, belum pernah dipanggil
        // sama sekali. Kalau embernya terpisah, ini TIDAK boleh 429.
        $respons = $this->actingAs($admin)->getJson('/api/audit-logs/export');

        $this->assertNotSame(
            429,
            $respons->getStatusCode(),
            'GET /api/audit-logs/export membalas 429 padahal belum pernah dipanggil — '
            .'jatahnya kehabisan gara-gara POST /api/calibrations/preview. '
            .'Artinya limiter angka berbagi satu ember per user.',
        );
    }
}
