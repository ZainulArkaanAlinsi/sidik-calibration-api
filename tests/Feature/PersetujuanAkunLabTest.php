<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persetujuan akun orang lab: admin meninjau akun `pending`, menyetujui atau
 * menolaknya. Aturannya dari docs/kontrak-api.md.
 *
 * Yang MEMBUAT akun `pending` bukan lagi pendaftaran mandiri — rutenya dicabut
 * (AGENTS.md §Akun Lahir dari Undangan), dan test yang menjaga "pintunya
 * beneran nggak ada" hidup di GerbangAplikasiTokenTest. Layar peninjauannya
 * sendiri TETAP dipakai: akun `pending` masih lahir dari panel admin, dan baris
 * `pending` yang sudah telanjur ada di produksi tetap harus bisa diputuskan.
 */
class PersetujuanAkunLabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // User nempel ke organisasi (FK), jadi organisasinya harus ada duluan.
        Organization::factory()->create();
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
            'employee_id' => 'SDK-0099',
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
            'identifier' => 'SDK-0099',
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
