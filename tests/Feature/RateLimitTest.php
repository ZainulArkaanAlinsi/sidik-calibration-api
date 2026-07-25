<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regresi buat bug yang ketemu waktu tes manual 14 Jul:
 *
 * `throttle:5,1` bawaan bikin SEMUA endpoint publik berbagi satu jatah per IP,
 * karena buat request tanpa login Laravel nyusun kuncinya dari sha1(domain|ip)
 * doang — nama route-nya nggak ikut. Efeknya: orang yang salah password
 * beberapa kali langsung kena 429 waktu mau minta reset password.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        RateLimiter::clear('login|127.0.0.1');
        RateLimiter::clear('password-reset|127.0.0.1');
    }

    public function test_gagal_login_berkali_kali_nggak_ngabisin_jatah_forgot_password(): void
    {
        User::factory()->create(['email' => 'teknisi@sidik.test']);

        // Habisin jatah login (10/menit).
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['identifier' => 'teknisi@sidik.test', 'password' => 'salah'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/login', ['identifier' => 'teknisi@sidik.test', 'password' => 'salah'])
            ->assertStatus(429);

        // Justru orang yang lupa password itu yang salah login berkali-kali.
        // Dia HARUS tetap bisa minta reset.
        $this->postJson('/api/forgot-password', ['email' => 'teknisi@sidik.test'])
            ->assertOk();
    }

    public function test_jatah_login_habis_balikin_429_dengan_pesan_yang_layak_ditampilin(): void
    {
        User::factory()->create(['email' => 'teknisi@sidik.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['identifier' => 'teknisi@sidik.test', 'password' => 'salah']);
        }

        $this->postJson('/api/login', ['identifier' => 'teknisi@sidik.test', 'password' => 'salah'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Kebanyakan percobaan. Tunggu sebentar, terus coba lagi.');
    }
}
