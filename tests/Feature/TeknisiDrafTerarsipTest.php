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

/**
 * Tepi arsip draf yang ditemukan peninjau PR #277 (18 Nov 2026):
 * `DELETE /technicians/{t}` memeriksa riwayat lewat `calibrationSessions()`, yang
 * sejak `SoftDeletes` di sesi TIDAK melihat draf terarsip. Teknisi yang hanya
 * punya draf terarsip lolos penjaga, tokennya terhapus, lalu `User` (hapus
 * permanen) melanggar FK `calibration_sessions.teknisi_id` -> 500.
 */
class TeknisiDrafTerarsipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->lab->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->lab->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->lab->id])->id,
        ]);
    }

    private function draf(array $ubah = []): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'organization_id' => $this->lab->id,
            'equipment_id' => $this->alat->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'keputusan' => null,
            ...$ubah,
        ]);
    }

    private function arsipkan(User $akun, CalibrationSession $sesi)
    {
        return $this->actingAs($akun)->postJson("/api/calibrations/{$sesi->id}/arsipkan", ['alasan' => 'Salah pilih alat']);
    }

    // ───────────────────────────── hapus teknisi ─────────────────────────────

    public function test_teknisi_yang_hanya_punya_draf_terarsip_tidak_bisa_dihapus(): void
    {
        $sesi = $this->draf();
        $this->arsipkan($this->teknisi, $sesi)->assertOk();
        $this->assertSoftDeleted('calibration_sessions', ['id' => $sesi->id]);

        $this->teknisi->createToken('hp-teknisi');

        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$this->teknisi->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $pesan): bool => str_contains($pesan, 'riwayat kalibrasi'));

        // Tidak ada yang berubah: akunnya ada, tokennya TIDAK ikut terhapus, drafnya tetap terarsip.
        $this->assertNotNull(User::find($this->teknisi->id));
        $this->assertSame(1, $this->teknisi->tokens()->count(), 'token tidak boleh terhapus oleh penolakan');
        $this->assertSoftDeleted('calibration_sessions', ['id' => $sesi->id]);
    }

    public function test_teknisi_tanpa_sesi_sama_sekali_tetap_bisa_dihapus(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$this->teknisi->id}")->assertOk();

        $this->assertNull(User::find($this->teknisi->id));
    }

    public function test_teknisi_dengan_sesi_hidup_tetap_ditolak(): void
    {
        $this->draf();

        $this->actingAs($this->admin)->deleteJson("/api/technicians/{$this->teknisi->id}")->assertUnprocessable();
        $this->assertNotNull(User::find($this->teknisi->id));
    }
}
