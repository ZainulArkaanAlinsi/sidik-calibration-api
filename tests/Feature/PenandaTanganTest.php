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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Penanda tangan sertifikat (Fase 2 §3c).
 *
 * Keputusan pemilik produk: "Manajer Teknis" itu ATRIBUT (`department`) di akun
 * admin, BUKAN role keempat. Jadi penanda tangan = admin yang meng-approve, dan
 * jabatannya diambil dari `department`-nya.
 */
class PenandaTanganTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Alex Misramto',
            'department' => 'Technical Manager',
        ]);
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function sesiDisetujui(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        return $sesi->fresh();
    }

    public function test_penanda_tangan_diambil_dari_admin_yang_approve(): void
    {
        $sesi = $this->sesiDisetujui();

        $this->actingAs($this->admin)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.penanda_tangan.nama', 'Alex Misramto')
            // Jabatan dari `department` — inti keputusan "atribut, bukan role".
            ->assertJsonPath('data.penanda_tangan.jabatan', 'Technical Manager')
            // Belum unggah TTD: tetap null, sertifikat tetap terbit.
            ->assertJsonPath('data.penanda_tangan.ttd_url', null);
    }

    public function test_sesi_belum_disetujui_belum_punya_penanda_tangan(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.penanda_tangan', null);
    }

    public function test_unggah_ttd_sendiri_lalu_kepakai_di_sesi(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/me/ttd', ['ttd' => UploadedFile::fake()->image('ttd.png')])
            ->assertOk()
            ->assertJsonPath('data.ttd_url', fn ($v) => $v !== null);

        $this->assertNotNull($this->admin->fresh()->ttd_path);
        Storage::disk('public')->assertExists($this->admin->fresh()->ttd_path);

        $sesi = $this->sesiDisetujui();

        $this->actingAs($this->admin)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.penanda_tangan.ttd_url', fn ($v) => $v !== null);
    }

    public function test_ttd_lama_dihapus_waktu_diganti(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/me/ttd', ['ttd' => UploadedFile::fake()->image('lama.png')])->assertOk();
        $lama = $this->admin->fresh()->ttd_path;

        $this->actingAs($this->admin)
            ->postJson('/api/me/ttd', ['ttd' => UploadedFile::fake()->image('baru.png')])->assertOk();

        // Kalau nggak dibersihin, tiap ganti TTD ninggalin sampah di disk selamanya.
        Storage::disk('public')->assertMissing($lama);
        Storage::disk('public')->assertExists($this->admin->fresh()->ttd_path);
    }

    public function test_svg_ditolak_karena_nggak_kebaca_dompdf(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/me/ttd', ['ttd' => UploadedFile::fake()->create('ttd.svg', 10, 'image/svg+xml')])
            ->assertStatus(422);
    }

    /**
     * TTD itu milik orangnya. Kalau admin bisa ngunggahin TTD orang lain,
     * sertifikat terakreditasi bisa ditandatangani atas nama orang yang nggak
     * pernah menyetujuinya — makanya endpoint-nya `/me/ttd`, bukan
     * `/users/{user}/ttd`.
     */
    public function test_nggak_ada_jalan_ngunggahin_ttd_orang_lain(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/users/{$this->teknisi->id}/ttd", ['ttd' => UploadedFile::fake()->image('x.png')])
            ->assertNotFound();
    }
}
