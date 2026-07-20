<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $pelanggan;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->pelanggan = Customer::factory()->create(['nama' => 'PT Maju Jaya']);
        $this->kategori = EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang']);
    }

    public function test_daftar_alat_pakai_bentuk_yang_dijanjiin_ke_mobile(): void
    {
        Equipment::factory()->create([
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'serial_number' => 'MT-500-196-30',
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
        ]);

        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'nama_alat', 'serial_number', 'kategori', 'merk', 'pelanggan' => ['id', 'nama'], 'tanggal_kalibrasi_terakhir', 'tanggal_jatuh_tempo', 'status']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.kategori', 'panjang')
            ->assertJsonPath('data.0.pelanggan.nama', 'PT Maju Jaya')
            ->assertJsonPath('data.0.status', 'aktif');
    }

    /**
     * `rentang_ukur` itu gabungan siap-tempel dari range_min/max/satuan buat
     * form Order — bukan kolom baru, datanya emang udah ada.
     */
    public function test_rentang_ukur_digabung_jadi_satu_baris(): void
    {
        Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'range_min' => 0,
            'range_max' => 14,
            'satuan' => 'pH',
        ]);

        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            // Kolomnya decimal — tanpa dirapiin bakal kebaca "0.00000000–14.00000000".
            ->assertJsonPath('data.0.rentang_ukur', '0–14 pH');
    }

    public function test_rentang_ukur_null_kalau_batasnya_belum_diisi(): void
    {
        Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'range_min' => 0,
            'range_max' => null,
            'satuan' => 'mm',
        ]);

        // Lebih baik field-nya kosong daripada nampilin "0– mm" di form Order.
        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonPath('data.0.rentang_ukur', null);
    }

    public function test_alat_lewat_jatuh_tempo_statusnya_jadi_overdue(): void
    {
        Equipment::factory()->overdue()->create(['nama_alat' => 'Micrometer']);

        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'overdue');
    }

    public function test_filter_status_overdue_cuma_balikin_yang_lewat_tempo(): void
    {
        Equipment::factory()->overdue()->create(['nama_alat' => 'Yang Telat']);
        Equipment::factory()->create(['nama_alat' => 'Yang Aman']);

        $this->actingAs($this->admin)->getJson('/api/equipments?status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama_alat', 'Yang Telat');
    }

    public function test_search_nyari_di_nama_serial_dan_merk(): void
    {
        Equipment::factory()->create(['nama_alat' => 'Jangka Sorong', 'serial_number' => 'AAA-111']);
        Equipment::factory()->create(['nama_alat' => 'Timbangan', 'serial_number' => 'ZZZ-999']);

        $this->actingAs($this->admin)->getJson('/api/equipments?search=jangka')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama_alat', 'Jangka Sorong');

        $this->actingAs($this->admin)->getJson('/api/equipments?search=ZZZ')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'ZZZ-999');
    }

    public function test_teknisi_bisa_bikin_alat_baru(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->actingAs($teknisi)->postJson('/api/equipments', [
            'nama_alat' => 'Micrometer Luar',
            'serial_number' => 'MT-103-137',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'merk' => 'Mitutoyo',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nama_alat', 'Micrometer Luar')
            ->assertJsonPath('data.kategori', 'panjang');

        $this->assertDatabaseHas('equipments', ['serial_number' => 'MT-103-137']);
    }

    public function test_serial_number_dobel_ditolak_422(): void
    {
        Equipment::factory()->create(['serial_number' => 'DOBEL-01']);

        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Kembar',
            'serial_number' => 'DOBEL-01',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', 'Nomor seri sudah dipakai alat lain.');
    }

    public function test_kategori_ngawur_ditolak_422(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Aneh',
            'serial_number' => 'X-1',
            'kategori' => 'kategori-karangan',
            'pelanggan_id' => $this->pelanggan->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kategori');
    }

    public function test_nama_alat_kemampuan_yang_nggak_ada_di_kategori_itu_ditolak_422(): void
    {
        CalibrationCapability::create([
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Vernier Caliper',
            'range_min' => 0, 'range_max' => 300, 'satuan' => 'mm',
            'ketidakpastian_terbaik' => 0.015, 'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);

        // "Sieve" ada di kemampuan kalibrasi, tapi bukan di kategori "panjang"
        // yang lagi dipakai di request ini — atau typo/karangan sama sekali.
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Jangka Sorong',
            'serial_number' => 'JS-01',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'nama_alat_kemampuan' => 'Vernier Kaliper Yang Salah Ketik',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nama_alat_kemampuan');
    }

    public function test_nama_alat_kemampuan_yang_valid_kesimpen(): void
    {
        CalibrationCapability::create([
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Vernier Caliper',
            'range_min' => 0, 'range_max' => 300, 'satuan' => 'mm',
            'ketidakpastian_terbaik' => 0.015, 'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);

        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'serial_number' => 'JS-02',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'nama_alat_kemampuan' => 'Vernier Caliper',
        ])->assertCreated();

        $this->assertDatabaseHas('equipments', [
            'serial_number' => 'JS-02',
            'nama_alat_kemampuan' => 'Vernier Caliper',
        ]);
    }

    public function test_status_overdue_nggak_boleh_dikirim_manual(): void
    {
        // `overdue` itu turunan tanggal, bukan nilai yang bisa diset.
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat',
            'serial_number' => 'X-2',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'status' => 'overdue',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_hapus_alat_itu_soft_delete(): void
    {
        $alat = Equipment::factory()->create();

        $this->actingAs($this->admin)->deleteJson("/api/equipments/{$alat->id}")->assertOk();

        // Riwayat kalibrasi & sertifikat lama harus tetap bisa ditelusuri.
        $this->assertSoftDeleted('equipments', ['id' => $alat->id]);
    }

    public function test_alat_milik_organisasi_lain_nggak_kelihatan(): void
    {
        $organisasiLain = Organization::factory()->create();
        $alatOrangLain = Equipment::factory()->create([
            'organization_id' => $organisasiLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $organisasiLain->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $organisasiLain->id, 'kode' => 'massa'])->id,
        ]);

        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->getJson("/api/equipments/{$alatOrangLain->id}")
            ->assertNotFound();
    }
}
