<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Mail\SertifikatKalibrasi;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kirim sertifikat ke pelanggan lewat email (Fase 2 §3d).
 *
 * Dua hal yang bikin ini harus di backend & diuji: lampirannya wajib kekirim
 * (email tanpa PDF lebih membingungkan daripada nggak ada email), dan tiap
 * pengiriman wajib tercatat buat audit.
 */
class KirimSertifikatEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Certificate $sertifikat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $standar = Standard::factory()->create();

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $this->sertifikat = $sesi->certificate()->firstOrFail();
    }

    public function test_admin_kirim_sertifikat_dan_tercatat(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", [
                'ke' => ['pic@pelanggan.co.id'],
                'cc' => ['arsip@sidik.test'],
            ])
            ->assertOk()
            ->assertJsonPath('data.penerima.0', 'pic@pelanggan.co.id')
            ->assertJsonPath('data.cc.0', 'arsip@sidik.test');

        Mail::assertSent(SertifikatKalibrasi::class, fn ($mail) => $mail->hasTo('pic@pelanggan.co.id')
            && $mail->hasCc('arsip@sidik.test'));

        // Jejak audit: siapa ngirim ke siapa, kapan.
        $this->assertDatabaseHas('certificate_emails', [
            'certificate_id' => $this->sertifikat->id,
            'dikirim_oleh' => $this->admin->id,
        ]);
    }

    public function test_pdf_ikut_dilampirkan(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", ['ke' => ['pic@pelanggan.co.id']])
            ->assertOk();

        // Tanpa lampiran, pelanggan nerima email kosong — itu lebih buruk
        // daripada nggak nerima apa-apa.
        Mail::assertSent(
            SertifikatKalibrasi::class,
            fn (SertifikatKalibrasi $mail): bool => count($mail->attachments()) === 1,
        );
    }

    public function test_sertifikat_yang_pdf_nya_belum_jadi_nggak_bisa_dikirim(): void
    {
        Mail::fake();
        $this->sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", ['ke' => ['pic@pelanggan.co.id']])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_email_ngawur_ditolak(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", ['ke' => ['bukan-email']])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_teknisi_nggak_boleh_kirim(): void
    {
        Mail::fake();

        $this->actingAs($this->teknisi)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", ['ke' => ['pic@pelanggan.co.id']])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_sertifikat_organisasi_lain_nggak_bisa_dikirim(): void
    {
        Mail::fake();

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->postJson("/api/certificates/{$this->sertifikat->id}/kirim-email", ['ke' => ['x@y.com']])
            ->assertNotFound();

        Mail::assertNothingSent();
    }
}
