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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
    }

    public function test_dashboard_ngitung_alat_dan_yang_overdue(): void
    {
        Equipment::factory()->count(3)->create();
        Equipment::factory()->overdue()->count(2)->create();

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_alat', 'alat_overdue', 'kalibrasi_draft', 'menunggu_approval', 'sertifikat_bulan_ini']])
            ->assertJsonPath('data.total_alat', 5)
            ->assertJsonPath('data.alat_overdue', 2);
    }

    /**
     * Teknisi cuma lihat kalibrasi miliknya sendiri, admin lihat semua — dan
     * role-nya diambil dari token, bukan dari yang dikirim mobile.
     */
    public function test_teknisi_cuma_ngitung_kalibrasi_miliknya_sendiri(): void
    {
        $alat = Equipment::factory()->create();
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $bikinDraft = fn (User $u) => CalibrationSession::create([
            'organization_id' => $u->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $u->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => now(),
        ]);

        $bikinDraft($teknisi);
        $bikinDraft($teknisiLain);
        $bikinDraft($teknisiLain);

        $this->actingAs($teknisi)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kalibrasi_draft', 1);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kalibrasi_draft', 3);
    }
}
