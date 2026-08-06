<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\FolderFile;
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

    public function test_lembar_kerja_yang_dikirim_masuk_folder_pt(): void
    {
        $sesi = $this->buatSesi();

        // Sertifikat itu KESIMPULAN. Yang ditanya waktu audit justru pembacaan
        // mentahnya — siapa yang ngukur, pakai standar apa, suhu berapa. Tanpa
        // lembar kerjanya ikut diarsip, riwayat kalibrasi satu alat nggak
        // lengkap di Folder Manager.
        $berkas = FolderFile::where('calibration_session_id', $sesi->id)->first();

        $this->assertNotNull($berkas, 'Lembar kerja yang dikirim nggak nyampe folder PT.');
        $this->assertSame(FolderFile::SUMBER_LEMBAR_KERJA, $berkas->sumber);
        $this->assertNull($berkas->path, 'Yang ditaut mestinya record-nya, bukan berkas hasil render.');

        // Foldernya: PT Maju Jaya / <tahun kalibrasi>
        $folderTahun = $berkas->folder;
        $this->assertSame(
            $sesi->tanggal_kalibrasi->format('Y'),
            $folderTahun->nama,
        );
        $this->assertSame('PT Maju Jaya', $folderTahun->parent->nama);
    }

    public function test_draft_ngga_k_diarsip_ke_folder_pt(): void
    {
        $sesi = $this->buatSesi(ubah: ['status' => CalibrationSession::STATUS_DRAFT]);

        // Draft masih diisi. Folder PT bukan tempat naruh pekerjaan setengah
        // jalan — kalau draft ikut masuk, folder pelanggan penuh sesi yang
        // belum tentu jadi.
        $this->assertNull($sesi->submitted_at);
        $this->assertDatabaseMissing('folder_files', [
            'calibration_session_id' => $sesi->id,
        ]);
    }

    public function test_kirim_ulang_sesudah_revisi_nggak_bikin_entri_kembar(): void
    {
        $sesi = $this->buatSesi();
        $this->assertSame(1, FolderFile::where('calibration_session_id', $sesi->id)->count());

        // Sesi yang masih `menunggu_approval` NGGAK bisa di-PUT — cuma draft
        // dan `perlu_revisi` yang boleh. Jadi jalur revisinya mesti lewat
        // penolakan admin dulu, dan itu justru skenario yang mau diuji.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", [
                'catatan_revisi' => 'Suhu awalnya di luar rentang IK, ulangi.',
            ])
            ->assertOk();

        // Lembar yang ditolak lalu dikirim ulang manggil penautan lagi. Satu
        // sesi mesti tetap satu entri — kalau nambah tiap revisi, folder PT
        // keisi entri yang isinya sama dan nggak ada yang tau mana yang
        // berlaku.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload())
            ->assertOk();

        $this->assertSame(1, FolderFile::where('calibration_session_id', $sesi->id)->count());
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

    public function test_lembar_kerja_tanpa_standar_acuan_tetap_bisa_dikirim_tapi_nggak_dihitung(): void
    {
        $payload = $this->payload();
        unset($payload['standard_id']);

        // Lembar kerja boleh dikirim setengah jadi — teknisi di lapangan nggak
        // boleh keblokir cuma gara-gara satu kolom belum keisi.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Tapi tanpa standar, Type B kehilangan komponen terbesarnya — jadi
        // titiknya disimpen mentah & NGGAK dihitung, bukan dihitung asal-asalan.
        $this->assertSame(3, $sesi->rawMeasurements()->count());
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count());
        $this->assertNull($sesi->keputusan);

        // Penjagaannya pindah ke penerbitan: sertifikat nggak bisa terbit.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('validasi.boleh_terbit', false);
    }

    public function test_standar_yang_sertifikatnya_kadaluarsa_ditolak(): void
    {
        $kadaluarsa = Standard::factory()->kadaluarsa()->create();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['standard_id' => $kadaluarsa->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('standard_id');
    }

    public function test_alat_tanpa_toleransi_nggak_dihitung_dan_sertifikatnya_ketahan(): void
    {
        // Toleransi itu data master yang cuma admin yang bisa isi — teknisi di
        // lapangan nggak bisa benerin, jadi jangan diblokir di sana.
        $this->alat->update(['toleransi' => null]);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count());

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422);

        $this->assertContains(
            'toleransi_kosong',
            array_column($respons->json('validasi.temuan'), 'kode'),
        );
    }

    public function test_titik_dengan_satu_pembacaan_disimpen_tapi_nggak_dihitung(): void
    {
        // Type A butuh sebaran — satu angka nggak punya standar deviasi. Datanya
        // tetap kesimpen (bukti kerja lapangan), cuma nggak jadi hasil resmi.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload([
                'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02]]],
            ]))
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertSame(1, $sesi->rawMeasurements()->count());
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count());
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
                // CalibrationCapability, jadi jalur CMC yang kepakai.
                [
                    'titik_ukur' => 4.00, 'satuan' => 'pH',
                    'pembacaan' => [4.01, 4.01, 4.01],
                    'standard_id' => $buffer4->id,
                ],
            ],
        ]));

        $response->assertCreated();

        $titik = $response->json('data.titik.0');

        // Yang diuji: standar per-titik kepakai & jalur CMC yang jalan.
        // Pembacaannya sengaja rapat, jadi Type A mendekati nol dan U95 jatuh
        // ke lantai CMC — U95 ikut sebaran sejak 29 Juli 2026 (lihat
        // GumCalculatorTest::test_sebaran_pembacaan_yang_lebar_bikin_u95_ikut_membesar).
        $this->assertSame($buffer4->id, $titik['standar_acuan']['id']);
        $this->assertSame('cmc_kemampuan_kalibrasi', $titik['type_b_components'][0]['sumber']);
        $this->assertEqualsWithDelta(0.02343221, $titik['ketidakpastian_diperluas'], 1e-9);

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
