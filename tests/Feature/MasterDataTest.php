<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['nama' => 'PT Sidik']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_bisa_lihat_dan_ubah_data_pt(): void
    {
        $this->actingAs($this->admin)->getJson('/api/organization')
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT Sidik');

        $this->actingAs($this->admin)->putJson('/api/organization', [
            'nama' => 'PT Sidik Kalibrasi',
            'alamat' => 'Bandung',
            'no_akreditasi' => 'LK-285-IDN',
        ])
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT Sidik Kalibrasi')
            ->assertJsonPath('data.no_akreditasi', 'LK-285-IDN');
    }

    public function test_crud_pelanggan_jalan(): void
    {
        $this->actingAs($this->admin)->postJson('/api/customers', [
            'nama' => 'PT Maju Jaya',
            'alamat' => 'Jl. Soekarno Hatta 12',
            'contact_person' => 'Rina',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'PT Maju Jaya');

        $pelanggan = Customer::firstWhere('nama', 'PT Maju Jaya');

        $this->actingAs($this->admin)->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->putJson("/api/customers/{$pelanggan->id}", ['nama' => 'PT Maju Jaya Abadi'])
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT Maju Jaya Abadi');

        $this->actingAs($this->admin)->deleteJson("/api/customers/{$pelanggan->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $pelanggan->id]);
    }

    public function test_pelanggan_yang_masih_punya_alat_nggak_boleh_dihapus(): void
    {
        $pelanggan = Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
        Equipment::factory()->create(['customer_id' => $pelanggan->id]);

        // Kalau dipaksa, alat & riwayat kalibrasinya jadi yatim.
        $this->actingAs($this->admin)->deleteJson("/api/customers/{$pelanggan->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('customers', ['id' => $pelanggan->id]);
    }

    public function test_daftar_kategori_bentuknya_sesuai_kontrak(): void
    {
        EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang']);

        $this->actingAs($this->admin)->getJson('/api/categories')
            ->assertOk()
            ->assertJsonStructure(['data' => [['kode', 'nama', 'rentang_ukur', 'ketidakpastian_terbaik', 'satuan']]])
            ->assertJsonPath('data.0.kode', 'panjang');
    }
}
