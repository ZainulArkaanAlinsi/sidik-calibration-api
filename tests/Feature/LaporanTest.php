<?php

namespace Tests\Feature;

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
 * Laporan kalibrasi + ekspor (Fase 2 §5).
 *
 * Yang paling penting diuji: cakupan datanya. Laporan itu barang yang dibaca
 * manajemen & asesor — kalau teknisi bisa narik sesi orang lain lewat sini,
 * penyaringan di /calibrations jadi percuma.
 */
class LaporanTest extends TestCase
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

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->teknisiLain = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create(['nama' => 'PT Contoh'])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function buatSesi(User $teknisi, string $tanggal = '-1 day'): CalibrationSession
    {
        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->modify($tanggal)->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_admin_lihat_semua_sesi_di_laporan(): void
    {
        $this->buatSesi($this->teknisi);
        $this->buatSesi($this->teknisiLain);

        $this->actingAs($this->admin)->getJson('/api/laporan/kalibrasi')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.ringkasan.total', 2)
            ->assertJsonPath('data.0.pelanggan', 'PT Contoh');
    }

    public function test_teknisi_cuma_dapat_sesinya_sendiri(): void
    {
        $this->buatSesi($this->teknisi);
        $this->buatSesi($this->teknisiLain);

        $this->actingAs($this->teknisi)->getJson('/api/laporan/kalibrasi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Ringkasan juga harus ikut disaring — kalau nggak, teknisi bisa
            // ngintip berapa banyak kerjaan orang lain dari angka footer.
            ->assertJsonPath('meta.ringkasan.total', 1);
    }

    public function test_filter_tanggal_dan_pelanggan(): void
    {
        $this->buatSesi($this->teknisi, '-1 day');
        $this->buatSesi($this->teknisi, '-30 days');

        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi?dari='.now()->subDays(7)->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi?pelanggan_id='.$this->alat->customer_id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi?pelanggan_id=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sampai_sebelum_dari_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi?dari=2026-07-10&sampai=2026-07-01')
            ->assertStatus(422);
    }

    public function test_export_csv(): void
    {
        $this->buatSesi($this->teknisi);

        $response = $this->actingAs($this->admin)
            ->get('/api/laporan/kalibrasi/export?format=csv')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $isi = $response->streamedContent();
        $this->assertStringContainsString('Nomor Sesi', $isi);
        $this->assertStringContainsString('PT Contoh', $isi);
        // BOM UTF-8 biar Excel di Windows nggak ngacak karakter non-ASCII.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
    }

    public function test_export_pdf(): void
    {
        $this->buatSesi($this->teknisi);

        $response = $this->actingAs($this->admin)
            ->get('/api/laporan/kalibrasi/export?format=pdf')
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_format_ekspor_selain_pdf_csv_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi/export?format=xlsx')
            ->assertStatus(422);
    }

    public function test_laporan_terisolasi_antar_organisasi(): void
    {
        $this->buatSesi($this->teknisi);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)->getJson('/api/laporan/kalibrasi')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_butuh_login(): void
    {
        $this->getJson('/api/laporan/kalibrasi')->assertUnauthorized();
    }
}
