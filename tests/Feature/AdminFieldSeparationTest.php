<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
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
 * Pemisahan field administratif dari layar teknisi (spesifikasi poin 1 & 12A).
 */
class AdminFieldSeparationTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
            ...$ubah,
        ];
    }

    public function test_field_administratif_dari_teknisi_dibuang_bukan_disimpen(): void
    {
        // Mobile versi lama masih ngirim field ini — request-nya tetap sukses
        // (biar teknisi di lapangan nggak keblokir), tapi nilainya nggak masuk.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload([
                'nomor_order' => 'DIISI-TEKNISI',
                'suhu_ketidakpastian' => 9.9,
            ]))
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertNull($sesi->nomor_order);
        $this->assertNull($sesi->suhu_ketidakpastian);
    }

    /**
     * `calibration_method_id` PINDAH jadi hak teknisi, 26 Agt 2026.
     *
     * Pindahnya persis sealasan dengan `thermohygro_standard_id` di bawah ini:
     * metode kalibrasi itu FAKTA LAPANGAN. Teknisi yang tahu alatnya
     * dikalibrasi dengan metode mana, dan sejak alat baru bisa didaftarkan
     * langsung dari lembar kerja, nggak ada admin yang bisa mengisinya lebih
     * dulu — alat yang lahir hari itu juga selalu lahir tanpa metode.
     *
     * Selama kolomnya tertutup, kegagalannya DIAM: kiriman teknisi yang
     * membawanya dibuang `prepareForValidation()` — sengaja dibuang bukan
     * ditolak, supaya HP versi lama nggak gagal submit — jadi teknisi memilih
     * metodenya, menekan kirim, dan kolomnya sampai di admin dalam keadaan
     * kosong tanpa satu pun pesan.
     */
    public function test_metode_kalibrasi_sekarang_hak_teknisi(): void
    {
        $metode = CalibrationMethod::factory()->create();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload([
                'calibration_method_id' => $metode->id,
            ]))
            ->assertCreated();

        $this->assertSame(
            $metode->id,
            CalibrationSession::latest('id')->firstOrFail()->calibration_method_id,
            'Metode yang dipilih teknisi dibuang diam-diam.',
        );
    }

    /**
     * `thermohygro_standard_id` PINDAH jadi hak teknisi, 29 Juli 2026.
     *
     * Awalnya dia ikut dibuang di test atas ini, ngikut spesifikasi poin 1.
     * Di praktiknya keliru: unit thermohygro mana yang kepakai itu fakta
     * lapangan — teknisi yang bawa TH-2 ke lokasi pelanggan atau makai TH-4 di
     * lab, dan admin nggak punya cara tau selain nanya. Selama field ini
     * administratif, kolom "6. Thermohygro used" keisi di HP tapi nyampe server
     * jadi null, tanpa pesan error apa pun.
     */
    public function test_thermohygro_dari_teknisi_disimpa_n_karena_itu_fakta_lapangan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload([
                'thermohygro_standard_id' => $this->standar->id,
            ]))
            ->assertCreated();

        $this->assertSame(
            $this->standar->id,
            CalibrationSession::latest('id')->firstOrFail()->thermohygro_standard_id,
        );
    }

    public function test_admin_ngisi_field_administratif_lewat_endpoint_sendiri(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload())->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $metode = CalibrationMethod::factory()->create(['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '6']);

        $this->actingAs($this->admin)
            ->patchJson("/api/calibrations/{$sesi->id}/admin", [
                'nomor_order' => '2405.13.A',
                'calibration_method_id' => $metode->id,
                'thermohygro_standard_id' => $this->standar->id,
                'suhu_ruang' => 21.0,
                'suhu_ketidakpastian' => 1.7,
            ])
            ->assertOk()
            ->assertJsonPath('data.nomor_order', '2405.13.A')
            ->assertJsonPath('data.metode_kalibrasi.kode', 'SIDIK-IK-CAL-0506_Rev.6')
            ->assertJsonPath('data.suhu_ketidakpastian', 1.7);
    }

    public function test_revisi_dari_teknisi_nggak_ngosongin_isian_admin(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload())->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => '2405.13.A'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Tambahin pembacaan.'])
            ->assertOk();

        // Teknisi ngerjain ulang — payload-nya nggak pernah bawa nomor order,
        // jadi yang udah diisi admin harus tetap utuh.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([
                'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03, 50.02, 50.01]]],
            ]))
            ->assertOk();

        $this->assertSame('2405.13.A', $sesi->fresh()->nomor_order);
    }

    public function test_teknisi_nggak_boleh_manggil_endpoint_admin(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload())->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->teknisi)
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => 'X'])
            ->assertForbidden();
    }

    public function test_sesi_yang_udah_disetujui_dikunci_dari_perubahan_administratif(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload())->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => 'TELAT'])
            ->assertStatus(422);
    }

    public function test_metode_kalibrasi_revisi_baru_itu_baris_baru_bukan_edit_kode_lama(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/calibration-methods', ['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '6'])
            ->assertCreated()
            ->assertJsonPath('data.kode_lengkap', 'SIDIK-IK-CAL-0506_Rev.6');

        // Kode+revisi yang sama ditolak…
        $this->actingAs($this->admin)
            ->postJson('/api/calibration-methods', ['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '6'])
            ->assertStatus(422);

        // …tapi revisi berikutnya boleh, sebagai baris sendiri.
        $this->actingAs($this->admin)
            ->postJson('/api/calibration-methods', ['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '7'])
            ->assertCreated();
    }

    public function test_teknisi_boleh_baca_metode_kalibrasi_tapi_nggak_boleh_ngubah(): void
    {
        CalibrationMethod::factory()->create();

        $this->actingAs($this->teknisi)->getJson('/api/calibration-methods')->assertOk();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibration-methods', ['kode' => 'IK-BARU'])
            ->assertForbidden();
    }
}
