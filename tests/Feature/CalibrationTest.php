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

class CalibrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $viewer;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $pelanggan = Customer::factory()->create(['nama' => 'PT Maju Jaya']);
        $kategori = EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang']);

        $this->alat = Equipment::factory()->create([
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    /**
     * Kasus acuan yang sama kayak di GumCalculatorTest: error +0.02, U ≈ 0.0129.
     *
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],
            ],
            ...$ubah,
        ];
    }

    private function buatSesi(?User $sebagai = null, array $ubah = []): CalibrationSession
    {
        $this->actingAs($sebagai ?? $this->teknisi)
            ->postJson('/api/calibrations', $this->payload($ubah))
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_teknisi_bikin_sesi_dapet_bentuk_yang_dijanjiin_ke_mobile(): void
    {
        $response = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            ->assertJsonPath('data.hasil.keputusan', 'PASS')
            ->assertJsonPath('data.equipment.nama_alat', 'Jangka Sorong Mitutoyo')
            ->assertJsonPath('data.teknisi.id', $this->teknisi->id)
            ->assertJsonPath('data.catatan_revisi', null)
            ->assertJsonPath('data.certificate_id', null)
            ->assertJsonStructure(['data' => ['id', 'equipment', 'teknisi', 'tanggal_kalibrasi', 'status', 'hasil' => [
                'rata_rata', 'error', 'ketidakpastian_gabungan', 'faktor_cakupan_k', 'ketidakpastian_diperluas', 'keputusan',
            ]]]);

        // Angkanya sama persis kayak yang dihitung tangan di GumCalculatorTest.
        $this->assertEqualsWithDelta(0.02, $response->json('data.hasil.error'), 1e-6);
        $this->assertEqualsWithDelta(0.0129161, $response->json('data.hasil.ketidakpastian_diperluas'), 1e-6);
    }

    public function test_tiap_pembacaan_disimpen_satu_baris_sendiri_bukan_ditumpuk_jadi_json(): void
    {
        $sesi = $this->buatSesi();

        // Type A butuh tiap pengulangan buat hitung standar deviasi — kalau
        // ditumpuk jadi satu baris, angkanya nggak bisa diaudit satu-satu.
        $this->assertSame(3, $sesi->rawMeasurements()->count());
        $this->assertSame(1, $sesi->uncertaintyCalculations()->count());
        $this->assertSame([1, 2, 3], $sesi->rawMeasurements()->orderBy('pembacaan_ke')->pluck('pembacaan_ke')->all());
    }

    public function test_input_manual_langsung_terverifikasi_hasil_ocr_belum(): void
    {
        $manual = $this->buatSesi();
        $ocr = $this->buatSesi(ubah: ['input_method' => 'ocr']);

        $this->assertTrue($manual->rawMeasurements()->get()->every(fn ($m) => $m->is_verified));
        // Kamera mempercepat input, bukan menggantikan verifikasi manusia.
        $this->assertTrue($ocr->rawMeasurements()->get()->every(fn ($m) => ! $m->is_verified));
    }

    public function test_viewer_nggak_boleh_bikin_sesi(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/calibrations', $this->payload())
            ->assertForbidden();
    }

    public function test_standar_acuan_wajib_diisi(): void
    {
        $payload = $this->payload();
        unset($payload['standard_id']);

        // Tanpa standar, Type B kehilangan komponen terbesarnya dan U jadi
        // kekecilan — alat yang harusnya FAIL malah lulus.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('standard_id');
    }

    public function test_standar_yang_sertifikatnya_kadaluarsa_ditolak(): void
    {
        $kadaluarsa = Standard::factory()->kadaluarsa()->create();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['standard_id' => $kadaluarsa->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('standard_id');
    }

    public function test_alat_tanpa_toleransi_ditolak_karena_pass_fail_nggak_bisa_diputusin(): void
    {
        $this->alat->update(['toleransi' => null]);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_id');
    }

    public function test_satu_pembacaan_doang_ditolak_karena_type_a_butuh_sebaran(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload([
                'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02]]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements.0.pembacaan');
    }

    public function test_satu_titik_fail_bikin_seluruh_sesi_fail(): void
    {
        $response = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],   // PASS
                ['titik_ukur' => 100.0, 'satuan' => 'mm', 'pembacaan' => [100.9, 100.8, 100.85]], // jauh lewat toleransi
            ],
        ]));

        $response->assertCreated()->assertJsonPath('data.hasil.keputusan', 'FAIL');

        // `hasil` nunjukin titik penentu — yang paling mepet/lewat batas, bukan titik pertama.
        $this->assertEqualsWithDelta(100.0, $response->json('data.hasil.rata_rata'), 1.0);
        $this->assertSame(['PASS', 'FAIL'], array_column($response->json('data.titik'), 'keputusan'));
    }

    public function test_teknisi_cuma_bisa_lihat_sesi_miliknya_sendiri(): void
    {
        $sesiOrangLain = $this->buatSesi(sebagai: $this->admin);
        $sesiSendiri = $this->buatSesi(sebagai: $this->teknisi);

        $response = $this->actingAs($this->teknisi)->getJson('/api/calibrations');

        $response->assertOk();
        $this->assertSame([$sesiSendiri->id], array_column($response->json('data'), 'id'));

        // Daftarnya udah disaring, tapi tanpa penjagaan di `show` teknisi masih
        // bisa ngintip punya orang lain cuma dengan nebak ID.
        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesiOrangLain->id}")
            ->assertNotFound();
    }

    public function test_teknisi_nggak_bisa_ngakalin_penyaringan_pakai_query_param(): void
    {
        $sesiOrangLain = $this->buatSesi(sebagai: $this->admin);
        $this->buatSesi(sebagai: $this->teknisi);

        // Kalau `mine` dipercaya apa adanya, `mine=false` jadi pintu belakang.
        $response = $this->actingAs($this->teknisi)->getJson('/api/calibrations?mine=false');

        $response->assertOk();
        $this->assertNotContains($sesiOrangLain->id, array_column($response->json('data'), 'id'));
    }

    public function test_admin_lihat_semua_sesi(): void
    {
        $this->buatSesi(sebagai: $this->teknisi);
        $this->buatSesi(sebagai: $this->admin);

        $this->actingAs($this->admin)
            ->getJson('/api/calibrations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_nyetujuin_sesi(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI);

        $this->assertSame($this->admin->id, $sesi->fresh()->reviewed_by);
    }

    public function test_teknisi_nggak_boleh_nyetujuin_sesinya_sendiri(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertForbidden();
    }

    public function test_sesi_fail_tetap_boleh_disetujui(): void
    {
        $sesi = $this->buatSesi(ubah: [
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.9, 50.8, 50.85]]],
        ]);

        $this->assertSame('FAIL', $sesi->keputusan);

        // FAIL itu temuan yang sah — sertifikatnya tetap terbit, isinya "tidak
        // laik pakai". Jangan diblokir di backend.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI)
            ->assertJsonPath('data.hasil.keputusan', 'FAIL');
    }

    public function test_nolak_sesi_wajib_pakai_catatan_revisi(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('catatan_revisi');
    }

    public function test_sesi_yang_ditolak_bisa_direvisi_teknisi_lalu_disubmit_ulang(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", [
                'catatan_revisi' => 'Titik ukur 50mm cuma 3 pembacaan, tambahin jadi 5.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_PERLU_REVISI)
            ->assertJsonPath('data.catatan_revisi', 'Titik ukur 50mm cuma 3 pembacaan, tambahin jadi 5.');

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03, 50.02, 50.01]],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            // Catatan teguran lama dibersihin — udah dibenerin, jangan dipajang terus.
            ->assertJsonPath('data.catatan_revisi', null);

        // Hitungan lama dibuang, bukan ditumpuk.
        $this->assertSame(5, $sesi->fresh()->rawMeasurements()->count());
        $this->assertSame(1, $sesi->fresh()->uncertaintyCalculations()->count());
    }

    public function test_sesi_yang_udah_disetujui_nggak_bisa_diubah_lagi(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        // Sertifikatnya udah terbit — angka yang dipegang pelanggan nggak boleh
        // berubah diam-diam. Kalau salah, terbitin revisi, bukan edit sesi.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload())
            ->assertStatus(422);
    }

    public function test_sesi_bisa_disimpen_sebagai_draft_dulu(): void
    {
        $sesi = $this->buatSesi(ubah: ['status' => CalibrationSession::STATUS_DRAFT]);

        $this->assertSame(CalibrationSession::STATUS_DRAFT, $sesi->status);
        $this->assertNull($sesi->submitted_at);

        // Draft belum masuk antrean approval, jadi admin nggak bisa nyetujuin.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->getJson('/api/dashboard')
            ->assertJsonPath('data.kalibrasi_draft', 1)
            ->assertJsonPath('data.menunggu_approval', 0);
    }

    public function test_sesi_dari_pt_lain_nggak_kelihatan(): void
    {
        $sesi = $this->buatSesi(sebagai: $this->admin);

        $ptLain = Organization::factory()->create();
        $adminPtLain = User::factory()->admin()->create(['organization_id' => $ptLain->id]);

        $this->actingAs($adminPtLain)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertNotFound();
    }
}
