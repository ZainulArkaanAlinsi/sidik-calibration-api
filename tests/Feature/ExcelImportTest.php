<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import master data dari Excel buat masa transisi (spesifikasi poin 12C).
 *
 * Pakai CSV di test — parser-nya sama (OpenSpout), tapi filenya bisa ditulis
 * apa adanya di sini jadi isinya kebaca orang yang baca test ini.
 */
class ExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function csv(string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-').'.csv';
        file_put_contents($path, $isi);

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    public function test_uji_coba_nggak_nulis_apa_apa_ke_database(): void
    {
        $file = $this->csv("Nama PT,Alamat,Telepon\nPT Tirta Gracia,Jl. Arteri Primer A-10,022-1234\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'customers'])
            ->assertOk()
            ->assertJsonPath('data.uji_coba', true)
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $this->assertSame(0, Customer::count());
    }

    public function test_import_beneran_ngisi_database(): void
    {
        $file = $this->csv("Nama PT,Alamat,Telepon\nPT Tirta Gracia,Jl. Arteri Primer A-10,022-1234\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'customers', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Tirta Gracia',
            'alamat' => 'Jl. Arteri Primer A-10',
        ]);
    }

    public function test_baris_yang_udah_ada_diperbarui_bukan_dikembarin(): void
    {
        Customer::factory()->create(['nama' => 'PT Tirta Gracia', 'alamat' => 'Alamat lama']);

        $file = $this->csv("Nama PT,Alamat\nPT Tirta Gracia,Alamat baru\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'customers', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.diperbarui', 1)
            ->assertJsonPath('data.ringkasan.dibuat', 0);

        $this->assertSame(1, Customer::count());
        $this->assertSame('Alamat baru', Customer::firstOrFail()->alamat);
    }

    public function test_header_dikenali_walau_beda_tulisan_dan_urutannya_diacak(): void
    {
        // Urutan kolomnya kebalik, hurufnya campur, ada tanda baca — semua
        // varian ini muncul di file Excel lab yang beneran.
        $file = $this->csv("SERIAL NUMBER,Traceable to SI through,nama,Merk/Type\nHC32513535,Merck KGaA,pH Buffer Solution 4,Supelco\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'standards', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $standar = Standard::firstOrFail();
        $this->assertSame('HC32513535', $standar->serial_number);
        $this->assertSame('Merck KGaA', $standar->tertelusur_ke);
    }

    public function test_kolom_wajib_yang_hilang_nolak_seluruh_file(): void
    {
        $file = $this->csv("Alamat,Telepon\nJl. Mawar,022-1234\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'customers'])
            ->assertStatus(422);
    }

    public function test_angka_format_indonesia_maupun_inggris_kebaca_bener(): void
    {
        $file = $this->csv("nama,ketidakpastian,faktor cakupan\nStandar A,\"0,0004\",2\nStandar B,0.0008,2\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'standards', 'uji_coba' => false])
            ->assertOk();

        $this->assertEqualsWithDelta(0.0004, Standard::where('nama', 'Standar A')->value('ketidakpastian'), 1e-9);
        $this->assertEqualsWithDelta(0.0008, Standard::where('nama', 'Standar B')->value('ketidakpastian'), 1e-9);
    }

    public function test_alat_dengan_pt_yang_belum_terdaftar_dilewati_bukan_bikin_pt_baru(): void
    {
        $file = $this->csv("Nama Alat,Pemilik,Kategori,Serial Number,Toleransi\npH Meter,PT Belum Ada,ph,B628755900,0.2\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'equipments', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 1)
            ->assertJsonPath('data.ringkasan.dibuat', 0);

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Equipment::count());
    }

    public function test_alat_kepasang_ke_pt_dan_kategori_yang_bener(): void
    {
        $pelanggan = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        $kategori = EquipmentCategory::factory()->create(['kode' => 'ph', 'nama' => 'Derajat Keasaman']);

        $file = $this->csv("Nama Alat,Pemilik,Kategori,Serial Number,Merk,Resolusi,Toleransi\npH Meter,PT Tirta Gracia,Derajat Keasaman,B628755900,Mettler Toledo,0.01,0.2\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'equipments', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $alat = Equipment::firstOrFail();
        $this->assertSame($pelanggan->id, $alat->customer_id);
        $this->assertSame($kategori->id, $alat->equipment_category_id);
        $this->assertSame('pH Meter', $alat->nama_alat);
        $this->assertEqualsWithDelta(0.2, (float) $alat->toleransi, 1e-9);
    }

    public function test_alat_tanpa_kategori_yang_dikenal_dilewati_bukan_ditebak(): void
    {
        Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        $file = $this->csv("Nama Alat,Pemilik,Kategori,Serial Number\npH Meter,PT Tirta Gracia,Kategori Ngawur,B628755900\n");

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'equipments', 'uji_coba' => false])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 1);

        $this->assertSame(0, Equipment::count());
    }

    public function test_import_cuma_buat_admin(): void
    {
        $teknisi = User::factory()->create();
        $file = $this->csv("nama\nPT Apa Saja\n");

        $this->actingAs($teknisi)
            ->post('/api/imports/excel', ['file' => $file, 'tipe' => 'customers'])
            ->assertForbidden();
    }

    public function test_daftar_kolom_yang_dikenali_bisa_dilihat_duluan(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/imports/format')
            ->assertOk()
            ->assertJsonPath('data.tipe', ['customers', 'equipments', 'standards'])
            ->assertJsonStructure(['data' => ['kolom' => ['customers', 'equipments', 'standards']]]);
    }
}
