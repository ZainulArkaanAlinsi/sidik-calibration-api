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

/**
 * Endpoint mobile buat ngambil hasil dari alur approve → PDF: daftar sertifikat,
 * unduh PDF-nya, dan retry yang gagal generate.
 */
class CertificateApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $teknisiLain;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        // Disk privat tempat PDF sertifikat disimpen — dipalsuin biar test nggak
        // nyentuh filesystem beneran.
        Storage::fake('local');

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->teknisiLain = User::factory()->create();
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    /** Bikin satu sertifikat terbit, atas nama teknisi tertentu. */
    private function terbitkanSertifikat(User $teknisi): Certificate
    {
        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->certificate()->firstOrFail();
    }

    public function test_teknisi_bisa_unduh_sertifikat_miliknya(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);

        $this->actingAs($this->teknisi)
            ->get("/api/certificates/{$sertifikat->id}/download")
            ->assertOk()
            // Slash di nomor (CAL/2026/07/0001) harus jadi strip di nama file.
            ->assertDownload('Sertifikat-'.str_replace('/', '-', $sertifikat->nomor).'.pdf');
    }

    public function test_teknisi_nggak_bisa_unduh_sertifikat_teknisi_lain(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisiLain);

        $this->actingAs($this->teknisi)
            ->getJson("/api/certificates/{$sertifikat->id}/download")
            ->assertNotFound();
    }

    public function test_admin_boleh_unduh_sertifikat_teknisi_manapun(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);

        $this->actingAs($this->admin)
            ->get("/api/certificates/{$sertifikat->id}/download")
            ->assertOk();
    }

    public function test_unduh_sertifikat_yang_belum_terbit_404(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}/download")
            ->assertNotFound();
    }

    public function test_sertifikat_organisasi_lain_nggak_bisa_diunduh(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->getJson("/api/certificates/{$sertifikat->id}/download")
            ->assertNotFound();
    }

    public function test_daftar_sertifikat_teknisi_cuma_miliknya(): void
    {
        $milikSaya = $this->terbitkanSertifikat($this->teknisi);
        $this->terbitkanSertifikat($this->teknisiLain);

        $this->actingAs($this->teknisi)
            ->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $milikSaya->id)
            ->assertJsonPath('data.0.pdf_url', route('certificates.download', $milikSaya));
    }

    public function test_admin_lihat_semua_sertifikat_di_organisasinya(): void
    {
        $this->terbitkanSertifikat($this->teknisi);
        $this->terbitkanSertifikat($this->teknisiLain);

        $this->actingAs($this->admin)
            ->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_daftar_sertifikat_terisolasi_antar_organisasi(): void
    {
        $this->terbitkanSertifikat($this->teknisi);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_retry_sertifikat_gagal_nge_dispatch_ulang(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', Certificate::STATUS_MENUNGGU_GENERATE);

        Queue::assertPushed(
            GenerateCertificate::class,
            fn ($job) => $job->calibrationSessionId === $sertifikat->calibration_session_id,
        );
    }

    public function test_retry_sertifikat_yang_udah_terbit_ditolak(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);

        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_teknisi_nggak_boleh_retry(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $this->actingAs($this->teknisi)
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertForbidden();
    }
}
