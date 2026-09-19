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

    /**
     * ARAH SEBALIKNYA — dan ini yang selama ini bolong.
     *
     * Test di atas menjaga "akun internal nggak bisa diturunin jadi pelanggan".
     * Yang nggak dijaga sama sekali sampai 19 Sep 2026: akun PELANGGAN dinaikin
     * jadi akun lab. `Rule::in(User::roles())` nggak menolong — dia memeriksa
     * nilai role yang MASUK, bukan akun yang DITUJU, dan `admin` memang anggota
     * sah `User::roles()`. `pastikanSatuOrganisasi()` juga nggak: pelanggan
     * PT Sidik memang duduk di organisasi PT Sidik.
     *
     * Diukur sebelum ditambal, dengan akun admin yang sah:
     *
     *     GET  /api/users                          -> 200, akun pelanggan IKUT terdaftar
     *     PUT  /api/users/{pelanggan} role=admin   -> 200, role berubah jadi `admin`
     *     POST /api/users/{pelanggan}/approve      -> 200, role berubah jadi `admin`
     *     POST /api/users/{pelanggan}/reject       -> 200, akun pelanggan dimatikan
     *
     * Sisi panel Filament sudah ditutup lebih dulu
     * (`AkunPelangganTidakBisaDipromosiDariPanelTest`); sisi API-nya ketinggalan.
     *
     * @return array{User, User, User}
     */
    private function tigaAkun(): array
    {
        $org = Organization::factory()->create(['nama' => 'PT Contoh Dua']);

        $buat = fn (string $role): User => User::factory()->create([
            'organization_id' => $org->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
        ]);

        return [$buat(User::ROLE_ADMIN), $buat(User::ROLE_PELANGGAN), $buat(User::ROLE_SUPER_ADMIN)];
    }

    public function test_akun_pelanggan_tidak_muncul_di_daftar_pengguna_api(): void
    {
        [$admin, $pelanggan, $super] = $this->tigaAkun();

        $id = collect(
            $this->actingAs($admin, 'sanctum')->getJson('/api/users')->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertContains($admin->id, $id, 'Akun lab wajib tetap kelihatan.');
        $this->assertNotContains($pelanggan->id, $id, 'Akun pelanggan bocor ke daftar pengguna internal.');
        $this->assertNotContains($super->id, $id, 'Akun super admin bocor ke daftar pengguna internal.');
    }

    public function test_akun_pelanggan_tidak_bisa_dinaikkan_jadi_admin_lewat_put(): void
    {
        [$admin, $pelanggan] = $this->tigaAkun();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$pelanggan->id}", ['role' => User::ROLE_ADMIN])
            ->assertNotFound();

        $this->assertSame(
            User::ROLE_PELANGGAN,
            $pelanggan->fresh()->role,
            'Akun pelanggan berubah jadi akun lab lewat API — R-D02.',
        );
    }

    public function test_akun_pelanggan_tidak_bisa_disetujui_jadi_internal_lewat_approve(): void
    {
        [$admin, $pelanggan] = $this->tigaAkun();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/users/{$pelanggan->id}/approve", ['role' => User::ROLE_TEKNISI])
            ->assertNotFound();

        $this->assertSame(User::ROLE_PELANGGAN, $pelanggan->fresh()->role);
    }

    public function test_akun_pelanggan_tidak_bisa_dimatikan_lewat_reject_internal(): void
    {
        [$admin, $pelanggan] = $this->tigaAkun();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/users/{$pelanggan->id}/reject")
            ->assertNotFound();

        $this->assertSame(User::STATUS_AKTIF, $pelanggan->fresh()->status);
    }

    public function test_akun_super_admin_tidak_bisa_disentuh_lewat_api(): void
    {
        [$admin, , $super] = $this->tigaAkun();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$super->id}", ['role' => User::ROLE_TEKNISI])
            ->assertNotFound();

        $this->assertSame(User::ROLE_SUPER_ADMIN, $super->fresh()->role);
    }

    /** Akun lab TETAP bisa diurus — penjaganya nggak boleh kebablasan. */
    public function test_akun_lab_tetap_bisa_diubah(): void
    {
        [$admin] = $this->tigaAkun();
        $teknisi = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$teknisi->id}", ['role' => User::ROLE_VIEWER])
            ->assertOk();

        $this->assertSame(User::ROLE_VIEWER, $teknisi->fresh()->role);
    }

    /** Arah masuk: akun pelanggan nggak boleh menyentuh endpoint internal sama sekali. */
    public function test_akun_pelanggan_ditolak_di_seluruh_endpoint_internal(): void
    {
        [, $pelanggan] = $this->tigaAkun();

        foreach (['/api/users', '/api/calibrations', '/api/dashboard', '/api/me'] as $url) {
            $this->actingAs($pelanggan, 'sanctum')
                ->getJson($url)
                ->assertForbidden();
        }
    }
}
