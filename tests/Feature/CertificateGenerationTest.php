<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateGenerationTest extends TestCase
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
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function buatSesiMenungguApproval(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_approve_nge_dispatch_job_generate_sertifikat(): void
    {
        Queue::fake();
        $sesi = $this->buatSesiMenungguApproval();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        Queue::assertPushed(GenerateCertificate::class, fn ($job) => $job->calibrationSessionId === $sesi->id);
    }

    public function test_job_bikin_sertifikat_terbit_lengkap_dengan_pdf(): void
    {
        Storage::fake('local');
        $sesi = $this->buatSesiMenungguApproval();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $sertifikat = $sesi->certificate()->firstOrFail();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertStringStartsWith('CAL/', $sertifikat->nomor);
        $this->assertNotNull($sertifikat->qr_token);
        // PDF beneran ketulis ke disk.
        Storage::disk('local')->assertExists($sertifikat->pdf_path);
    }

    public function test_sertifikat_fail_tetap_terbit(): void
    {
        Storage::fake('local');
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.9, 50.8, 50.85]]],
        ])->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->assertSame('FAIL', $sesi->keputusan);

        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id))->handle();

        // FAIL itu temuan yang sah — sertifikatnya tetap terbit.
        $this->assertSame(Certificate::STATUS_TERBIT, $sesi->certificate()->value('status'));
    }

    public function test_job_idempoten_approve_dua_kali_nggak_bikin_sertifikat_dobel(): void
    {
        Storage::fake('local');
        $sesi = $this->buatSesiMenungguApproval();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id))->handle();
        (new GenerateCertificate($sesi->id))->handle();

        $this->assertSame(1, Certificate::where('calibration_session_id', $sesi->id)->count());
    }

    public function test_sertifikat_hasil_generate_kebaca_di_verifikasi_publik(): void
    {
        Storage::fake('local');
        $sesi = $this->buatSesiMenungguApproval();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id))->handle();

        $token = $sesi->certificate()->value('qr_token');

        // Token hasil generate beneran nyambung ke halaman verifikasi publik.
        $this->getJson("/api/verify/{$token}")
            ->assertOk()
            ->assertJsonPath('data.keputusan', 'PASS');
    }
}
