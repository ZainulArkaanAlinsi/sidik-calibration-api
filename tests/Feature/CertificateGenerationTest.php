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

    /**
     * Sejak 29 Juli 2026 approve nerbitin sertifikat LANGSUNG, bukan lewat
     * antrean.
     *
     * Dulu ini `Queue::assertPushed`. Masalahnya kelihatan waktu dipakai
     * beneran: tanpa `queue:work` yang jalan, approve-nya sukses tapi
     * sertifikatnya nggak pernah terbit — dan dari layar admin itu kelihatan
     * "lagi diproses" selamanya, tanpa error di mana pun.
     */
    public function test_approve_langsung_nerbitin_sertifikat_tanpa_antrean(): void
    {
        Storage::fake('local');
        $sesi = $this->buatSesiMenungguApproval();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk()
            // `certificate_id` udah keisi di respons approve-nya sendiri —
            // mobile nggak perlu polling nunggu sertifikatnya jadi.
            ->assertJsonPath('data.sertifikat.status', Certificate::STATUS_TERBIT);

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertNotNull($sertifikat->pdf_path);
    }

    /**
     * **Bug asli:** `qr_payload` dibikin pakai `url()`, yang ngambil host dari
     * request yang lagi jalan. Sertifikat diterbitin lewat request admin dari
     * LAN, jadi alamat sementara mesin dev ikut kecetak PERMANEN ke QR —
     * ketemu `http://192.168.1.8:8000/verify/...` di sertifikat yang udah
     * terbit. IP-nya pindah, QR-nya mati; dari luar kantor emang nggak pernah
     * bisa dibuka.
     *
     * QR nempel di dokumen terkendali yang salinannya dipegang pelanggan, jadi
     * alamatnya harus satu & stabil — diambil dari `APP_URL`, bukan dari
     * request yang kebetulan lagi jalan.
     */
    public function test_qr_pakai_app_url_bukan_host_request(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://sertifikat.ptsidik.co.id']);

        $sesi = $this->buatSesiMenungguApproval();

        // Request-nya SENGAJA dari host lain — persis kejadian di lapangan:
        // admin approve dari HP/laptop lewat IP LAN.
        $this->actingAs($this->admin)
            ->withServerVariables(['HTTP_HOST' => '192.168.1.8:8000'])
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();

        $this->assertStringStartsWith(
            'https://sertifikat.ptsidik.co.id/verify/',
            $sertifikat->qr_payload,
            'QR harus ngikut APP_URL biar bisa dibuka siapa pun, kapan pun',
        );
        $this->assertStringNotContainsString(
            '192.168.1.8',
            $sertifikat->qr_payload,
            'alamat sementara mesin dev nggak boleh nempel di dokumen resmi',
        );
        $this->assertStringEndsWith($sertifikat->qr_token, $sertifikat->qr_payload);
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
