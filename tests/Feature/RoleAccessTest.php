<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tugas 24 Jul: uji hak akses dari 3 akun beda — pastiin nggak bisa ditembus.
 *
 * Ini matriksnya. Kalau ada endpoint baru, tambahin barisnya di sini.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $alat;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->pelanggan = Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang']);
        $this->alat = Equipment::factory()->create();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * Viewer itu read-only. Dia boleh lihat semuanya, tapi nggak boleh
     * nyentuh apa pun.
     */
    public function test_viewer_boleh_baca_tapi_nggak_boleh_nulis(): void
    {
        $viewer = $this->user(User::ROLE_VIEWER);

        $this->actingAs($viewer)->getJson('/api/equipments')->assertOk();
        $this->actingAs($viewer)->getJson('/api/categories')->assertOk();
        $this->actingAs($viewer)->getJson('/api/dashboard')->assertOk();

        $this->actingAs($viewer)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Selundupan',
            'serial_number' => 'VW-001',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
        ])->assertForbidden();

        $this->actingAs($viewer)->putJson("/api/equipments/{$this->alat->id}", ['nama_alat' => 'Diubah Viewer'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/equipments/{$this->alat->id}")->assertForbidden();

        // Yang paling penting: nggak ada satu pun percobaan di atas yang nembus.
        $this->assertDatabaseMissing('equipments', ['serial_number' => 'VW-001']);
        $this->assertDatabaseHas('equipments', ['id' => $this->alat->id, 'nama_alat' => $this->alat->nama_alat]);
        $this->assertNotSoftDeleted('equipments', ['id' => $this->alat->id]);
    }

    /** Teknisi boleh kelola alat, tapi master data & user management bukan haknya. */
    public function test_teknisi_boleh_kelola_alat_tapi_nggak_boleh_master_data(): void
    {
        $teknisi = $this->user(User::ROLE_TEKNISI);

        $this->actingAs($teknisi)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Teknisi',
            'serial_number' => 'TK-001',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
        ])->assertCreated();

        $this->actingAs($teknisi)->getJson('/api/customers')->assertForbidden();
        $this->actingAs($teknisi)->postJson('/api/customers', ['nama' => 'PT Selundupan'])->assertForbidden();
        $this->actingAs($teknisi)->getJson('/api/organization')->assertForbidden();
        $this->actingAs($teknisi)->putJson('/api/organization', ['nama' => 'PT Bajakan'])->assertForbidden();
        $this->actingAs($teknisi)->getJson('/api/users')->assertForbidden();

        $this->assertDatabaseMissing('customers', ['nama' => 'PT Selundupan']);
        $this->assertDatabaseMissing('organizations', ['nama' => 'PT Bajakan']);
    }

    public function test_admin_boleh_semuanya(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/equipments')->assertOk();
        $this->actingAs($admin)->getJson('/api/customers')->assertOk();
        $this->actingAs($admin)->getJson('/api/organization')->assertOk();
        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/dashboard')->assertOk();

        $this->actingAs($admin)->postJson('/api/customers', ['nama' => 'PT Baru'])->assertCreated();
    }

    /** Tanpa token, semuanya 401 — nggak ada endpoint yang bocor. */
    public function test_tanpa_token_semua_endpoint_data_nolak_401(): void
    {
        foreach (['/api/equipments', '/api/categories', '/api/customers', '/api/organization', '/api/users', '/api/dashboard'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
