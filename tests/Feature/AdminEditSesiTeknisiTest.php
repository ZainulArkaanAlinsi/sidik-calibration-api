<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin bisa nambah & ngedit data yang UDAH diisi teknisi, tanpa harus
 * mbalikin lembarnya dulu.
 *
 * Sebelum ini `menunggu_approval` ngunci semua orang. Admin yang nemu satu
 * angka keliru waktu review cuma punya satu jalan: `reject()` — lembar balik
 * ke teknisi, teknisi benerin, submit ulang, admin review lagi. Buat salah
 * ketik satu digit, itu perjalanan bolak-balik yang nggak perlu.
 *
 * Yang dibuka cuma jendela statusnya, bukan aturan isinya: admin lewat
 * `CalibrationRequest` yang sama, dan `disetujui` tetap terkunci buat semua
 * orang.
 */
class AdminEditSesiTeknisiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    private function sesi(string $status): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],
            ],
            ...$ubah,
        ];
    }

    public function test_admin_bisa_ngedit_lembar_yang_udah_disubmit_teknisi(): void
    {
        $sesi = $this->sesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([
                'alat_serial_number' => 'DIBETULIN-ADMIN',
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.05, 50.04, 50.06]],
                ],
            ]))
            ->assertOk();

        $sesi->refresh();

        $this->assertSame('DIBETULIN-ADMIN', $sesi->alat_serial_number);
        $this->assertEqualsWithDelta(
            50.05,
            (float) $sesi->uncertaintyCalculations()->first()->rata_rata,
            1e-9,
        );
    }

    /**
     * Admin panel kebuka semuanya: field administratif YANG DIBUANG dari layar
     * teknisi tetap bisa diisi admin lewat jalur yang sama.
     */
    public function test_admin_bisa_ngisi_field_administratif_sekalian(): void
    {
        $sesi = $this->sesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([
                'nomor_order' => '2405.32.A.NK',
            ]))
            ->assertOk();

        $this->assertSame('2405.32.A.NK', $sesi->refresh()->nomor_order);
    }

    public function test_teknisi_tetap_nggak_bisa_ngedit_lembar_yang_nunggu_approval(): void
    {
        $sesi = $this->sesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload())
            ->assertStatus(422);
    }

    /**
     * Sertifikat yang udah terbit nggak boleh berubah diam-diam — termasuk
     * sama admin. Ini batas yang nggak ikut dilonggarkan.
     */
    public function test_sesi_disetujui_terkunci_buat_admin_juga(): void
    {
        $sesi = $this->sesi(CalibrationSession::STATUS_DISETUJUI);

        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sesi yang udah disetujui nggak bisa diubah — sertifikatnya udah terbit.');
    }

    /** Siapa yang ngubah apa harus kecatat — ini lembar terakreditasi. */
    public function test_editan_admin_kecatat_di_audit_log(): void
    {
        $sesi = $this->sesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([
                'alat_serial_number' => 'JEJAK-AUDIT',
            ]))
            ->assertOk();

        $log = AuditLog::where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Editan admin harus ninggalin jejak audit');
        $this->assertSame($this->admin->id, $log->changed_by);
    }
}
