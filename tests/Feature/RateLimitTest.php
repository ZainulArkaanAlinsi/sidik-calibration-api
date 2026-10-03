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
        RateLimiter::clear('login|teknisi@sidik.test|127.0.0.1');
        RateLimiter::clear('password-reset|127.0.0.1');
    }

    /**
     * Satu lab, satu WiFi, satu IP publik: dua belas orang masuk dalam semenit
     * semuanya harus lolos. Dulu jatahnya per IP saja, jadi orang ke-11 ditolak
     * walau sandinya benar (simulasi S1b, 2 Okt 2026).
     */
    public function test_dua_belas_orang_dari_satu_wifi_semuanya_bisa_masuk(): void
    {
        $orang = User::factory()->count(12)->create();

        foreach ($orang as $user) {
            $this->postJson('/api/login', ['identifier' => $user->email, 'password' => 'password'])
                ->assertOk();
        }
    }

    /**
     * Jatah tetap per akun: menghabiskan jatah satu akun tidak boleh menyentuh
     * akun lain dari IP yang sama — dan mengganti huruf besar/kecil email tidak
     * boleh membuka ember baru, karena MySQL menganggapnya akun yang sama.
     */
    public function test_jatah_habis_hanya_untuk_akun_itu_dan_tidak_bisa_diakali_huruf_besar(): void
    {
        $sasaran = User::factory()->create(['email' => 'teknisi@sidik.test']);
        $lain = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['identifier' => $sasaran->email, 'password' => 'salah']);
        }

        $this->postJson('/api/login', ['identifier' => ' TEKNISI@Sidik.test ', 'password' => 'salah'])
            ->assertStatus(429);

        $this->postJson('/api/login', ['identifier' => $lain->email, 'password' => 'password'])
            ->assertOk();
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
