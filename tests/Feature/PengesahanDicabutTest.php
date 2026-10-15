<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Penjaga K-50-07 (15 Okt 2026): pengesahan dicabut. Tidak ada rute sahkan /
 * kembalikan / tarik-pengajuan / antrean, tidak ada sakelar `GERBANG_PENGESAHAN`,
 * dan approve() menerbitkan langsung. Sesi `menunggu_pengesahan` yang tertinggal
 * dipindah `kalibrasi:pindahkan-pengesahan` (dry-run dulu).
 */
class PengesahanDicabutTest extends TestCase
{
    use RefreshDatabase;

    public function test_rute_pengesahan_tidak_ada(): void
    {
        foreach (Route::getRoutes() as $rute) {
            $this->assertDoesNotMatchRegularExpression('#/(sahkan|kembalikan-dari-pengesahan|tarik-pengajuan)$|pengesahan/antrean#', $rute->uri());
        }

        $sesi = CalibrationSession::factory()->create();
        $super = User::factory()->create(['organization_id' => $sesi->organization_id, 'role' => User::ROLE_SUPER_ADMIN]);

        foreach (['sahkan', 'kembalikan-dari-pengesahan', 'tarik-pengajuan'] as $akhir) {
            $this->actingAs($super, 'sanctum')->postJson("/api/calibrations/{$sesi->id}/{$akhir}")->assertNotFound();
        }
    }

    public function test_tidak_ada_izin_atau_sakelar_pengesahan(): void
    {
        foreach (['kalibrasi.sahkan', 'kalibrasi.kembalikan-dari-pengesahan', 'kalibrasi.tarik-pengajuan', 'pengesahan.antrean'] as $izin) {
            $this->assertArrayNotHasKey($izin, MatriksIzin::PETA);
        }

        $this->assertArrayNotHasKey('gerbang_pengesahan', config('kalibrasi'));
    }

    public function test_perintah_pindah_dry_run_tidak_menulis_dan_jalankan_memindahkan(): void
    {
        $antre = CalibrationSession::factory()->create(['status' => CalibrationSession::STATUS_MENUNGGU_PENGESAHAN]);
        $lain = CalibrationSession::factory()->create(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        $this->artisan('kalibrasi:pindahkan-pengesahan')->assertSuccessful();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_PENGESAHAN, $antre->fresh()->status);

        $this->artisan('kalibrasi:pindahkan-pengesahan', ['--jalankan' => true])->assertSuccessful();

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $antre->fresh()->status);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $lain->fresh()->status);
        $this->assertTrue(AuditLog::where('entity_type', 'calibration_sessions')->where('entity_id', $antre->id)
            ->where('note', 'like', '%K-50-07%')->exists());
    }
}
