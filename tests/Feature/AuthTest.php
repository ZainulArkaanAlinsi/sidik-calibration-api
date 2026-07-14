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
        return User::factory()->admin()->create([
            'employee_id' => 'ASM-0001',
            'name' => 'Admin ASMO',
            'department' => 'Quality Control',
            'email' => 'admin@asmo.test',
            'password' => 'rahasia123',
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

    public function test_login_pakai_email_balikin_token_dan_user(): void
    {
        $this->admin();

        $this->postJson('/api/login', [
            'identifier' => 'admin@asmo.test',
            'password' => 'rahasia123',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'nama', 'email', 'employee_id', 'role', 'status', 'department', 'organization_id'],
                ],
            ])
            ->assertJsonPath('data.user.nama', 'Admin ASMO')
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.status', 'aktif');
    }

    public function test_login_pakai_id_pegawai_juga_jalan(): void
    {
        $this->admin();

        $this->postJson('/api/login', [
            'identifier' => 'ASM-0001',
            'password' => 'rahasia123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.employee_id', 'ASM-0001');
    }

    public function test_login_dengan_password_salah_balikin_401(): void
    {
        $this->admin();

        $this->postJson('/api/login', [
            'identifier' => 'ASM-0001',
            'password' => 'ngasal',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'ID pegawai / email atau password salah.');
    }

    public function test_login_tanpa_identifier_balikin_422(): void
    {
        $this->postJson('/api/login', ['password' => 'rahasia123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');
    }

    public function test_akun_pending_ditolak_403_walau_password_benar(): void
    {
        User::factory()->pending()->create([
            'employee_id' => 'ASM-0099',
            'email' => 'eko@asmo.test',
            'password' => 'rahasia123',
        ]);

        $this->postJson('/api/login', [
            'identifier' => 'ASM-0099',
            'password' => 'rahasia123',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun kamu belum disetujui admin. Tunggu konfirmasi dulu ya.');
    }

    public function test_akun_nonaktif_ditolak_403(): void
    {
        User::factory()->create([
            'employee_id' => 'ASM-0077',
            'email' => 'nonaktif@asmo.test',
            'password' => 'rahasia123',
            'status' => User::STATUS_NONAKTIF,
        ]);

        $this->postJson('/api/login', [
            'identifier' => 'ASM-0077',
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
