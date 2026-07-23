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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Export sertifikat ke Excel & QR Code (spesifikasi poin 10 & 13).
 */
class CertificateExportTest extends TestCase
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
            'customer_id' => Customer::factory()->create(['nama' => 'PT Contoh Jaya'])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    private function sertifikatTerbit(): Certificate
    {
        Storage::fake('local');

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI, 'reviewed_by' => $this->admin->id]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->certificate()->firstOrFail();
    }

    public function test_sertifikat_bisa_diexport_ke_excel(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $respons = $this->actingAs($this->admin)
            ->get("/api/certificates/{$sertifikat->id}/excel")
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $isi = $respons->streamedContent();

        // XLSX itu file ZIP — dua huruf pertamanya `PK`. Cukup buat mastiin
        // yang kekirim beneran workbook, bukan halaman error.
        $this->assertStringStartsWith('PK', $isi);
        $this->assertGreaterThan(1000, strlen($isi));
    }

    public function test_rekap_banyak_sertifikat_bisa_diexport_sekaligus(): void
    {
        $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->get('/api/certificates/export/excel?bulan='.now()->format('Y-m'))
            ->assertOk();

        // Bulan yang nggak ada sertifikatnya jangan ngasih file kosong —
        // itu bikin orang ngira datanya ilang.
        $this->actingAs($this->admin)
            ->get('/api/certificates/export/excel?bulan='.now()->subYears(3)->format('Y-m'))
            ->assertNotFound();
    }

    public function test_rekap_cuma_buat_admin(): void
    {
        $this->sertifikatTerbit();

        $this->actingAs($this->teknisi)
            ->get('/api/certificates/export/excel')
            ->assertForbidden();
    }

    public function test_qr_sertifikat_kekirim_sebagai_png(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $respons = $this->actingAs($this->admin)
            ->get("/api/certificates/{$sertifikat->id}/qr")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        // Tanda tangan berkas PNG.
        $this->assertStringStartsWith("\x89PNG", $respons->getContent());
    }

    public function test_hasil_scan_qr_bisa_langsung_unduh_pdf_maupun_excel_tanpa_login(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $token = $sertifikat->qr_token;

        $this->get("/verify/{$token}/download")->assertOk();
        $this->get("/verify/{$token}/download?format=xlsx")->assertOk();

        // Token ngawur nggak boleh bocorin apa pun.
        $this->get('/verify/tokenpalsu/download')->assertNotFound();
    }

    public function test_teknisi_nggak_bisa_export_sertifikat_punya_orang_lain(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $teknisiLain = User::factory()->create();

        $this->actingAs($teknisiLain)
            ->get("/api/certificates/{$sertifikat->id}/excel")
            ->assertNotFound();

        $this->actingAs($teknisiLain)
            ->get("/api/certificates/{$sertifikat->id}/qr")
            ->assertNotFound();
    }

    public function test_detail_sertifikat_bawa_snapshot_dan_hasil_validasi(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.header.owner', 'PT Contoh Jaya')
            ->assertJsonPath('data.validasi.boleh_terbit', true);
    }
}
