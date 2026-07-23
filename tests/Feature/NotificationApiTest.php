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
 * Halaman notifikasi & fungsinya sebagai pengingat (spesifikasi poin 4 & 6).
 */
class NotificationApiTest extends TestCase
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
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    private function submitSesi(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_admin_dikabarin_waktu_teknisi_nyubmit_sesi(): void
    {
        $this->submitSesi();

        $this->actingAs($this->admin)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kategori', 'sesi_menunggu_approval')
            ->assertJsonPath('data.0.dibaca', false)
            ->assertJsonPath('data.0.tautan.tipe', 'calibration');
    }

    public function test_draft_nggak_ngabarin_siapa_siapa(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'status' => CalibrationSession::STATUS_DRAFT,
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_teknisi_dikabarin_waktu_sesinya_disetujui(): void
    {
        $sesi = $this->submitSesi();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $kategori = array_column(
            $this->actingAs($this->teknisi)->getJson('/api/notifications')->json('data'),
            'kategori',
        );

        // Dua-duanya: sesi disetujui, lalu sertifikatnya terbit.
        $this->assertContains('sesi_disetujui', $kategori);
        $this->assertContains('sertifikat_terbit', $kategori);
    }

    public function test_teknisi_dikabarin_lengkap_dengan_catatan_waktu_sesinya_ditolak(): void
    {
        $sesi = $this->submitSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", [
                'catatan_revisi' => 'Titik 50mm tambahin jadi 5 pembacaan.',
            ])->assertOk();

        $this->actingAs($this->teknisi)
            ->getJson('/api/notifications?belum_dibaca=1')
            ->assertOk()
            ->assertJsonPath('data.0.kategori', 'sesi_perlu_revisi')
            ->assertJsonFragment(['isi' => $sesi->fresh()->nomor_sesi.' dikembalikan admin. Titik 50mm tambahin jadi 5 pembacaan.']);
    }

    public function test_penghitung_belum_dibaca_dan_tandai_sudah_dibaca(): void
    {
        $this->submitSesi();

        $this->actingAs($this->admin)
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.belum_dibaca', 1);

        $id = $this->actingAs($this->admin)->getJson('/api/notifications')->json('data.0.id');

        $this->actingAs($this->admin)
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.dibaca', true);

        $this->actingAs($this->admin)
            ->getJson('/api/notifications/unread-count')
            ->assertJsonPath('data.belum_dibaca', 0);
    }

    public function test_tandai_semua_sudah_dibaca(): void
    {
        $this->submitSesi();
        $this->submitSesi();

        $this->actingAs($this->admin)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('meta.ditandai', 2)
            ->assertJsonPath('meta.belum_dibaca', 0);
    }

    public function test_notifikasi_orang_lain_nggak_bisa_disentuh(): void
    {
        $this->submitSesi();
        $id = $this->actingAs($this->admin)->getJson('/api/notifications')->json('data.0.id');

        // Teknisi nggak punya baris ini — tebak-tebakan UUID nggak nemu apa-apa.
        $this->actingAs($this->teknisi)->postJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($this->teknisi)->deleteJson("/api/notifications/{$id}")->assertNotFound();
    }

    public function test_pengingat_jatuh_tempo_masuk_ke_halaman_notifikasi_admin(): void
    {
        Equipment::factory()->overdue()->create([
            'customer_id' => $this->alat->customer_id,
            'equipment_category_id' => $this->alat->equipment_category_id,
        ]);

        $this->artisan('alat:cek-jatuh-tempo')->assertSuccessful();

        $this->actingAs($this->admin)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.kategori', 'jatuh_tempo')
            ->assertJsonPath('data.0.warna', 'danger')
            ->assertJsonPath('data.0.tautan.tipe', 'equipments');
    }
}
