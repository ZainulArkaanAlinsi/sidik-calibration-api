<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Approve nge-dispatch GenerateCertificate; di test queue-nya sync, jadi
        // job jalan inline & bikin PDF. Fake disk biar nggak nyampah ke storage asli.
        Storage::fake('local');

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

    /**
     * Alur pH tanpa Excel: mobile ngirim suhu larutan, backend yang ngitung
     * titik ukurnya dari kurva suhu sertifikat standar.
     *
     * Angka yang diuji bukan karangan — 4.009244572 itu `titik_ukur` yang
     * BENERAN tercetak di sertifikat 012-CAL-524, dan suhunya (22,2 / 22,2 /
     * 22,1 / 22,2 / 22,2 -> rata-rata 22,18 °C) diambil dari sheet
     * `PERHITUNGAN` blok After Adjustment.
     */
    public function test_titik_ukur_dihitung_dari_suhu_larutan_kalau_nggak_dikirim(): void
    {
        $buffer = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 4',
            // Koefisien dari sheet `Nilai koefisien Sensitifitas`.
            'koef_suhu_a' => 0.00003,
            'koef_suhu_b' => -0.0023,
            'koef_suhu_c' => 4.0455,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'standard_id' => $buffer->id,
            'measurements' => [[
                // `titik_ukur` sengaja NGGAK dikirim — ini intinya.
                'satuan' => 'pH',
                'pembacaan' => [4, 4, 4, 4, 4],
                'suhu_larutan' => [22.2, 22.2, 22.1, 22.2, 22.2],
            ]],
        ]))->assertCreated();

        $titik = CalibrationSession::latest('id')->firstOrFail()->uncertaintyCalculations()->firstOrFail();

        // Toleransi 1e-8: kolomnya decimal(20,8), jadi digit ke-9 kepotong.
        $this->assertEqualsWithDelta(4.009244572, (float) $titik->titik_ukur, 1e-8);
    }

    /** Suhu tiap pengulangan kesimpen sendiri-sendiri, kayak di Excel. */
    public function test_suhu_larutan_disimpen_per_pembacaan(): void
    {
        $buffer = Standard::factory()->create([
            'koef_suhu_a' => 0.00003, 'koef_suhu_b' => -0.0023, 'koef_suhu_c' => 4.0455,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'standard_id' => $buffer->id,
            'measurements' => [[
                'satuan' => 'pH',
                'pembacaan' => [4, 4, 4],
                'suhu_larutan' => [22.2, 22.1, 22.2],
            ]],
        ]))->assertCreated();

        $suhu = CalibrationSession::latest('id')->firstOrFail()
            ->rawMeasurements()->where('tahap', 'sesudah_adjustment')
            ->orderBy('pembacaan_ke')->pluck('suhu_larutan')->map(fn ($s) => (float) $s)->all();

        $this->assertSame([22.2, 22.1, 22.2], $suhu);
    }

    /**
     * Titik ukur kosong TANPA jalan buat ngitungnya harus ditolak — nebak
     * angkanya bikin error salah tanpa ada yang tau.
     */
    public function test_titik_ukur_kosong_ditolak_kalau_standarnya_nggak_punya_kurva_suhu(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'measurements' => [[
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'suhu_larutan' => [22.2, 22.1, 22.2],
            ]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements.0.titik_ukur');
    }

    public function test_jumlah_suhu_larutan_harus_sama_dengan_jumlah_pembacaan(): void
    {
        $buffer = Standard::factory()->create([
            'koef_suhu_a' => 0.00003, 'koef_suhu_b' => -0.0023, 'koef_suhu_c' => 4.0455,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'standard_id' => $buffer->id,
            'measurements' => [[
                'satuan' => 'pH',
                'pembacaan' => [4, 4, 4],
                // Cuma 2 suhu buat 3 pembacaan — rata-ratanya bakal salah.
                'suhu_larutan' => [22.2, 22.1],
            ]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements.0.suhu_larutan');
    }

    /** Jalur lama tetap jalan: kalau `titik_ukur` dikirim, itu yang dipakai. */
    public function test_titik_ukur_yang_dikirim_tetap_menang(): void
    {
        $sesi = $this->buatSesi();

        $this->assertEqualsWithDelta(
            50.0,
            (float) $sesi->uncertaintyCalculations()->firstOrFail()->titik_ukur,
            1e-8,
        );
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

    /**
     * Skenario offline: teknisi submit di lapangan, sinyal putus pas nunggu
     * respons (padahal request-nya udah sampe), mobile retry begitu koneksi
     * balik. `client_request_id` yang sama harus balikin sesi yang sama,
     * bukan bikin dobel.
     */
    public function test_retry_dengan_client_request_id_sama_cuma_bikin_1_sesi(): void
    {
        $requestId = (string) Str::uuid();

        $pertama = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['client_request_id' => $requestId]))
            ->assertCreated();

        $kedua = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['client_request_id' => $requestId]))
            ->assertOk(); // 200, bukan 201 — ini replay, bukan resource baru.

        $this->assertSame($pertama->json('data.id'), $kedua->json('data.id'));
        $this->assertSame(1, CalibrationSession::count());
    }

    public function test_client_request_id_beda_tetap_bikin_2_sesi_terpisah(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['client_request_id' => (string) Str::uuid()]))
            ->assertCreated();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['client_request_id' => (string) Str::uuid()]))
            ->assertCreated();

        $this->assertSame(2, CalibrationSession::count());
    }

    /** Mobile lama yang belum kirim `client_request_id` harus tetap jalan seperti biasa. */
    public function test_tanpa_client_request_id_tetap_jalan_seperti_biasa(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();

        // Tanpa key, backend nggak punya cara bedain retry dari submission
        // baru yang emang disengaja — makanya field ini opsional tapi
        // direkomendasikan buat mobile.
        $this->assertSame(2, CalibrationSession::count());
    }

    /**
     * Kasus pH: tiap titik (buffer 4/7/10) pakai standar sendiri lewat
     * `measurements.*.standard_id`, dan U95%-nya harus CMC dari
     * CalibrationCapability, bukan dihitung ulang dari pembacaan sesi ini.
     */
    public function test_titik_ukur_bisa_pakai_standar_beda_beda_dan_kepake_cmc(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter',
            'parameter' => 'pH',
            'range_min' => 4,
            'range_max' => 4,
            'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.02343221,
            'satuan_ketidakpastian' => 'pH',
            'faktor_cakupan' => 2,
        ]);

        $phMeter = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $bufferDefault = Standard::factory()->create(['nama' => 'pH Buffer Solution 7']);
        $buffer4 = Standard::factory()->create(['nama' => 'pH Buffer Solution 4']);

        $response = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload([
            'equipment_id' => $phMeter->id,
            'standard_id' => $bufferDefault->id,
            'measurements' => [
                // Titik ini nunjuk standar_id sendiri (buffer 4) dan punya
                // CalibrationCapability — U95%-nya harus CMC, bukan gabungan
                // Type A+B, walaupun pembacaannya nyebar jauh.
                [
                    'titik_ukur' => 4.009244572, 'satuan' => 'pH',
                    'pembacaan' => [4.04, 4.04, 4.04, 5.0, 4.04],
                    'standard_id' => $buffer4->id,
                ],
            ],
        ]));

        $response->assertCreated();

        $titik = $response->json('data.titik.0');
        $this->assertEqualsWithDelta(0.02343221, $titik['ketidakpastian_diperluas'], 1e-9);
        $this->assertSame($buffer4->id, $titik['standar_acuan']['id']);

        // Kolom DB-nya juga kesimpan, bukan cuma yang keluar di response.
        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->assertSame($buffer4->id, $sesi->uncertaintyCalculations()->first()->standard_id);
    }

    /** Titik yang nggak nentuin standard_id sendiri ikut standar default sesi. */
    public function test_titik_tanpa_standard_id_ikut_standar_default_sesi(): void
    {
        $sesi = $this->buatSesi();

        $this->assertSame(
            $this->standar->id,
            $sesi->uncertaintyCalculations()->first()->standard_id,
        );
    }
}
