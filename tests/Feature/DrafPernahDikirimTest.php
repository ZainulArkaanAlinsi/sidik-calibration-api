<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Draf yang lahir dari lembar yang PERNAH dikirim tidak boleh diarsipkan
 * (temuan peninjau PR #277, 18 Nov 2026).
 *
 * Sesi yang dikirim lalu dikembalikan dan disimpan lagi sebagai draf tetap
 * berstatus `draft`, tetapi `input_asli`/`submitted_at` terisi dan
 * `catatan_revisi` admin menempel di sana: mengarsipkannya menghilangkan teguran
 * dari antrean teknisi. Jalur koreksinya tetap lanjutkan lembar lalu kirim ulang.
 */
class DrafPernahDikirimTest extends TestCase
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

    public function test_draf_dengan_input_asli_terbeku_tidak_bisa_diarsipkan(): void
    {
        $sesi = $this->draf(['catatan_revisi' => 'Angka titik 2 meragukan, ukur ulang.']);
        // `input_asli` tulis-sekali & bukan fillable: ditanam seperti `bekukanInputAsli()`.
        DB::table('calibration_sessions')->where('id', $sesi->id)->update([
            'input_asli' => json_encode(['jenis' => 'pembacaan', 'pembacaan' => [['titik_ke' => 1, 'pembacaan_ke' => 1, 'pembacaan' => 50.02]]]),
        ]);

        foreach ([$this->teknisi, $this->admin] as $akun) {
            $this->arsipkan($akun, $sesi)
                ->assertUnprocessable()
                ->assertJsonPath('kode', 'pernah_dikirim');
        }

        $segar = CalibrationSession::findOrFail($sesi->id);
        $this->assertNull($segar->deleted_at, 'tidak boleh terarsip');
        $this->assertSame('Angka titik 2 meragukan, ukur ulang.', $segar->catatan_revisi, 'teguran admin tetap ada');
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_DIARSIPKAN)->where('entity_id', $sesi->id)->count());
    }

    public function test_draf_dengan_waktu_kirim_terisi_tidak_bisa_diarsipkan(): void
    {
        $sesi = $this->draf(['submitted_at' => now()->subDay()]);

        $this->arsipkan($this->teknisi, $sesi)->assertUnprocessable()->assertJsonPath('kode', 'pernah_dikirim');
        $this->assertNull(CalibrationSession::findOrFail($sesi->id)->deleted_at);
    }

    /** Kontrol: draf yang benar-benar belum pernah dikirim tetap bisa diarsipkan. */
    public function test_draf_murni_tetap_bisa_diarsipkan(): void
    {
        $sesi = $this->draf();

        $this->arsipkan($this->teknisi, $sesi)->assertOk()->assertJsonPath('data.diarsipkan', true);
        $this->assertSoftDeleted('calibration_sessions', ['id' => $sesi->id]);
    }
}
