<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /api/calibrations/preview` (Fase 2 #8) + `room_id` (#11).
 *
 * Preview ada supaya angka di layar input SAMA PERSIS sama yang nanti tercetak
 * di sertifikat — kalau mobile ngitung sendiri, dua angka bisa beda tipis dan
 * buat lab terakreditasi itu temuan.
 */
class CalibrationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'measurements' => [['titik_ukur' => 50.0, 'pembacaan' => [50.02, 50.01, 50.03]]],
        ];
    }

    public function test_preview_ngitung_tanpa_nyimpen_apa_pun(): void
    {
        $titik = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.keputusan', 'PASS')
            ->json('data.titik.0');

        // Pakai delta, bukan sama-persis: 50.02−50.0 di float itu
        // 0.020000000000003126, dan itu wajar — bukan tanda hitungannya salah.
        $this->assertEqualsWithDelta(50.02, $titik['rata_rata'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $titik['error'], 1e-9);
        $this->assertEqualsWithDelta(-0.02, $titik['koreksi'], 1e-9);

        // Inti endpoint ini: NOL efek samping.
        $this->assertDatabaseCount('calibration_sessions', 0);
        $this->assertDatabaseCount('raw_measurements', 0);
        $this->assertDatabaseCount('uncertainty_calculations', 0);
    }

    public function test_angka_preview_sama_persis_sama_yang_disimpen(): void
    {
        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payload())
            ->assertOk()->json('data.titik.0');

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            ...$this->payload(),
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $tersimpan = CalibrationSession::latest('id')->firstOrFail()
            ->uncertaintyCalculations()->firstOrFail();

        // Kalau dua jalur ini pernah nyimpang, sertifikat bakal beda dari yang
        // dilihat teknisi waktu ngisi.
        $this->assertEqualsWithDelta($preview['rata_rata'], $tersimpan->rata_rata, 1e-9);
        $this->assertEqualsWithDelta($preview['koreksi'], $tersimpan->koreksi, 1e-9);
        $this->assertEqualsWithDelta(
            $preview['ketidakpastian_diperluas'],
            $tersimpan->ketidakpastian_diperluas,
            1e-9,
        );
        $this->assertSame($preview['keputusan'], $tersimpan->keputusan);
    }

    public function test_pembacaan_kurang_dari_minimum_ditolak(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations/preview', [
            ...$this->payload(),
            'measurements' => [['titik_ukur' => 50.0, 'pembacaan' => [50.02]]],
        ])->assertStatus(422);
    }

    public function test_alat_tanpa_toleransi_ditolak_dengan_pesan_jelas(): void
    {
        $this->alat->update(['toleransi' => null]);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'toleransi'));
    }

    public function test_viewer_nggak_boleh_preview(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)
            ->postJson('/api/calibrations/preview', $this->payload())
            ->assertForbidden();
    }

    public function test_room_id_kesimpen_dan_kebalikin_sebagai_ruangan(): void
    {
        $ruangan = Room::factory()->create(['nama' => 'Lab. Uji A']);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'room_id' => $ruangan->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->assertSame($ruangan->id, $sesi->room_id);

        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.ruangan.nama', 'Lab. Uji A');
    }

    public function test_ruangan_organisasi_lain_ditolak(): void
    {
        $orgLain = Organization::factory()->create();
        $ruanganLain = Room::factory()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'room_id' => $ruanganLain->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertStatus(422);
    }
}
