<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-8 uji E2E-QA 18 Nov 2026: seorang admin bisa menonaktifkan AKUNNYA
 * SENDIRI (`PUT /users/{id sendiri} {"status":"nonaktif"}` → 200), dan
 * `POST /users/{id}/reject` pada akun AKTIF menonaktifkannya dengan pesan
 * "Pendaftaran ditolak." Satu-satunya admin bisa mengunci laboratorium.
 *
 * Aturan sekarang:
 *  - admin tidak boleh menonaktifkan akunnya sendiri;
 *  - admin AKTIF terakhir di organisasi tidak boleh dinonaktifkan siapa pun;
 *  - `reject` hanya untuk pendaftaran yang berstatus `pending`.
 *
 * Penonaktifan akun teknisi/viewer dan admin yang masih ada admin lain
 * tetap berjalan seperti sebelumnya.
 */
class PenjagaAdminTerakhirTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->adminA = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->adminB = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function ubahStatus(User $pelaku, User $sasaran, string $status)
    {
        return $this->actingAs($pelaku)->putJson("/api/users/{$sasaran->id}", ['status' => $status]);
    }

    // ----------------------------------------------------------- diri sendiri

    public function test_admin_tidak_boleh_menonaktifkan_akunnya_sendiri(): void
    {
        $this->adminA->createToken('hp');

        foreach ([User::STATUS_NONAKTIF, User::STATUS_PENDING] as $status) {
            $this->ubahStatus($this->adminA, $this->adminA, $status)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'tidak_boleh_nonaktifkan_diri_sendiri');
        }

        $this->assertSame(User::STATUS_AKTIF, $this->adminA->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_admin_tetap_boleh_mengubah_data_dirinya_dan_menyetel_status_aktif(): void
    {
        $this->ubahStatus($this->adminA, $this->adminA, User::STATUS_AKTIF)->assertOk();

        $this->actingAs($this->adminA)
            ->putJson("/api/users/{$this->adminA->id}", ['department' => 'Kalibrasi'])
            ->assertOk();

        $this->assertSame('Kalibrasi', $this->adminA->fresh()->department);
    }

    // ------------------------------------------------------------ admin terakhir

    public function test_admin_boleh_menonaktifkan_admin_lain_selama_masih_ada_admin_aktif(): void
    {
        $this->ubahStatus($this->adminA, $this->adminB, User::STATUS_NONAKTIF)->assertOk();

        $this->assertSame(User::STATUS_NONAKTIF, $this->adminB->fresh()->status);
    }

    public function test_admin_aktif_terakhir_tidak_boleh_dinonaktifkan_siapa_pun(): void
    {
        $this->adminB->update(['status' => User::STATUS_NONAKTIF]);
        $this->adminA->createToken('hp');

        // Super admin (bukan dirinya sendiri) pun tidak boleh mengunci lab.
        $this->ubahStatus($this->superAdmin, $this->adminA, User::STATUS_NONAKTIF)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'admin_terakhir_aktif');

        $this->assertSame(User::STATUS_AKTIF, $this->adminA->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_dua_admin_saling_menonaktifkan_hanya_yang_pertama_berhasil(): void
    {
        $this->ubahStatus($this->adminA, $this->adminB, User::STATUS_NONAKTIF)->assertOk();

        // adminB kini nonaktif; adminA tinggal satu-satunya.
        $this->ubahStatus($this->superAdmin, $this->adminA, User::STATUS_NONAKTIF)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'admin_terakhir_aktif');

        $this->assertSame(1, User::where('organization_id', $this->lab->id)
            ->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_AKTIF)->count());
    }

    public function test_admin_lab_lain_tidak_dihitung_sebagai_admin_lain(): void
    {
        $labLain = Organization::factory()->create();
        User::factory()->admin()->create(['organization_id' => $labLain->id]);
        $this->adminB->update(['status' => User::STATUS_NONAKTIF]);

        $this->ubahStatus($this->superAdmin, $this->adminA, User::STATUS_NONAKTIF)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'admin_terakhir_aktif');
    }

    public function test_admin_yang_sudah_nonaktif_bukan_penjaga_dan_teknisi_tetap_bisa_dinonaktifkan(): void
    {
        $teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $teknisi->createToken('hp');

        $this->ubahStatus($this->adminA, $teknisi, User::STATUS_NONAKTIF)->assertOk();

        $this->assertSame(User::STATUS_NONAKTIF, $teknisi->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_menyetel_nonaktif_pada_akun_yang_sudah_nonaktif_tidak_ditolak(): void
    {
        $this->adminB->update(['status' => User::STATUS_NONAKTIF]);

        // Tidak ada penonaktifan baru yang terjadi, jadi bukan urusan penjaga.
        $this->ubahStatus($this->adminA, $this->adminB, User::STATUS_NONAKTIF)->assertOk();
    }

    // ------------------------------------------------------------------ reject

    public function test_reject_hanya_untuk_pendaftaran_yang_pending(): void
    {
        $pendaftar = User::factory()->pending()->create(['organization_id' => $this->lab->id]);

        $this->actingAs($this->adminA)->postJson("/api/users/{$pendaftar->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_NONAKTIF);
    }

    public function test_reject_pada_akun_aktif_ditolak_dan_akunnya_tidak_berubah(): void
    {
        $teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $teknisi->createToken('hp');

        foreach ([$teknisi, $this->adminB, $this->adminA] as $aktif) {
            $this->actingAs($this->adminA)->postJson("/api/users/{$aktif->id}/reject")
                ->assertStatus(422)
                ->assertJsonPath('kode', 'bukan_pending');

            $this->assertSame(User::STATUS_AKTIF, $aktif->fresh()->status);
        }

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_reject_pada_akun_nonaktif_juga_ditolak(): void
    {
        $nonaktif = User::factory()->create([
            'organization_id' => $this->lab->id, 'status' => User::STATUS_NONAKTIF,
        ]);

        $this->actingAs($this->adminA)->postJson("/api/users/{$nonaktif->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'bukan_pending');
    }

    // -------------------------------------------------------------------- izin

    public function test_izin_tidak_berubah_teknisi_tetap_403_dan_lab_lain_tetap_404(): void
    {
        $teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $pendaftar = User::factory()->pending()->create(['organization_id' => $this->lab->id]);

        $this->actingAs($teknisi)->putJson("/api/users/{$this->adminB->id}", ['status' => 'nonaktif'])->assertForbidden();
        $this->actingAs($teknisi)->postJson("/api/users/{$pendaftar->id}/reject")->assertForbidden();

        $adminLain = User::factory()->admin()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $this->actingAs($adminLain)->putJson("/api/users/{$this->adminB->id}", ['status' => 'nonaktif'])->assertNotFound();
        $this->actingAs($adminLain)->postJson("/api/users/{$pendaftar->id}/reject")->assertNotFound();

        $this->assertSame(User::STATUS_AKTIF, $this->adminB->fresh()->status);
        $this->assertSame(User::STATUS_PENDING, $pendaftar->fresh()->status);
    }
}
