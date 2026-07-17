<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Modal detail sesi kalibrasi di panel admin sekarang nampilin rincian GUM
 * per titik ukur (bukan cuma badge PASS/FAIL) — diuji langsung lewat
 * komponen Livewire-nya biar nangkep salah nama relasi/kolom.
 */
class CalibrationSessionTableActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_lihat_detail_sesi_termasuk_titik_ukur(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create();
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
        ]);
        $standar = Standard::factory()->create();

        $sesi = CalibrationSession::factory()->create([
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'standard_id' => $standar->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            'keputusan' => 'PASS',
        ]);

        UncertaintyCalculation::create([
            'calibration_session_id' => $sesi->id,
            'titik_ke' => 1,
            'titik_ukur' => 50.0,
            'rata_rata' => 50.02,
            'error' => 0.02,
            'koreksi' => -0.02,
            'standar_deviasi' => 0.01,
            'jumlah_pengulangan' => 3,
            'type_a' => 0.005,
            'type_b_components' => ['standar' => 0.0002],
            'type_b' => 0.0002,
            'ketidakpastian_gabungan' => 0.005,
            'faktor_cakupan_k' => 2,
            'derajat_kebebasan_efektif' => 2,
            'ketidakpastian_diperluas' => 0.01,
            'toleransi' => 0.05,
            'keputusan' => 'PASS',
            'calculated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListCalibrationSessions::class)
            ->mountTableAction('view', $sesi)
            ->assertSuccessful();
    }
}
