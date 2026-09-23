<?php

namespace Tests\Feature;

use App\Filament\Pages\PengaturanOrganisasi;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2: `super_admin` dibuka — MASUK dan MEMBACA, bukan memutuskan.
 *
 * ## Keadaan sebelum ini
 *
 * Rolenya diterima ENUM `users.role` sejak 16 Sep 2026 lalu tertolak di tujuh
 * pintu sekaligus. Akun yang dibuat waktu itu tidak bisa melakukan apa pun, dan
 * yang paling menyesatkan: `AuthController` menolaknya dari aplikasi teknisi
 * sambil menyuruh "pakai panel admin di peramban", sementara
 * `User::canAccessPanel()` cuma menerima `ROLE_ADMIN`. Petunjuknya menunjuk
 * pintu yang ikut terkunci.
 *
 * ## Yang dibuka, dan yang SENGAJA tetap tertutup
 *
 * Dibuka: login aplikasi, panel `/admin`, channel organisasi, dan seluruh rute
 * BACA — `EnsureUserHasRole::lolosBacaSuperAdmin()` meloloskan GET/HEAD walau
 * namanya tidak pernah ditulis di `role:` mana pun.
 *
 * Tetap tertutup, dan ketiganya bukan kelupaan:
 *
 * 1. **Menulis lewat API.** K4 (siapa yang boleh mengesahkan sertifikat) belum
 *    dijawab manajer teknis. Peran yang baru dibuka tidak boleh mendapat
 *    wewenang itu duluan.
 * 2. **Menulis lewat panel.** Panel bukan layar baca: `approve` di tabel sesi
 *    menerbitkan sertifikat berlogo akreditasi.
 * 3. **`User::roles()`.** Daftar itu dipakai `Rule::in` sebagai pilihan role
 *    waktu admin membuat akun. Begitu `super_admin` masuk ke sana, admin biasa
 *    bisa mencetak super admin — peran pengawas jadi bisa dibuat yang diawasi.
 *
 * Lintas organisasi juga belum: super admin hari ini membaca lab-nya sendiri.
 * 59 tempat menyaring `organization_id`, dan menggesernya slice terpisah supaya
 * perubahan isolasi data tidak nebeng di commit ini.
 */
class SuperAdminAksesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->super = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => 'pengawas@sidik.test',
            'password' => 'rahasia123',
        ]);
    }

    /*
    |---------------------------------------------------------------------------
    | Pintu masuk
    |---------------------------------------------------------------------------
    */

    public function test_super_admin_bisa_login_lewat_aplikasi(): void
    {
        $this->postJson('/api/login', [
            'identifier' => 'pengawas@sidik.test',
            'password' => 'rahasia123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', User::ROLE_SUPER_ADMIN)
            ->assertJsonStructure(['data' => ['token']]);
    }

    /** Pesan lama menyuruhnya ke panel; sekarang panelnya memang terbuka. */
    public function test_panel_admin_menerima_super_admin(): void
    {
        $this->assertTrue($this->super->canAccessPanel(filament()->getPanel('admin')));

        $this->actingAs($this->super)
            ->get(ListEquipment::getUrl())
            ->assertSuccessful();
    }

    /** Akun nonaktif tetap kehilangan panel — status diperiksa terpisah dari role. */
    public function test_super_admin_nonaktif_tetap_ditolak_panel(): void
    {
        $this->super->update(['status' => User::STATUS_NONAKTIF]);

        $this->assertFalse($this->super->fresh()->canAccessPanel(filament()->getPanel('admin')));
    }

    /*
    |---------------------------------------------------------------------------
    | Baca boleh
    |---------------------------------------------------------------------------
    */

    /** `GET /api/users` dipagari `role:admin` — super admin tetap lewat karena ini baca. */
    public function test_rute_baca_admin_only_tetap_terbuka(): void
    {
        $this->actingAs($this->super, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();
    }

    public function test_rute_baca_umum_terbuka(): void
    {
        $this->actingAs($this->super, 'sanctum')
            ->getJson('/api/equipments')
            ->assertOk();
    }

    /*
    |---------------------------------------------------------------------------
    | Tulis: tidak, di dua-duanya
    |---------------------------------------------------------------------------
    */

    public function test_rute_tulis_ditolak(): void
    {
        $teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs($this->super, 'sanctum')
            ->putJson("/api/users/{$teknisi->id}", ['name' => 'Diubah Super Admin'])
            ->assertForbidden();

        $this->assertNotSame('Diubah Super Admin', $teknisi->fresh()->name);
    }

    /** Termasuk approval akun — jalur tulis yang paling gampang dikira "cuma tombol". */
    public function test_approve_akun_ditolak(): void
    {
        $pending = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_PENDING,
        ]);

        $this->actingAs($this->super, 'sanctum')
            ->postJson("/api/users/{$pending->id}/approve")
            ->assertForbidden();

        $this->assertSame(User::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_panel_tidak_memberi_jalur_tulis(): void
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
        ]);

        $this->actingAs($this->super);

        $this->get(CreateEquipment::getUrl())->assertForbidden();
        $this->get(EditEquipment::getUrl(['record' => $alat->getKey()]))->assertForbidden();

        // Halaman Pengaturan Organisasi seluruhnya tulis — tanda tangan & logo
        // yang ikut tercetak di sertifikat. Disembunyikan utuh, bukan tombolnya.
        $this->get(PengaturanOrganisasi::getUrl())->assertForbidden();
    }

    /** Admin tetap punya semuanya — penjagaan baru nggak boleh kena role lain. */
    public function test_admin_tidak_ikut_terkunci(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);

        $this->actingAs($admin);

        $this->get(CreateEquipment::getUrl())->assertSuccessful();
        $this->get(PengaturanOrganisasi::getUrl())->assertSuccessful();
    }

    /*
    |---------------------------------------------------------------------------
    | Jawaban izin ke mobile harus sama dengan penjagaannya
    |---------------------------------------------------------------------------
    */

    /**
     * `/me/izin` dipakai mobile buat menyembunyikan tombol yang bakal ditolak.
     * Kalau jawabannya beda dari middleware, yang muncul tombol nyala lalu 403 —
     * persis kegagalan yang `MatriksIzin` ada buat mencegahnya.
     */
    public function test_izin_yang_dijawab_cuma_yang_baca(): void
    {
        $boleh = $this->actingAs($this->super, 'sanctum')
            ->getJson('/api/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_SUPER_ADMIN)
            ->json('data.boleh');

        $this->assertContains('alat.lihat', $boleh);
        $this->assertContains('sertifikat.lihat', $boleh);
        $this->assertNotContains('metode.kelola', $boleh, 'Izin TULIS bocor ke super admin.');
    }

    /*
    |---------------------------------------------------------------------------
    | Yang tetap tertutup
    |---------------------------------------------------------------------------
    */

    /**
     * Dua daftar, dan bedanya yang menahan eskalasi: super admin boleh MASUK
     * (`rolesInternal`) tapi tidak boleh DIBERIKAN admin ke siapa pun
     * (`roles`, yang dipakai `Rule::in`).
     */
    public function test_dua_daftar_role_tetap_beda(): void
    {
        $this->assertNotContains(User::ROLE_SUPER_ADMIN, User::roles());
        $this->assertContains(User::ROLE_SUPER_ADMIN, User::rolesInternal());
        $this->assertNotContains(User::ROLE_PELANGGAN, User::rolesInternal());
    }

    /** Admin tetap tidak bisa mencetak super admin lewat API. */
    public function test_admin_tidak_bisa_mempromosikan_jadi_super_admin(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);

        $teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$teknisi->id}", ['role' => User::ROLE_SUPER_ADMIN])
            ->assertStatus(422);

        $this->assertSame(User::ROLE_TEKNISI, $teknisi->fresh()->role);
    }

    /** Pelanggan tidak ikut kebuka — gerbangnya beda dunia, bukan beda tingkat. */
    public function test_pelanggan_tetap_ditolak_pintu_internal(): void
    {
        User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
            'email' => 'orang@pabrik.test',
            'password' => 'rahasia123',
        ]);

        $this->postJson('/api/login', [
            'identifier' => 'orang@pabrik.test',
            'password' => 'rahasia123',
        ])
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_internal');
    }
}
