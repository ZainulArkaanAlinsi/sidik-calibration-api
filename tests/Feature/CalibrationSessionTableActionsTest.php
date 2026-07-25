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

    /**
     * Tombol Setujui di panel web SEBELUMNYA cuma ngubah status & nembak job —
     * ngelewatin `CalibrationValidator` yang di jalur API nahan penerbitan.
     * Dua pintu ke keputusan yang sama, satu ada penjaganya satu nggak.
     */
    public function test_approve_di_panel_diblokir_kalau_ada_temuan_fatal(): void
    {
        [$admin, $sesi] = $this->sesiTanpaHitungan();

        Livewire::actingAs($admin)
            ->test(ListCalibrationSessions::class)
            ->callTableAction('approve', $sesi, ['abaikan_peringatan' => false]);

        // Sesi tanpa satu pun titik kehitung = temuan fatal. Statusnya WAJIB
        // nggak berubah, dan sertifikatnya nggak boleh kebentuk.
        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            $sesi->fresh()->status,
        );
        $this->assertDatabaseMissing('certificates', ['calibration_session_id' => $sesi->id]);
    }

    public function test_periksa_nggak_ngubah_status_sesi(): void
    {
        [$admin, $sesi] = $this->sesiTanpaHitungan();

        Livewire::actingAs($admin)
            ->test(ListCalibrationSessions::class)
            ->callTableAction('periksa', $sesi)
            ->assertSuccessful();

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            $sesi->fresh()->status,
        );
    }

    public function test_lembar_perhitungan_kerender_di_panel(): void
    {
        [$admin, $sesi] = $this->sesiTanpaHitungan();

        // Blade-nya baca banyak kunci nested dari PerhitunganBuilder — salah
        // satu nama kunci aja bikin modalnya blank di produksi, dan itu nggak
        // ketahuan dari test yang cuma ngecek route.
        Livewire::actingAs($admin)
            ->test(ListCalibrationSessions::class)
            ->mountTableAction('perhitungan', $sesi)
            ->assertSuccessful();
    }

    /**
     * Sesi yang belum punya satu pun `UncertaintyCalculation` — bikin
     * validator ngeluarin temuan fatal.
     *
     * @return array{0: User, 1: CalibrationSession}
     */
    private function sesiTanpaHitungan(): array
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
        ]);

        $sesi = CalibrationSession::factory()->create([
            'equipment_id' => $alat->id,
            'teknisi_id' => User::factory()->create()->id,
            'standard_id' => Standard::factory()->create()->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        return [$admin, $sesi];
    }
}
