<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Global search bawaan Filament cuma nyari 1 kolom judul (`recordTitleAttribute`).
 * Tiap resource di-custom biar bisa ketemu dari field sekunder yang lebih sering
 * jadi patokan pencarian admin (nomor seri, nama pelanggan/alat, dst).
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_sertifikat_ketemu_lewat_nomor_sesi_bukan_cuma_nomor_sertifikat(): void
    {
        $sesi = CalibrationSession::factory()->create(['nomor_sesi' => 'SESI-UNIK-001']);
        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/07/9999',
        ]);

        $hasil = CertificateResource::getGlobalSearchResults('SESI-UNIK-001');

        $this->assertCount(1, $hasil);
        $this->assertSame($sertifikat->nomor, $hasil->first()->title);
    }

    public function test_alat_ketemu_lewat_nomor_seri_dan_nama_pelanggan(): void
    {
        $pelanggan = Customer::factory()->create(['nama' => 'PT Pelanggan Unik']);
        $alat = Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'serial_number' => 'SN-UNIK-777',
        ]);

        $this->assertCount(1, EquipmentResource::getGlobalSearchResults('SN-UNIK-777'));
        $this->assertCount(1, EquipmentResource::getGlobalSearchResults('PT Pelanggan Unik'));

        $details = EquipmentResource::getGlobalSearchResults('SN-UNIK-777')->first()->details;
        $this->assertSame('PT Pelanggan Unik', $details['Pelanggan']);
    }

    public function test_sesi_kalibrasi_ketemu_lewat_nama_alat(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'nama_alat' => 'Jangka Sorong Digital Unik',
        ]);
        CalibrationSession::factory()->create(['equipment_id' => $alat->id]);

        $this->assertCount(1, CalibrationSessionResource::getGlobalSearchResults('Jangka Sorong Digital Unik'));
    }

    public function test_pengguna_ketemu_lewat_id_pegawai(): void
    {
        User::factory()->create(['employee_id' => 'ASM-7777']);

        $this->assertCount(1, UserResource::getGlobalSearchResults('ASM-7777'));
    }

    public function test_pencarian_nggak_nembus_organisasi_lain(): void
    {
        $orgLain = Organization::factory()->create();
        Equipment::factory()->create([
            'organization_id' => $orgLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $orgLain->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $orgLain->id])->id,
            'serial_number' => 'SN-ORG-LAIN',
        ]);

        $this->assertCount(0, EquipmentResource::getGlobalSearchResults('SN-ORG-LAIN'));
    }
}
