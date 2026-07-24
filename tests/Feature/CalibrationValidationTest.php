<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Validasi & hitung ulang sebelum sertifikat terbit (spesifikasi poin 11).
 */
class CalibrationValidationTest extends TestCase
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

    private function buatSesi(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_sesi_yang_sehat_lolos_pemeriksaan(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.boleh_terbit', true)
            ->assertJsonPath('data.ringkasan.error', 0)
            ->assertJsonPath('data.ringkasan.peringatan', 0);
    }

    public function test_angka_yang_diutak_atik_langsung_ketahuan_waktu_dihitung_ulang(): void
    {
        Queue::fake();
        $sesi = $this->buatSesi();

        // Ketidakpastian dikecilin langsung di DB — persis skenario yang bikin
        // alat FAIL kelihatan PASS di sertifikat.
        $sesi->uncertaintyCalculations()->update(['ketidakpastian_diperluas' => 0.0001]);

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('butuh_konfirmasi', true)
            ->assertJsonPath('validasi.valid', false);

        $this->assertContains(
            'hitung_ulang_beda',
            array_column($respons->json('validasi.temuan'), 'kode'),
        );

        // Ketahan beneran: statusnya nggak pindah & sertifikat nggak diantre.
        // (Sinyal broadcast realtime boleh jalan — yang dijaga cuma jangan sampai
        // sertifikatnya kegenerate.)
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        Queue::assertNotPushed(GenerateCertificate::class);
    }

    public function test_admin_tetap_bisa_lanjut_kalau_peringatannya_disadari(): void
    {
        $sesi = $this->buatSesi();
        $sesi->uncertaintyCalculations()->update(['ketidakpastian_diperluas' => 0.0001]);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI);
    }

    public function test_koreksi_yang_nggak_konsisten_nahan_penerbitan_tanpa_bisa_dilewatin(): void
    {
        $sesi = $this->buatSesi();

        // Correction wajib kebalikan error — ini rumus mati, bukan selisih
        // pembulatan. Nggak boleh bisa di-abaikan.
        $sesi->uncertaintyCalculations()->update(['koreksi' => 999]);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('validasi.boleh_terbit', false);
    }

    public function test_keputusan_sesi_yang_nggak_cocok_sama_titiknya_ditolak(): void
    {
        $sesi = $this->buatSesi();

        // Satu titik FAIL harusnya bikin seluruh sesi FAIL.
        $sesi->uncertaintyCalculations()->update(['keputusan' => 'FAIL']);

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422);

        $this->assertContains(
            'keputusan_sesi_salah',
            array_column($respons->json('validasi.temuan'), 'kode'),
        );
    }

    public function test_kolom_sertifikat_yang_kosong_cuma_jadi_info_dan_nggak_nahan_approve(): void
    {
        $sesi = $this->buatSesi();

        $respons = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk();

        $info = array_filter(
            $respons->json('data.temuan'),
            fn (array $t): bool => $t['tingkat'] === 'info',
        );

        // Order Number & Calibration Method emang belum diisi admin di sini.
        $this->assertNotEmpty($info);
        $this->assertTrue($respons->json('data.boleh_terbit'));

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();
    }

    public function test_hasil_pemeriksaan_ikut_kesimpen_di_sertifikat(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $validasi = $sesi->fresh()->certificate()->firstOrFail()->validasi;

        $this->assertTrue($validasi['boleh_terbit']);
        $this->assertSame(0, $validasi['ringkasan']['error']);
    }
}
