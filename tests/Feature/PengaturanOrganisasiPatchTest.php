<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BUG-2 uji E2E-QA 18 Nov 2026: `PUT /api/organization` dengan `settings`
 * SEBAGIAN mengganti seluruh kolom JSON — penanda tangan, catatan KAN, QR, dan
 * kode dokumen hilang tanpa galat, lalu sertifikat berikutnya tercetak dengan
 * nama & departemen admin yang menekan approve.
 *
 * Kontrak sesudah perbaikan: `settings` adalah PATCH PER KUNCI. Kunci yang tidak
 * dikirim tidak pernah disentuh, `null` pada satu kunci membuang kunci itu
 * (kembali ke bawaan), dan kunci yang tidak dikenal ditolak 422.
 */
class PengaturanOrganisasiPatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $awal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awal = [
            'prefix_sertifikat' => 'CAL',
            'masa_berlaku_sertifikat_bulan' => 12,
            'faktor_cakupan_default' => 2,
            'tampilkan_qr_di_pdf' => true,
            'tampilkan_keputusan_di_pdf' => false,
            'penandatangan_nama' => 'Alex Misramto',
            'penandatangan_jabatan' => 'Technical Manager',
            'kode_dokumen_form' => 'SIDIK-FM-CAL-2403_Rev. 0',
            'catatan_ketidakpastian' => 'Ketidakpastian yang diperluas dinyatakan pada tingkat kepercayaan 95%.',
            'catatan_penggandaan' => 'Lampiran ini tidak boleh digandakan kecuali seluruhnya.',
            'reminder_hari_sebelum' => 7,
            'desimal_sertifikat' => 3,
            'ttd_geser_x_mm' => 4.5,
            'ttd_geser_y_mm' => -2.0,
            'ttd_lebar_mm' => 42.0,
        ];

        $this->org = Organization::factory()->create(['settings' => $this->awal]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
    }

    private function settingsTersimpan(): array
    {
        return $this->org->fresh()->settings;
    }

    public function test_settings_sebagian_tidak_menghapus_kunci_yang_tidak_dikirim(): void
    {
        // Persis reproduksi QA: dua kunci saja yang dikirim.
        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['masa_berlaku_sertifikat_bulan' => 24, 'prefix_sertifikat' => 'E2E'],
        ])->assertOk()
            ->assertJsonPath('data.settings.masa_berlaku_sertifikat_bulan', 24)
            ->assertJsonPath('data.settings.penandatangan_nama', 'Alex Misramto');

        $this->assertEquals(
            ['masa_berlaku_sertifikat_bulan' => 24, 'prefix_sertifikat' => 'E2E'] + $this->awal,
            $this->settingsTersimpan(),
            'hanya dua kunci yang berubah; penanda tangan, catatan KAN, QR, dsb. harus utuh',
        );
    }

    public function test_pembaruan_bidang_lain_tanpa_settings_tidak_menyentuh_settings(): void
    {
        $this->actingAs($this->admin)->putJson('/api/organization', ['telepon' => '022-1234'])->assertOk();

        $this->assertEquals($this->awal, $this->settingsTersimpan());
    }

    public function test_null_pada_satu_kunci_membuang_hanya_kunci_itu(): void
    {
        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['desimal_sertifikat' => null],
        ])->assertOk();

        $sisa = $this->awal;
        unset($sisa['desimal_sertifikat']);

        $this->assertEquals($sisa, $this->settingsTersimpan());
    }

    public function test_settings_null_utuh_tidak_menghapus_apa_pun(): void
    {
        $this->actingAs($this->admin)->putJson('/api/organization', ['settings' => null])->assertOk();
        $this->assertEquals($this->awal, $this->settingsTersimpan());

        $this->actingAs($this->admin)->putJson('/api/organization', ['settings' => []])->assertOk();
        $this->assertEquals($this->awal, $this->settingsTersimpan());
    }

    public function test_kunci_tak_dikenal_ditolak_dan_tidak_ada_yang_tertulis(): void
    {
        $this->actingAs($this->admin)->putJson('/api/organization', [
            // Satu kunci sah + satu ngawur: semuanya ditolak, bukan separuh.
            'settings' => ['masa_berlaku_sertifikat_bulan' => 24, 'penandatangan_nma' => 'typo'],
        ])->assertStatus(422)->assertJsonValidationErrors(['settings']);

        $this->assertEquals($this->awal, $this->settingsTersimpan());
    }

    public function test_kop_path_tidak_bisa_diubah_lewat_api(): void
    {
        // Jalur berkas internal, bukan pengaturan yang boleh diketik klien.
        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['kop_path' => '../../.env'],
        ])->assertStatus(422)->assertJsonValidationErrors(['settings']);

        $this->assertEquals($this->awal, $this->settingsTersimpan());
    }

    public function test_nilai_kunci_yang_dikenal_tetap_divalidasi(): void
    {
        $ngawur = [
            ['masa_berlaku_sertifikat_bulan' => 0],
            ['masa_berlaku_sertifikat_bulan' => 999],
            ['masa_berlaku_sertifikat_bulan' => 'abc'],
            ['desimal_sertifikat' => 9],
            ['reminder_hari_sebelum' => 0],
            ['tampilkan_qr_di_pdf' => 'mungkin'],
            ['penandatangan_nama' => ['Alex']],
            ['penandatangan_nama' => str_repeat('A', 256)],
            ['catatan_ketidakpastian' => str_repeat('c', 1001)],
            ['ttd_lebar_mm' => 'lebar'],
        ];

        foreach ($ngawur as $settings) {
            $this->actingAs($this->admin)
                ->putJson('/api/organization', ['settings' => $settings])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['settings.'.array_key_first($settings)]);
        }

        $this->assertEquals($this->awal, $this->settingsTersimpan());
    }

    public function test_saklar_qr_dan_keputusan_tersimpan_sebagai_boolean_sungguhan(): void
    {
        // `DataTampilanSertifikat` membaca `tampilkan_qr_di_pdf !== true`:
        // angka 1 atau teks "1" yang lolos validasi lalu tersimpan apa adanya
        // akan mematikan QR tanpa error.
        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['tampilkan_qr_di_pdf' => 0, 'tampilkan_keputusan_di_pdf' => 1],
        ])->assertOk();

        $this->assertSame(false, $this->settingsTersimpan()['tampilkan_qr_di_pdf']);
        $this->assertSame(true, $this->settingsTersimpan()['tampilkan_keputusan_di_pdf']);

        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['tampilkan_qr_di_pdf' => '1'],
        ])->assertOk();

        $this->assertSame(true, $this->settingsTersimpan()['tampilkan_qr_di_pdf']);
    }

    public function test_organisasi_lain_tidak_ikut_berubah(): void
    {
        $lain = Organization::factory()->create(['settings' => ['penandatangan_nama' => 'Orang Lain']]);

        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['penandatangan_nama' => 'Alex Baru'],
        ])->assertOk();

        $this->assertSame('Orang Lain', $lain->fresh()->settings['penandatangan_nama']);
        $this->assertSame('Alex Baru', $this->settingsTersimpan()['penandatangan_nama']);
    }

    /**
     * Akibat yang dulu terlihat di PDF: sertifikat sesudah PUT parsial tercetak
     * dengan nama & departemen admin yang menyetujui ("Admin Master Data E2E /
     * Uji E2E"), bukan penanda tangan yang ditetapkan lab.
     */
    public function test_sertifikat_berikutnya_tetap_mencetak_penanda_tangan_lab(): void
    {
        Storage::fake('local');

        $teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Dedi Rahmat',
            'kode_teknisi' => 'DR',
        ]);
        $pelanggan = Customer::factory()->create(['organization_id' => $this->org->id]);
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
                'kode' => 'ph',
            ])->id,
            'nama_alat' => 'pH Meter',
            'range_min' => 0, 'range_max' => 14,
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);
        $standar = Standard::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'pH Buffer Solution 4',
            'satuan_ketidakpastian' => 'pH',
        ]);
        $ruangan = Room::factory()->create(['organization_id' => $this->org->id]);

        // Satu PUT parsial dari aplikasi, sebelum sertifikat terbit.
        $this->actingAs($this->admin)->putJson('/api/organization', [
            'settings' => ['masa_berlaku_sertifikat_bulan' => 24, 'prefix_sertifikat' => 'E2E'],
        ])->assertOk();

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'room_id' => $ruangan->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'tanggal_terima' => now()->subDays(3)->toDateString(),
            'suhu_ruang' => 21.0,
            'kelembaban' => 51.95,
            'measurements' => [
                ['titik_ukur' => 4.01, 'satuan' => 'pH', 'pembacaan' => [4.00, 4.00, 4.01]],
                ['titik_ukur' => 6.99, 'satuan' => 'pH', 'pembacaan' => [7.00, 7.00, 7.01]],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $metode = CalibrationMethod::factory()->create([
            'organization_id' => $this->org->id,
            'kode' => 'SIDIK-IK-CAL-0506',
            'revisi' => '6',
        ]);

        $this->actingAs($this->admin)->patchJson("/api/calibrations/{$sesi->id}/admin", [
            'nomor_order' => '2405.13.A',
            'calibration_method_id' => $metode->id,
            'suhu_ketidakpastian' => 1.7,
            'kelembaban_ketidakpastian' => 5.7,
        ])->assertOk();

        $this->denganOrderNumber($sesi->id)->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji()])
            ->assertOk();

        $footer = $sesi->fresh()->certificate()->firstOrFail()->snapshot['footer'];

        $this->assertSame('Alex Misramto', $footer['penandatangan']);
        $this->assertSame('Technical Manager', $footer['jabatan']);
        $this->assertSame('SIDIK-FM-CAL-2403_Rev. 0', $footer['kode_dokumen']);
        $this->assertNotSame($this->admin->name, $footer['penandatangan']);
    }
}
