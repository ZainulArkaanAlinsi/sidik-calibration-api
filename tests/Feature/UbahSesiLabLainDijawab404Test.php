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
use Tests\TestCase;

/**
 * R2-5 (QA ronde 2): `PUT /api/calibrations/{sesi}` milik lab lain dijawab 404,
 * bukan 422 validasi.
 *
 * `CalibrationRequest` (FormRequest) divalidasi SEBELUM controller memanggil
 * `pastikanBolehLihat()`, jadi badan kosong ke sesi lab lain menjawab "Alat yang
 * mau dikalibrasi wajib diisi" (422) sementara ID yang tidak ada menjawab 404 —
 * selisih itu membuka keberadaan ID sesi lab lain. `authorize()` sekarang
 * memeriksa kepemilikan lebih dulu (pola `PenugasanUbahRequest::authorize`).
 * Sama untuk `PUT /calibrations/autoclave/{sesi}`.
 */
class UbahSesiLabLainDijawab404Test extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $teknisi;

    private User $teknisiLain;

    private CalibrationSession $sesiMilik;

    private CalibrationSession $sesiAsing;

    private CalibrationSession $sesiRekan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $this->teknisiLain = User::factory()->create(['organization_id' => $this->lab->id]);
        $teknisiAsing = User::factory()->create(['organization_id' => $this->labLain->id]);

        $this->sesiMilik = $this->sesi($this->lab, $this->teknisi);
        $this->sesiRekan = $this->sesi($this->lab, $this->teknisiLain);
        $this->sesiAsing = $this->sesi($this->labLain, $teknisiAsing);
    }

    private function sesi(Organization $lab, User $teknisi): CalibrationSession
    {
        $pt = Customer::factory()->create(['organization_id' => $lab->id]);
        $alat = Equipment::factory()->create([
            'organization_id' => $lab->id,
            'customer_id' => $pt->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $lab->id])->id,
        ]);

        return CalibrationSession::factory()->create([
            'organization_id' => $lab->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    public function test_put_sesi_lab_lain_dijawab_404_sama_dengan_id_yang_tidak_ada(): void
    {
        foreach ([$this->admin, $this->teknisi] as $pemanggil) {
            $asing = $this->actingAs($pemanggil)->putJson("/api/calibrations/{$this->sesiAsing->id}", []);
            $tidakAda = $this->actingAs($pemanggil)->putJson('/api/calibrations/99999999', []);

            $this->assertSame(404, $asing->getStatusCode(), "{$pemanggil->role}: sesi lab lain harus 404, bukan validasi.");
            $this->assertSame($tidakAda->getStatusCode(), $asing->getStatusCode());
            $this->assertArrayNotHasKey('errors', $asing->json() ?? [], 'Jawaban 404 tidak boleh memuat galat validasi.');
        }
    }

    public function test_put_autoclave_sesi_lab_lain_dijawab_404(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/autoclave/{$this->sesiAsing->id}", [])
            ->assertNotFound();
    }

    public function test_percobaan_ke_sesi_lab_lain_tetap_tercatat_sekali(): void
    {
        $this->actingAs($this->admin)->putJson("/api/calibrations/{$this->sesiAsing->id}", [])->assertNotFound();

        $this->assertSame(1, AuditLog::query()
            ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->where('organization_id', $this->lab->id)
            ->where('entity_id', $this->sesiAsing->id)
            ->count());
    }

    public function test_teknisi_ke_sesi_rekan_sama_dijawab_404_seperti_get(): void
    {
        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$this->sesiRekan->id}")->assertNotFound();
        $this->actingAs($this->teknisi)->putJson("/api/calibrations/{$this->sesiRekan->id}", [])->assertNotFound();
    }

    public function test_pemilik_sesi_dan_admin_tetap_sampai_ke_validasi(): void
    {
        $this->actingAs($this->teknisi)->putJson("/api/calibrations/{$this->sesiMilik->id}", [])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/calibrations/{$this->sesiMilik->id}", [])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/calibrations/{$this->sesiRekan->id}", [])->assertUnprocessable();
    }
}
