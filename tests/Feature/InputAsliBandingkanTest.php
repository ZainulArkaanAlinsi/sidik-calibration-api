<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K-50-01: Master Data/super admin mengoreksi lembar teknisi lewat PUT, dan isian
 * ASLI teknisi tetap utuh (`input_asli`, tulis-sekali) walau `isiUlangPengukuran()`
 * menghapus-tulis-ulang `raw_measurements`. `GET /calibrations/{c}/bandingkan`
 * menampilkan asli lawan sekarang per sel + siapa/kapan/alasan.
 */
class InputAsliBandingkanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $org->id, 'kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create(['organization_id' => $org->id]);
    }

    /** @param  list<float>  $baca */
    private function payload(array $baca = [50.02, 50.01, 50.03], array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => $baca]],
            ...$ubah,
        ];
    }

    private function kirimTeknisi(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payload())->assertCreated()->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    private function koreksiAdmin(CalibrationSession $sesi, array $baca, string $alasan = 'Salah ketik, angka benar dari lembar kertas.'): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload($baca, ['alasan_koreksi' => $alasan]))
            ->assertOk();
    }

    private function hashAsli(CalibrationSession $sesi): string
    {
        return hash('sha256', (string) DB::table('calibration_sessions')->where('id', $sesi->id)->value('input_asli'));
    }

    public function test_kirim_pertama_membekukan_isian_asli_teknisi(): void
    {
        $sesi = $this->kirimTeknisi();

        $asli = $sesi->fresh()->input_asli;
        $this->assertSame('pembacaan', $asli['jenis']);
        $this->assertSame([50.02, 50.01, 50.03], array_column($asli['pembacaan'], 'pembacaan'));
    }

    public function test_draft_belum_membekukan_asli(): void
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payload(ubah: ['status' => CalibrationSession::STATUS_DRAFT]))
            ->assertCreated()->json('data.id');

        $this->assertNull(CalibrationSession::findOrFail($id)->input_asli);
    }

    public function test_koreksi_admin_berulang_tidak_mengubah_asli_sedikit_pun(): void
    {
        $sesi = $this->kirimTeknisi();
        $hash = $this->hashAsli($sesi);

        $this->koreksiAdmin($sesi, [50.05, 50.01, 50.03]);
        $this->koreksiAdmin($sesi, [50.05, 50.06, 50.03], 'Pembacaan kedua juga salah salin dari logger.');

        $this->assertSame($hash, $this->hashAsli($sesi), 'input_asli berubah sesudah koreksi admin.');
        $this->assertSame([50.05, 50.06, 50.03], RawMeasurement::where('calibration_session_id', $sesi->id)
            ->orderBy('pembacaan_ke')->pluck('pembacaan')->map(fn ($v) => (float) $v)->all());
    }

    public function test_sesi_lama_tanpa_asli_dibekukan_sebelum_koreksi_pertama(): void
    {
        $sesi = $this->kirimTeknisi();
        DB::table('calibration_sessions')->where('id', $sesi->id)->update(['input_asli' => null]);

        $this->koreksiAdmin($sesi, [50.09, 50.01, 50.03]);

        $asli = $sesi->fresh()->input_asli;
        $this->assertSame([50.02, 50.01, 50.03], array_column($asli['pembacaan'], 'pembacaan'));
    }

    public function test_bandingkan_menunjuk_sel_yang_berubah_siapa_kapan_alasan(): void
    {
        $sesi = $this->kirimTeknisi();
        $this->koreksiAdmin($sesi, [50.05, 50.01, 50.03], 'Digit kedua salah salin dari logger.');

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/calibrations/{$sesi->id}/bandingkan")
            ->assertOk()
            ->assertJsonPath('data.ada_asli', true)
            ->assertJsonPath('data.jumlah_berubah', 1)
            ->json('data');

        $berubah = array_values(array_filter($data['sel'], fn ($s) => $s['berubah']));
        $this->assertCount(1, $berubah);
        $this->assertEquals(50.02, $berubah[0]['asli']);
        $this->assertEquals(50.05, $berubah[0]['sekarang']);
        $this->assertSame($this->admin->id, $berubah[0]['diubah_oleh']['id']);
        $this->assertNotNull($berubah[0]['diubah_pada']);
        $this->assertSame('Koreksi pembacaan oleh Master Data: Digit kedua salah salin dari logger.', $berubah[0]['alasan']);
    }

    public function test_bandingkan_hanya_admin_dan_satu_organisasi(): void
    {
        $sesi = $this->kirimTeknisi();

        $this->actingAs($this->teknisi, 'sanctum')->getJson("/api/calibrations/{$sesi->id}/bandingkan")->assertForbidden();

        $lain = User::factory()->admin()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($lain, 'sanctum')->getJson("/api/calibrations/{$sesi->id}/bandingkan")->assertNotFound();

        $super = User::factory()->create(['organization_id' => $sesi->organization_id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($super, 'sanctum')->getJson("/api/calibrations/{$sesi->id}/bandingkan")->assertOk();
    }
}
