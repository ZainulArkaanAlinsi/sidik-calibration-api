<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportExcel;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman Import Excel di panel web (spesifikasi poin 12C).
 *
 * Yang diuji BUKAN parsing-nya (itu punya ExcelImporterTest), tapi penjagaan
 * dua langkahnya: uji coba nggak boleh nulis, dan Terapkan nggak boleh jalan
 * sebelum uji coba.
 */
class ImportExcelPanelTest extends TestCase
{
    use RefreshDatabase;

    private function siapkanBerkas(): string
    {
        Storage::fake('local');

        $isi = "Nama PT,Alamat\nPT Uji Coba,Jl. Contoh No. 1\n";
        Storage::disk('local')->put('import/pelanggan.csv', $isi);

        return 'import/pelanggan.csv';
    }

    public function test_uji_coba_nggak_nulis_apa_apa(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $path = $this->siapkanBerkas();

        Livewire::actingAs($admin)
            ->test(ImportExcel::class)
            ->set('data.tipe', 'customers')
            ->set('data.berkas', [$path])
            ->call('ujiCoba')
            ->assertSuccessful();

        // Inti uji coba: ringkasannya bilang "1 dibuat", tapi DB-nya bersih.
        // Kalau baris ini gagal, admin bisa ngira dia cuma ngintip padahal
        // datanya udah ketulis.
        $this->assertDatabaseMissing('customers', ['nama' => 'PT Uji Coba']);
    }

    public function test_terapkan_ditolak_sebelum_uji_coba(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $path = $this->siapkanBerkas();

        Livewire::actingAs($admin)
            ->test(ImportExcel::class)
            ->set('data.tipe', 'customers')
            ->set('data.berkas', [$path])
            // Langsung Terapkan tanpa uji coba — ini yang harus dicegah.
            ->call('terapkan')
            ->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['nama' => 'PT Uji Coba']);
    }

    public function test_terapkan_sesudah_uji_coba_beneran_nulis(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $path = $this->siapkanBerkas();

        Livewire::actingAs($admin)
            ->test(ImportExcel::class)
            ->set('data.tipe', 'customers')
            ->set('data.berkas', [$path])
            ->call('ujiCoba')
            ->call('terapkan')
            ->assertSuccessful();

        $this->assertDatabaseHas('customers', ['nama' => 'PT Uji Coba']);
    }

    public function test_non_admin_nggak_bisa_buka(): void
    {
        Organization::factory()->create();

        // Teknisi & viewer nggak boleh nyentuh master data lewat pintu ini.
        $this->actingAs(User::factory()->create());
        $this->assertFalse(ImportExcel::canAccess());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $this->assertFalse(ImportExcel::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ImportExcel::canAccess());
    }

    public function test_customer_yang_udah_ada_kebaca_sebagai_diperbarui(): void
    {
        // `organization_id` diambil dari organisasi yang baru dibikin, bukan
        // dipatok `1`. Di SQLite in-memory id-nya selalu 1; di MySQL
        // AUTO_INCREMENT terus maju lintas tes, jadi FK-nya putus.
        $org = Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        Customer::factory()->create(['nama' => 'PT Uji Coba', 'organization_id' => $org->id]);
        $path = $this->siapkanBerkas();

        $komponen = Livewire::actingAs($admin)
            ->test(ImportExcel::class)
            ->set('data.tipe', 'customers')
            ->set('data.berkas', [$path])
            ->call('ujiCoba');

        $hasil = $komponen->get('hasil');

        // Uji coba tetap nulis-lalu-rollback di server, jadi pencocokan
        // "sudah ada / belum" kelihatan apa adanya — bukan ditebak.
        $this->assertSame(1, $hasil['ringkasan']['diperbarui'] ?? 0);
        $this->assertSame(0, $hasil['ringkasan']['dibuat'] ?? 0);
    }
}
