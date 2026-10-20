<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Keputusan pemilik proyek 20 Okt 2026: `viewer` tidak lagi ditawarkan untuk
 * akun BARU. Akun viewer lama tetap sah — nilai kolom `role` tidak disentuh,
 * dan [User::roles()] (gerbang channel, daftar pengguna) tetap memuatnya.
 */
class ViewerAkunBaruTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::factory()->create();
    }

    public function test_daftar_role_akun_baru_tanpa_viewer(): void
    {
        $this->assertSame([User::ROLE_ADMIN, User::ROLE_TEKNISI], User::rolesAkunBaru());
        // Akun lama tetap sah: daftar role internal tidak berubah.
        $this->assertContains(User::ROLE_VIEWER, User::roles());
        $this->assertContains(User::ROLE_VIEWER, User::rolesInternal());
    }

    public function test_approve_pendaftar_jadi_viewer_ditolak(): void
    {
        $admin = User::factory()->admin()->create();
        $pendaftar = User::factory()->pending()->create();

        $this->actingAs($admin)
            ->postJson("/api/users/{$pendaftar->id}/approve", ['role' => User::ROLE_VIEWER])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(User::STATUS_PENDING, $pendaftar->fresh()->status);
    }

    public function test_akun_viewer_lama_tetap_bisa_masuk_dan_membaca(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->getJson('/api/equipments')->assertOk();
        $this->actingAs($viewer)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', User::ROLE_VIEWER);
    }

    public function test_form_buat_akun_di_panel_menolak_viewer(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Calon Viewer',
                'email' => 'calon@lab.test',
                'role' => User::ROLE_VIEWER,
                'status' => User::STATUS_AKTIF,
                'password' => 'SandiAwal#2026',
            ])
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'calon@lab.test']);
    }

    public function test_form_ubah_akun_viewer_lama_tetap_tersimpan(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        Livewire::test(EditUser::class, ['record' => $viewer->getKey()])
            ->fillForm(['department' => 'QA'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(User::ROLE_VIEWER, $viewer->fresh()->role);
        $this->assertSame('QA', $viewer->fresh()->department);
    }
}
