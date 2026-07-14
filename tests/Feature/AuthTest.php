<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bentuk response di sini dikunci sama docs/kontrak-api.md (repo mobile).
 * Kalau test ini merah gara-gara nama field berubah, berarti app mobile juga
 * bakal rusak — kabarin mobile dulu, jangan cuma benerin testnya.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'name' => 'Admin ASMO',
            'email' => 'admin@asmo.test',
            'password' => 'rahasia123',
            'role' => User::ROLE_ADMIN,
            'organization_id' => 1,
        ]);
    }

    public function test_health_bisa_diakses_tanpa_auth(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'app', 'time']);
    }

    public function test_login_dengan_kredensial_benar_balikin_token_dan_user(): void
    {
        $this->admin();

        $this->postJson('/api/login', [
            'email' => 'admin@asmo.test',
            'password' => 'rahasia123',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'nama', 'email', 'role', 'organization_id']]])
            ->assertJsonPath('data.user.nama', 'Admin ASMO')
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_login_dengan_password_salah_balikin_401(): void
    {
        $this->admin();

        $this->postJson('/api/login', [
            'email' => 'admin@asmo.test',
            'password' => 'ngasal',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Email atau password salah.');
    }

    public function test_login_tanpa_email_balikin_422_dengan_error_per_field(): void
    {
        $this->postJson('/api/login', ['password' => 'rahasia123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_akun_nonaktif_ditolak_403(): void
    {
        User::factory()->create([
            'email' => 'nonaktif@asmo.test',
            'password' => 'rahasia123',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'nonaktif@asmo.test',
            'password' => 'rahasia123',
        ])->assertForbidden();
    }

    public function test_me_butuh_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_dengan_token_balikin_user_yang_login(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@asmo.test')
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_logout_mencabut_token_yang_dipakai(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Berhasil logout.');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Guard 'auth' nyimpen user hasil resolve dari request sebelumnya (satu
        // instance app dipakai sepanjang test), jadi harus di-reset dulu — kalau
        // nggak, request di bawah lolos pakai user yang udah kesimpen di memori,
        // bukan ngecek ulang tokennya.
        $this->app['auth']->forgetGuards();

        // Token yang sama nggak boleh bisa dipakai lagi.
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
