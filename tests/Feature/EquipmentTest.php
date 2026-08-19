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

    /**
     * `resolusi_rentang` nentuin satuan tiap baris lembar kerja, jumlah desimal
     * sertifikat, dan style sertifikat Conductivity — dan sempat cuma bisa
     * diisi lewat panel admin. Mobile nggak pernah nerima maupun ngirimnya,
     * jadi form alat di HP cuma bisa nampilin `resolusi` tunggal yang buat alat
     * bersatuan campur nggak mewakili apa-apa.
     */
    public function test_resolusi_rentang_kekirim_ke_mobile(): void
    {
        $alat = Equipment::factory()->create([
            'resolusi_rentang' => [
                ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
                ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
            ],
        ]);

        $this->actingAs($this->admin)->getJson("/api/equipments/{$alat->id}")
            ->assertOk()
            ->assertJsonPath('data.resolusi_rentang.0.satuan', 'µS/cm')
            ->assertJsonPath('data.resolusi_rentang.1.resolusi', 0.01);
    }

    public function test_mobile_bisa_nyimpen_resolusi_rentang(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Conductivity Meter',
            'serial_number' => 'C12345-COND',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'resolusi_rentang' => [
                ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
                ['titik' => 1412, 'satuan' => 'µS/cm', 'resolusi' => 1],
                ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
            ],
        ])->assertCreated();

        $alat = Equipment::where('serial_number', 'C12345-COND')->firstOrFail();

        $this->assertCount(3, $alat->resolusi_rentang);
        // Yang beneran diuji: bandnya kepakai buat mutusin satuan per titik,
        // bukan cuma kesimpen sebagai JSON.
        $this->assertSame('µS/cm', $alat->satuanPada(1412.0));
        $this->assertSame('mS/cm', $alat->satuanPada(111.0));
    }

    /**
     * PATCH yang nggak nyebut `resolusi_rentang` nggak boleh ngehapus band yang
     * udah diisi lewat panel admin — mobile ngirim body parsial buat perubahan
     * kecil kayak ganti lokasi.
     */
    public function test_update_tanpa_resolusi_rentang_nggak_ngehapus_band(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'resolusi_rentang' => [['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1]],
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/equipments/{$alat->id}", ['lokasi' => 'Lab. PT. Sidik'])
            ->assertOk();

        $this->assertCount(1, $alat->fresh()->resolusi_rentang);
    }

    public function test_resolusi_rentang_kosong_yang_eksplisit_ngosongin_band(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'resolusi_rentang' => [['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1]],
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/equipments/{$alat->id}", ['resolusi_rentang' => []])
            ->assertOk();

        $this->assertSame([], $alat->fresh()->resolusi_rentang);
    }

    public function test_resolusi_nol_di_baris_band_ditolak_422(): void
    {
        // Resolusi 0 bikin `Angka::desimalDariResolusi()` jatuh ke default 4
        // desimal diam-diam, dan sertifikatnya ngaku presisi yang alatnya
        // nggak punya.
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Resolusi Nol',
            'serial_number' => 'RES-0',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'resolusi_rentang' => [['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolusi_rentang.0.resolusi');
    }

    /**
     * `bandResolusi()` mriksa band ber-`titik` duluan dan langsung balik begitu
     * ketemu yang cocok, jadi baris yang punya dua kunci bikin `maks`-nya nggak
     * pernah kepakai — diam, tanpa error, dan ketahuannya baru waktu sertifikat
     * kecetak dengan desimal yang salah.
     */
    public function test_baris_yang_punya_titik_dan_maks_sekaligus_ditolak_422(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Dua Kunci',
            'serial_number' => 'DUA-KUNCI',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'resolusi_rentang' => [
                ['titik' => 25, 'maks' => 100, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolusi_rentang.0.maks');
    }

    /**
     * Golongan terakhir Turbidimeter — `maks: null` itu nilai yang SAH (nampung
     * sisa pembacaan di atas band sebelumnya), bukan baris yang belum diisi.
     */
    public function test_band_maks_null_golongan_terakhir_diterima(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Turbidimeter',
            'serial_number' => 'TB-01',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            'resolusi_rentang' => [
                ['maks' => 10, 'resolusi' => 0.01],
                ['maks' => 100, 'resolusi' => 0.1],
                ['maks' => null, 'resolusi' => 1],
            ],
        ])->assertCreated();

        $alat = Equipment::where('serial_number', 'TB-01')->firstOrFail();

        $this->assertSame(0.1, $alat->resolusiPada(100.0));
        $this->assertSame(1.0, $alat->resolusiPada(5000.0));
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
