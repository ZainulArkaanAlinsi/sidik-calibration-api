<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alur daftar mandiri → nunggu approval admin. Aturannya dari docs/kontrak-api.md.
 */
class RegisterApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // User nempel ke organisasi (FK), jadi organisasinya harus ada duluan.
        Organization::factory()->create();
    }

    /** @var array<string, string> */
    private array $pendaftar = [
        'nama' => 'Eko Prasetyo',
        'employee_id' => 'ASM-0099',
        'department' => 'Kalibrasi',
        'email' => 'eko@ptasmo.com',
        'password' => 'rahasia123',
    ];

    public function test_register_bikin_akun_pending_dengan_role_teknisi(): void
    {
        $this->postJson('/api/register', $this->pendaftar)
            ->assertCreated()
            ->assertJsonPath('message', 'Pendaftaran terkirim. Akun menunggu persetujuan admin.');

        $this->assertDatabaseHas('users', [
            'email' => 'eko@ptasmo.com',
            'employee_id' => 'ASM-0099',
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_PENDING,
        ]);
    }

    public function test_pendaftar_tidak_bisa_milih_role_sendiri(): void
    {
        // Ini serangan aslinya: daftar sambil nyelipin role admin.
        $this->postJson('/api/register', [...$this->pendaftar, 'role' => User::ROLE_ADMIN])
            ->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'eko@ptasmo.com',
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_PENDING,
        ]);
    }

    public function test_email_dan_id_pegawai_dobel_ditolak_422(): void
    {
        User::factory()->create([
            'email' => 'eko@ptasmo.com',
            'employee_id' => 'ASM-0099',
        ]);

        $this->postJson('/api/register', $this->pendaftar)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'employee_id'])
            ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar.')
            ->assertJsonPath('errors.employee_id.0', 'ID pegawai ini sudah terdaftar.');
    }

    public function test_password_kurang_dari_8_karakter_ditolak(): void
    {
        $this->postJson('/api/register', [...$this->pendaftar, 'password' => 'pendek'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_admin_bisa_lihat_daftar_akun_pending(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->pending()->create(['name' => 'Eko Prasetyo']);
        User::factory()->create(['name' => 'Yang Udah Aktif']);

        $this->actingAs($admin)->getJson('/api/users?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Eko Prasetyo')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_admin_approve_bikin_akun_jadi_aktif_dan_bisa_login(): void
    {
        $admin = User::factory()->admin()->create();
        $pendaftar = User::factory()->pending()->create([
            'employee_id' => 'ASM-0099',
            'password' => 'rahasia123',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/users/{$pendaftar->id}/approve", ['role' => User::ROLE_TEKNISI])
            ->assertOk()
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.role', 'teknisi');

        $this->app['auth']->forgetGuards();

        // Sebelum di-approve dia 403; sekarang harus bisa masuk.
        $this->postJson('/api/login', [
            'identifier' => 'ASM-0099',
            'password' => 'rahasia123',
        ])->assertOk();
    }

    public function test_approve_dengan_role_ngawur_ditolak(): void
    {
        $admin = User::factory()->admin()->create();
        $pendaftar = User::factory()->pending()->create();

        $this->actingAs($admin)
            ->postJson("/api/users/{$pendaftar->id}/approve", ['role' => 'superadmin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_reject_bikin_akun_nonaktif(): void
    {
        $admin = User::factory()->admin()->create();
        $pendaftar = User::factory()->pending()->create();

        $this->actingAs($admin)
            ->postJson("/api/users/{$pendaftar->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'nonaktif');
    }

    public function test_teknisi_tidak_boleh_buka_endpoint_approval(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $pendaftar = User::factory()->pending()->create();

        $this->actingAs($teknisi)->getJson('/api/users?status=pending')->assertForbidden();

        $this->actingAs($teknisi)
            ->postJson("/api/users/{$pendaftar->id}/approve", ['role' => User::ROLE_ADMIN])
            ->assertForbidden();

        // Yang penting: statusnya nggak berubah gara-gara percobaan barusan.
        $this->assertDatabaseHas('users', [
            'id' => $pendaftar->id,
            'status' => User::STATUS_PENDING,
        ]);
    }

    public function test_endpoint_approval_butuh_login(): void
    {
        $this->getJson('/api/users?status=pending')->assertUnauthorized();
    }
}
