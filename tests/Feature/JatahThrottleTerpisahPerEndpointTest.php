<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tiap endpoint ber-throttle punya EMBERNYA SENDIRI.
 *
 * ## Kenapa berkas ini ada
 *
 * `throttle:N,1` — limiter ANGKA — menyusun kuncinya lewat
 * `ThrottleRequests::resolveRequestSignature()`, dan **nama route-nya tidak
 * ikut**:
 *
 *     if ($user = $request->user()) return formatIdentifier($user->getAuthIdentifier());
 *     elseif ($route = $request->route()) return formatIdentifier($route->getDomain().'|'.$request->ip());
 *
 * Argumen ketiga `$prefix` yang bisa memisahkannya bawaannya kosong, dan tidak
 * ada satu route pun yang mengisinya. Jadi tiap request yang sudah login
 * menabung ke SATU counter per user, dan tiap request tamu ke SATU counter per
 * IP — sementara batas yang diadu ke counter itu beda-beda per route.
 *
 * Akibatnya jatah endpoint berangka kecil habis gara-gara endpoint berangka
 * besar yang tidak ada hubungannya. Teknisi memindai satu lembar kerja
 * (puluhan panggilan `crop`, jatah 300) mengunci `extract-from-photo` (30) dan
 * semua endpoint 20/menit selama sisa menit itu — dan yang muncul di HP-nya
 * "Kebanyakan percobaan", untuk tombol yang belum pernah dia tekan.
 *
 * Bentuk cacat yang SAMA sudah ditambal untuk `login`/`register`/
 * `password-reset` (lihat `AppServiceProvider::rateLimiters()`); yang di sini
 * sisa yang tidak ikut waktu itu.
 */
class JatahThrottleTerpisahPerEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'aktif',
        ]);
    }

    /**
     * Jalur SUDAH LOGIN: `/calibrations/preview` (120/menit) tidak boleh
     * menghabiskan jatah `/audit-logs/export` (20/menit).
     *
     * Body preview sengaja kosong — yang diuji middleware throttle, yang jalan
     * sebelum validasi. 422-nya tetap menghitung satu ketukan.
     */
    public function test_jatah_export_tidak_ikut_habis_gara_gara_preview(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 21; $i++) {
            $this->actingAs($admin)->postJson('/api/calibrations/preview', []);
        }

        $this->assertNotSame(
            429,
            $this->actingAs($admin)->getJson('/api/audit-logs/export')->getStatusCode(),
            'GET /api/audit-logs/export membalas 429 padahal belum pernah dipanggil — '
            .'jatahnya habis gara-gara POST /api/calibrations/preview. Embernya berbagi.',
        );
    }

    /**
     * Jalur TAMU: halaman verifikasi QR (30/menit) tidak boleh menghabiskan
     * jatah tombol unduhnya (10/menit).
     *
     * Token-nya sengaja tidak ada — throttle jalan sebelum controller, jadi
     * 404 pun tetap menghitung satu ketukan. Yang diuji embernya, bukan
     * isinya.
     *
     * Ini yang paling kelihatan ke ORANG LUAR: pelanggan yang membuka halaman
     * sertifikatnya sebelas kali menemukan tombol unduhnya mati, tanpa pernah
     * menekannya sekali pun.
     */
    public function test_jatah_unduh_verifikasi_tidak_ikut_habis_gara_gara_halaman_verifikasi(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->get('/verify/token-yang-nggak-ada');
        }

        $this->assertNotSame(
            429,
            $this->get('/verify/token-yang-nggak-ada/download')->getStatusCode(),
            'Tombol unduh sertifikat membalas 429 padahal belum pernah ditekan — '
            .'jatahnya habis gara-gara halaman verifikasinya sendiri dibuka berkali-kali.',
        );
    }

    /**
     * Happy path — batasnya sendiri tetap ditegakkan.
     *
     * Tanpa ini, "perbaikan" yang diam-diam mematikan throttle-nya juga lulus
     * dua test di atas.
     */
    public function test_jatah_sendiri_tetap_ditegakkan_sampai_429(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($admin)->getJson('/api/audit-logs/export')->assertStatus(200);
        }

        $this->actingAs($admin)
            ->getJson('/api/audit-logs/export')
            ->assertStatus(429);
    }

    /**
     * Dua admin berbeda tidak boleh berbagi jatah.
     *
     * Kunci berbasis IP kelihatan masuk akal sampai diingat sepuluh teknisi
     * satu lab keluar lewat SATU alamat IP — di situ jatah per-IP artinya
     * teknisi pertama yang sibuk mengunci sembilan temannya.
     */
    public function test_dua_admin_punya_jatah_masing_masing(): void
    {
        $satu = $this->admin();
        $dua = $this->admin();

        for ($i = 0; $i < 21; $i++) {
            $this->actingAs($satu)->getJson('/api/audit-logs/export');
        }

        $this->assertNotSame(
            429,
            $this->actingAs($dua)->getJson('/api/audit-logs/export')->getStatusCode(),
            'Admin kedua kena 429 gara-gara admin pertama — jatahnya kekunci per IP, bukan per orang.',
        );
    }
}
