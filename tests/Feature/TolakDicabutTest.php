<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Penjaga K-50-01 (15 Okt 2026): Tolak dicabut seluruhnya. Master Data/super
 * admin mengoreksi lembar lewat PUT lalu menyetujui; tidak ada jalur yang
 * mengembalikan lembar ke teknisi. Sesi `perlu_revisi` lama tetap bisa dikirim
 * ulang (dijaga CalibrationTest).
 */
class TolakDicabutTest extends TestCase
{
    use RefreshDatabase;

    public function test_tidak_ada_rute_reject_sesi_kalibrasi(): void
    {
        foreach (Route::getRoutes() as $rute) {
            $this->assertStringNotContainsString('calibrations/{calibration}/reject', $rute->uri());
        }
    }

    public function test_matriks_izin_tidak_punya_izin_tolak(): void
    {
        $this->assertArrayNotHasKey('kalibrasi.tolak', MatriksIzin::PETA);
    }

    public function test_reject_dijawab_404_untuk_admin_dan_super_admin(): void
    {
        $sesi = CalibrationSession::factory()->create(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $u = User::factory()->create(['organization_id' => $sesi->organization_id, 'role' => $role]);
            $this->actingAs($u, 'sanctum')
                ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Coba tolak.'])
                ->assertNotFound();
        }

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
    }

    public function test_panel_tidak_punya_aksi_tolak(): void
    {
        $berkas = file_get_contents(app_path('Filament/Resources/CalibrationSessions/Tables/CalibrationSessionsTable.php'));
        $this->assertStringNotContainsString("Action::make('reject')", $berkas);
    }
}
