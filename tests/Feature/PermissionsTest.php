<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/me/permissions` — daftar ability yang dipakai mobile buat
 * nyembunyiin tombol yang bakal ditolak 403.
 *
 * Yang paling penting di sini bukan cuma "daftarnya keluar", tapi bahwa
 * daftarnya JUJUR: kalau `Permissions` bilang sebuah role nggak punya ability,
 * route aslinya harus beneran nolak role itu. Kalau dua-duanya nyimpang,
 * mobile balik ke masalah lama — tombol muncul lalu user kena error.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_tiap_role_dapat_daftar_ability_sendiri(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $this->actingAs($this->user($role))
                ->getJson('/api/me/permissions')
                ->assertOk()
                ->assertJsonPath('data.role', $role)
                ->assertJsonPath('data.boleh', Permissions::untukRole($role));
        }
    }

    public function test_ability_bertingkat_viewer_subset_teknisi_subset_admin(): void
    {
        $viewer = Permissions::untukRole(User::ROLE_VIEWER);
        $teknisi = Permissions::untukRole(User::ROLE_TEKNISI);
        $admin = Permissions::untukRole(User::ROLE_ADMIN);

        $this->assertEmpty(array_diff($viewer, $teknisi), 'viewer harus subset teknisi');
        $this->assertEmpty(array_diff($teknisi, $admin), 'teknisi harus subset admin');
        $this->assertGreaterThan(count($teknisi), count($admin));
    }

    public function test_viewer_nggak_punya_ability_nulis_apa_pun(): void
    {
        $viewer = Permissions::untukRole(User::ROLE_VIEWER);

        foreach ($viewer as $ability) {
            $this->assertMatchesRegularExpression(
                '/\.(lihat|unduh)$/',
                $ability,
                "viewer cuma boleh baca, tapi punya ability nulis: {$ability}",
            );
        }
    }

    /**
     * Inti test ini: ability yang TIDAK dipunyai harus beneran ditolak
     * route-nya. Kalau `Permissions` dilonggarin tanpa ngubah middleware (atau
     * sebaliknya), salah satu baris di bawah bakal jebol.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function abilityYangDitolak(): array
    {
        return [
            // role, ability yang NGGAK dipunya, method, endpoint
            [User::ROLE_VIEWER, 'kalibrasi.buat', 'postJson', '/api/calibrations'],
            [User::ROLE_VIEWER, 'alat.tambah', 'postJson', '/api/equipments'],
            [User::ROLE_VIEWER, 'arsip.folder.buat', 'postJson', '/api/arsip/folders'],
            [User::ROLE_TEKNISI, 'pelanggan.lihat', 'getJson', '/api/customers'],
            [User::ROLE_TEKNISI, 'organisasi.lihat', 'getJson', '/api/organization'],
            [User::ROLE_TEKNISI, 'pengguna.kelola', 'getJson', '/api/users'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('abilityYangDitolak')]
    public function test_ability_yang_nggak_dipunya_beneran_ditolak_route(
        string $role,
        string $ability,
        string $method,
        string $endpoint,
    ): void {
        $this->assertNotContains(
            $ability,
            Permissions::untukRole($role),
            "Prasyarat test: {$role} seharusnya NGGAK punya {$ability}",
        );

        // 403 = ditolak middleware role. Bukan 422 (validasi) — kalau kebablasan
        // sampai validasi, artinya middleware-nya nggak jalan.
        $this->actingAs($this->user($role))
            ->{$method}($endpoint, [])
            ->assertForbidden();
    }

    public function test_butuh_login(): void
    {
        $this->getJson('/api/me/permissions')->assertUnauthorized();
    }
}
