<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerbang M0-06 tidak boleh mengubah apa pun buat admin, teknisi, dan viewer.
 *
 * ## Kenapa berkas ini ada
 *
 * `RuteInternalMenolakRoleLainTest` membuktikan yang DITOLAK. Dia sama sekali
 * nggak membuktikan yang DITERIMA masih diterima — dan gerbang yang kelewat
 * ketat gagal dengan bentuk yang sama buruknya: teknisi di lokasi mendadak
 * nggak bisa menyimpan sesi, dan yang kelihatan cuma "Kamu nggak punya akses
 * ke sini" di layar HP-nya.
 *
 * Angka status di bawah adalah perilaku SEBELUM gerbangnya dipasang, dan
 * berkas ini sengaja diuji dua kali waktu ditulis: sekali di kode lama, sekali
 * di kode baru. Dua-duanya hijau.
 *
 * ## Kenapa `/me/permissions` ikut dijaga di sini
 *
 * Memasang `role:admin,teknisi,viewer` di grup luar bikin hampir tiap rute
 * punya DUA middleware `role:`. `MatriksIzin` dulu membaca yang pertama ketemu
 * — yaitu gerbang luar yang longgar itu — jadi endpoint ini bakal bilang viewer
 * boleh approve sertifikat. Tombolnya nyala di HP orang yang bakal ditolak.
 */
class GerbangRoleRegresiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Equipment $alat;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create([
                'organization_id' => $this->org->id,
                'nama' => 'PT Contoh Dua',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
            'nama_alat' => 'Timbangan Contoh',
        ]);

        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $this->alat->id,
            'teknisi_id' => $this->orang(User::ROLE_TEKNISI)->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    private function orang(string $role): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
        ]);
    }

    /** Baca daftar alat: ketiga role lab, sama-sama boleh. */
    public function test_get_equipments_tetap_boleh_buat_tiga_role(): void
    {
        foreach (User::roles() as $role) {
            $this->actingAs($this->orang($role), 'sanctum')
                ->getJson('/api/equipments')
                ->assertOk();
        }
    }

    /**
     * Tulis sesi kalibrasi: admin & teknisi lolos gerbang (lalu kena 422 karena
     * badannya kosong — itu validasi, bukan otorisasi), viewer ditolak 403.
     *
     * 422 vs 403 yang dibedakan di sini, bukan "bukan 403": 422 membuktikan
     * permintaannya SAMPAI ke validasi, artinya gerbangnya beneran dilewati.
     */
    public function test_post_calibrations_tetap_boleh_admin_teknisi_ditolak_viewer(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI] as $role) {
            $this->actingAs($this->orang($role), 'sanctum')
                ->postJson('/api/calibrations', [])
                ->assertStatus(422);
        }

        $this->actingAs($this->orang(User::ROLE_VIEWER), 'sanctum')
            ->postJson('/api/calibrations', [])
            ->assertForbidden();
    }

    /** Approve: admin doang yang lolos gerbang. */
    public function test_approve_tetap_admin_doang(): void
    {
        $this->actingAs($this->orang(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/calibrations/{$this->sesi->id}/approve")
            ->assertStatus(422);

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $this->actingAs($this->orang($role), 'sanctum')
                ->postJson("/api/calibrations/{$this->sesi->id}/approve")
                ->assertForbidden();
        }
    }

    /** Rute yang dipakai SEMUA role internal tetap kebuka buat ketiganya. */
    public function test_rute_bersama_tetap_kebuka_buat_tiga_role(): void
    {
        foreach (User::roles() as $role) {
            $orang = $this->orang($role);

            $this->actingAs($orang, 'sanctum')->getJson('/api/me')->assertOk();
            $this->actingAs($orang, 'sanctum')->getJson('/api/me/permissions')->assertOk();
            $this->actingAs($orang, 'sanctum')->getJson('/api/notifications')->assertOk();
            $this->actingAs($orang, 'sanctum')->getJson('/api/notifications/unread-count')->assertOk();
            $this->actingAs($orang, 'sanctum')
                ->postJson('/api/device-tokens', ['token' => 'contoh-token-fcm-'.$orang->id, 'platform' => 'android'])
                ->assertSuccessful();

        }
    }

    /**
     * `/logout` dan `/broadcasting/auth` — ketiga role tetap bisa.
     *
     * Method SENDIRI, dan nggak memanggil `actingAs()` sama sekali. Dua
     * sebabnya, dan yang kedua yang bikin test ini sempat merah palsu:
     *
     * 1. `actingAs()` menyetel user guard TANPA access token, jadi
     *    `currentAccessToken()` di `AuthController::logout()` null dan
     *    jalur logout-nya meledak 500 — kegagalan yang nggak ada hubungannya
     *    dengan gerbang role.
     * 2. `actingAs()` yang sudah kepanggil di satu test METHOD bikin guard-nya
     *    kadung terisi, jadi header `Authorization` yang dipasang sesudahnya
     *    diabaikan diam-diam.
     */
    public function test_logout_dan_auth_channel_tetap_boleh_buat_tiga_role(): void
    {
        foreach (User::roles() as $role) {
            $orang = $this->orang($role);
            $bearer = ['Authorization' => 'Bearer '.$orang->createToken('uji-regresi')->plainTextToken];

            // Driver broadcast `null` bikin `auth()` jadi no-op, jadi yang
            // dibuktikan di sini cuma "gerbang role nggak menutupnya" —
            // otorisasi channel-nya sendiri diuji `GerbangChannelRoleTest`.
            $this->withHeaders($bearer)
                ->postJson('/api/broadcasting/auth', [
                    'channel_name' => 'private-organisasi.'.$orang->organization_id,
                    'socket_id' => '1234.5678',
                ])
                ->assertStatus(200);

            $this->withHeaders($bearer)->postJson('/api/logout')->assertOk();
        }
    }

    /**
     * `/me/permissions` tidak boleh ikut melonggar gara-gara gerbang luar.
     *
     * Ini penjaga langsung buat `MatriksIzin::roleYangBoleh()` yang sekarang
     * mengiris SEMUA `role:`, bukan memulangkan yang pertama ketemu.
     */
    public function test_me_permissions_tidak_ikut_melonggar(): void
    {
        $izin = function (string $role): array {
            return $this->actingAs($this->orang($role), 'sanctum')
                ->getJson('/api/me/permissions')
                ->assertOk()
                ->json('data.boleh');
        };

        $admin = $izin(User::ROLE_ADMIN);
        $teknisi = $izin(User::ROLE_TEKNISI);
        $viewer = $izin(User::ROLE_VIEWER);

        $this->assertContains('kalibrasi.setujui', $admin);
        $this->assertNotContains('kalibrasi.setujui', $teknisi, 'Teknisi dikasih izin approve yang bakal ditolak 403.');
        $this->assertNotContains('kalibrasi.setujui', $viewer, 'Viewer dikasih izin approve yang bakal ditolak 403.');

        $this->assertContains('kalibrasi.buat', $teknisi);
        $this->assertNotContains('kalibrasi.buat', $viewer, 'Viewer dikasih izin nulis sesi yang bakal ditolak 403.');

        $this->assertContains('pengguna.kelola', $admin);
        $this->assertNotContains('pengguna.kelola', $teknisi);
    }
}
