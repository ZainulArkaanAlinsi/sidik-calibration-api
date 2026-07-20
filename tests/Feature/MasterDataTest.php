<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
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

    public function test_crud_ruangan_jalan(): void
    {
        $this->actingAs($this->admin)->postJson('/api/rooms', [
            'kode' => 'R-01',
            'nama' => 'Ruang Kalibrasi Massa',
            'lokasi' => 'Lantai 2',
            'suhu_min' => 18,
            'suhu_max' => 25,
        ])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'R-01')
            ->assertJsonPath('data.aktif', true);

        $ruangan = Room::firstWhere('kode', 'R-01');

        $this->actingAs($this->admin)->getJson('/api/rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->putJson("/api/rooms/{$ruangan->id}", ['nama' => 'Ruang Massa'])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Ruang Massa');

        $this->actingAs($this->admin)->deleteJson("/api/rooms/{$ruangan->id}")->assertOk();
        $this->assertSoftDeleted('rooms', ['id' => $ruangan->id]);
    }

    public function test_kode_ruangan_nggak_boleh_dobel_di_satu_organisasi(): void
    {
        Room::factory()->create(['kode' => 'R-01']);

        $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => 'R-01', 'nama' => 'Ruang Lain'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kode');
    }

    /**
     * Rentang kebalik itu syarat yang nggak mungkin dipenuhi siapa pun — kalau
     * lolos, tiap sesi di ruangan itu ketulis melanggar syarat selamanya.
     */
    public function test_rentang_suhu_kebalik_ditolak(): void
    {
        $this->actingAs($this->admin)->postJson('/api/rooms', [
            'kode' => 'R-02',
            'nama' => 'Ruang Suhu',
            'suhu_min' => 25,
            'suhu_max' => 18,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('suhu_max');
    }

    /** Update sebagian tetap kejaga: batas bawah diambil dari data tersimpan. */
    public function test_rentang_kebalik_ketahan_walau_cuma_satu_sisi_yang_diubah(): void
    {
        $ruangan = Room::factory()->create(['suhu_min' => 18, 'suhu_max' => 25]);

        $this->actingAs($this->admin)->putJson("/api/rooms/{$ruangan->id}", ['suhu_max' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('suhu_max');
    }

    public function test_dropdown_ruangan_cuma_nawarin_yang_aktif(): void
    {
        Room::factory()->create(['kode' => 'R-01']);
        Room::factory()->nonaktif()->create(['kode' => 'R-02']);

        // Layar master data lihat dua-duanya...
        $this->actingAs($this->admin)->getJson('/api/rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // ...tapi dropdown pilih ruangan cuma yang aktif.
        $this->actingAs($this->admin)->getJson('/api/rooms?hanya_aktif=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kode', 'R-01');
    }

    public function test_teknisi_boleh_baca_ruangan_tapi_nggak_boleh_nulis(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        Room::factory()->create();

        // Butuh baca buat ngisi dropdown "Ruangan" waktu bikin sesi.
        $this->actingAs($teknisi)->getJson('/api/rooms')->assertOk();

        $this->actingAs($teknisi)->postJson('/api/rooms', ['kode' => 'R-99', 'nama' => 'Ruang Baru'])
            ->assertForbidden();
    }

    public function test_crud_teknisi_jalan(): void
    {
        $this->actingAs($this->admin)->postJson('/api/technicians', [
            'nama' => 'Budi Santoso',
            'employee_id' => 'ASM-2001',
            'email' => 'budi@sidik.test',
            'department' => 'Kalibrasi',
            'password' => 'rahasia123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Budi Santoso')
            ->assertJsonPath('data.status', User::STATUS_AKTIF);

        $teknisi = User::firstWhere('employee_id', 'ASM-2001');

        // Dibikinin admin, jadi langsung role teknisi & aktif — nggak nyangkut
        // di antrean approval yang gunanya nyaring pendaftar mandiri.
        $this->assertSame(User::ROLE_TEKNISI, $teknisi->role);

        $this->actingAs($this->admin)->getJson('/api/technicians')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jumlah_kalibrasi', 0);

        $this->actingAs($this->admin)->putJson("/api/technicians/{$teknisi->id}", ['nama' => 'Budi S'])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Budi S');

        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$teknisi->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $teknisi->id]);
    }

    /** Daftar teknisi cuma berisi role teknisi — admin & viewer nggak ikut. */
    public function test_daftar_teknisi_nggak_nyampur_role_lain(): void
    {
        User::factory()->create(['role' => User::ROLE_TEKNISI]);
        User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($this->admin)->getJson('/api/technicians')
            ->assertOk()
            // Admin yang lagi login sendiri juga nggak ikut kehitung.
            ->assertJsonCount(1, 'data');
    }

    /**
     * Endpoint ini nggak boleh jadi jalan pintas ngutak-atik akun admin lewat
     * URL yang kedengarannya nggak berbahaya.
     */
    public function test_akun_non_teknisi_nggak_bisa_disentuh_lewat_endpoint_teknisi(): void
    {
        $adminLain = User::factory()->admin()->create();

        $this->actingAs($this->admin)->getJson("/api/technicians/{$adminLain->id}")->assertNotFound();
        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$adminLain->id}")->assertNotFound();
    }

    public function test_role_nggak_bisa_dinaikin_lewat_form_teknisi(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->actingAs($this->admin)->putJson("/api/technicians/{$teknisi->id}", [
            'nama' => 'Budi',
            'role' => User::ROLE_ADMIN,
        ])->assertOk();

        $this->assertSame(User::ROLE_TEKNISI, $teknisi->fresh()->role);
    }

    /**
     * Nama teknisi nempel di sertifikat yang udah terbit. Hapus dia = putus
     * ketertelusuran yang justru dicari asesor waktu audit.
     */
    public function test_teknisi_yang_punya_riwayat_kalibrasi_nggak_boleh_dihapus(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
        $alat = Equipment::factory()->create();

        CalibrationSession::create([
            'organization_id' => $teknisi->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'tanggal_kalibrasi' => now(),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$teknisi->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $teknisi->id]);

        // Jalan keluarnya: nonaktifin. Nggak bisa login lagi, riwayat utuh.
        $this->actingAs($this->admin)->putJson("/api/technicians/{$teknisi->id}", [
            'status' => User::STATUS_NONAKTIF,
        ])->assertOk();

        $this->assertSame(User::STATUS_NONAKTIF, $teknisi->fresh()->status);
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
