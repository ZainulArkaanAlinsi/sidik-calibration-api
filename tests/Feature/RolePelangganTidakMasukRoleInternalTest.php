<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `pelanggan` dan `super_admin` TIDAK boleh masuk `User::roles()`.
 *
 * ## Kenapa test ini ada, padahal kelihatannya sepele
 *
 * Refleks orang berikutnya waktu melihat ENUM `users.role` sudah memuat lima
 * nilai sementara `User::roles()` cuma memulangkan tiga adalah "merapikannya".
 * Kalau itu terjadi, tiga hal rusak sekaligus dan nggak satu pun memunculkan
 * error:
 *
 * 1. `routes/channels.php` memakai `in_array($user->role, User::roles())` —
 *    gerbang channel organisasi (M0-06) LANGSUNG TERBUKA buat pelanggan, dan
 *    tiap sesi & sertifikat yang lewat bocor realtime.
 * 2. `UserController` memakai `Rule::in(User::roles())` — admin bisa mencetak
 *    akun pelanggan langsung dari panel internal, melewati seluruh alur
 *    verifikasi yang menahan R-D02 (orang mengaku sebagai PT X).
 * 3. `role:admin,teknisi,viewer` di grup luar `routes/api.php` jadi nggak
 *    sejalan lagi dengan daftar yang dipakai di tempat lain.
 *
 * `roles()` artinya "role INTERNAL lab", dan itu yang dijaga di sini.
 */
class RolePelangganTidakMasukRoleInternalTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_tetap_cuma_tiga_role_internal(): void
    {
        $this->assertSame(
            [User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER],
            User::roles(),
            'User::roles() melebar. Baca docblock test ini sebelum mengubahnya.'
        );
    }

    public function test_konstanta_role_baru_memang_ada(): void
    {
        $this->assertSame('pelanggan', User::ROLE_PELANGGAN);
        $this->assertSame('super_admin', User::ROLE_SUPER_ADMIN);

        $this->assertNotContains(User::ROLE_PELANGGAN, User::roles());
        $this->assertNotContains(User::ROLE_SUPER_ADMIN, User::roles());
    }

    /** Gerbang channel M0-06 tetap menolak pelanggan sesudah role-nya ada di ENUM. */
    public function test_channel_organisasi_tetap_menolak_pelanggan(): void
    {
        $org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);

        $pelanggan = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->assertFalse(
            in_array($pelanggan->role, User::roles(), true),
            'Pelanggan lolos gerbang channel organisasi.'
        );
    }

    /** Admin internal TIDAK bisa mencetak akun pelanggan lewat `POST /api/users`. */
    public function test_admin_tidak_bisa_bikin_akun_pelanggan_dari_panel_internal(): void
    {
        $org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/'.$admin->id, ['role' => User::ROLE_PELANGGAN])
            ->assertStatus(422);
    }
}
