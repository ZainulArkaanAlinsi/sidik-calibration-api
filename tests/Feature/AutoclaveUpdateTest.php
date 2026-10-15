<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SesiMenungguApproval;
use App\Services\PemisahanWewenang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * `PUT /calibrations/autoclave/{id}` — draft Autoklaf yang dibuka lagi lalu
 * disimpan ulang memperbarui sesi yang SAMA, bukan melahirkan sesi kedua.
 *
 * Sebelum rute ini ada, satu-satunya jalan simpan Autoklaf `POST`, dan `POST`
 * selalu membuat sesi baru: tiap kali teknisi menyimpan draft yang sama, daftar
 * Draf-nya bertambah satu.
 *
 * Fixture-nya fixture `AutoclaveStoreTest` (alat `Autoklaf`, data ukur yang
 * sama), supaya angka yang dibandingkan lahir dari jalur yang sudah diadu.
 */
class AutoclaveUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $customer = Customer::factory()->create(['organization_id' => $this->org->id]);
        $category = EquipmentCategory::factory()->create(['organization_id' => $this->org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * Sama persis dengan `AutoclaveStoreTest::payload()`.
     *
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4,
            'suhu_akhir' => 24.5,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ],
            ...$ubah,
        ];
    }

    /** Payload dengan satu disk yang angkanya beda — hasil olahnya ikut bergeser. */
    private function payloadDiubah(array $ubah = []): array
    {
        $payload = $this->payload($ubah);
        $payload['suhu']['disk'][0] = [121.10, 121.40, 121.05, 121.45, 121.20];

        return $payload;
    }

    private function draftTeknisi(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload([
                'status' => 'draft',
                'client_request_id' => '22222222-2222-4222-8222-222222222222',
            ]))
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    private function kirimanTeknisi(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    public function test_simpan_ulang_draft_memperbarui_sesi_yang_sama(): void
    {
        $draft = $this->draftTeknisi();
        $u95Lama = $draft->hasil_autoclave['suhu']['u95'];

        $respons = $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.id', $draft->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('meta.belum_dihitung', []);

        $this->assertSame(1, CalibrationSession::count(), 'PUT tidak boleh melahirkan sesi kedua.');

        $sesi = $draft->fresh();
        $this->assertSame(CalibrationSession::STATUS_DRAFT, $sesi->status);
        $this->assertNull($sesi->submitted_at);
        $this->assertSame($draft->nomor_sesi, $sesi->nomor_sesi);
        $this->assertSame($draft->teknisi_id, $sesi->teknisi_id);
        $this->assertSame($draft->organization_id, $sesi->organization_id);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $sesi->client_request_id);

        // Snapshot dihitung ulang dari kiriman baru, lembar mentahnya ikut.
        $this->assertSame([121.10, 121.40, 121.05, 121.45, 121.20], $sesi->hasil_autoclave['lembar']['suhu']['disk'][0]);
        $this->assertNotEqualsWithDelta($u95Lama, $sesi->hasil_autoclave['suhu']['u95'], 1e-12);
        $this->assertEqualsWithDelta(
            $sesi->hasil_autoclave['suhu']['u95'],
            $respons->json('data.hasil_autoclave.suhu.u95'),
            1e-12,
        );
    }

    public function test_hasil_put_identik_dengan_post_untuk_data_yang_sama(): void
    {
        // Preview dan simpan memakai fungsi yang sama — POST dan PUT juga.
        $draft = $this->draftTeknisi();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertOk();

        $viaPost = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadDiubah(['status' => 'draft']))
            ->assertCreated()
            ->json('data.id');

        $this->assertEquals(
            CalibrationSession::findOrFail($viaPost)->hasil_autoclave,
            $draft->fresh()->hasil_autoclave,
        );
    }

    public function test_put_tanpa_status_draft_mengirim_sesi(): void
    {
        Notification::fake();
        $draft = $this->draftTeknisi();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $this->payload([
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $sesi = $draft->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
        $this->assertNotNull($sesi->submitted_at);
        $this->assertSame(1, CalibrationSession::count());

        // Efek samping kirim sama dengan POST: admin dikabari.
        Notification::assertSentTo($this->admin, SesiMenungguApproval::class);
    }

    public function test_teknisi_lain_dapat_404(): void
    {
        $draft = $this->draftTeknisi();
        $teknisiLain = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        $this->actingAs($teknisiLain, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertNotFound();

        $this->assertEquals($draft->hasil_autoclave, $draft->fresh()->hasil_autoclave);
    }

    public function test_admin_lab_lain_dapat_404(): void
    {
        $draft = $this->draftTeknisi();
        $labLain = Organization::factory()->create();
        $adminLabLain = User::factory()->admin()->create(['organization_id' => $labLain->id]);
        $alatLabLain = Equipment::factory()->create([
            'organization_id' => $labLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $labLain->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $labLain->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $this->actingAs($adminLabLain, 'sanctum')
            ->putJson(
                "/api/calibrations/autoclave/{$draft->id}",
                $this->payloadDiubah(['status' => 'draft', 'equipment_id' => $alatLabLain->id]),
            )
            ->assertNotFound();

        $this->assertEquals($draft->hasil_autoclave, $draft->fresh()->hasil_autoclave);
    }

    public function test_sesi_disetujui_ditolak_422(): void
    {
        $sesi = $this->kirimanTeknisi();
        // Lewat query builder: yang diuji penjaga PUT-nya, bukan alur approve.
        CalibrationSession::query()->whereKey($sesi->id)->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah([
                'alasan_koreksi' => 'Angka disk 1 salah salin dari rekaman logger.',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sesi yang udah disetujui nggak bisa diubah — sertifikatnya udah terbit.');

        $this->assertEquals($sesi->hasil_autoclave, $sesi->fresh()->hasil_autoclave);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    public function test_teknisi_tidak_bisa_mengubah_sesi_yang_menunggu_approval(): void
    {
        $sesi = $this->kirimanTeknisi();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah())
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Sesi yang lagi nunggu approval nggak bisa diubah teknisi. Minta admin yang ngedit, atau minta lembarnya dibalikin dulu.',
            );

        $this->assertEquals($sesi->hasil_autoclave, $sesi->fresh()->hasil_autoclave);
    }

    public function test_sesi_bukan_autoklaf_ditolak_422(): void
    {
        $sesiLain = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $this->alat->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'keputusan' => null,
        ]);
        $this->assertFalse($sesiLain->adalahAutoclave());

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesiLain->id}", $this->payload(['status' => 'draft']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sesi ini bukan sesi Autoklaf — simpan lewat PUT /calibrations/{id}, bukan jalur Autoklaf.');

        $this->assertNull($sesiLain->fresh()->hasil_autoclave);
        $this->assertSame(1, CalibrationSession::count());
    }

    public function test_admin_boleh_memperbarui_draft_teknisi(): void
    {
        $draft = $this->draftTeknisi();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.id', $draft->id);

        $sesi = $draft->fresh();
        $this->assertSame($this->teknisi->id, $sesi->teknisi_id, 'Pengisi lembar tetap teknisinya, bukan admin.');
        $this->assertSame([121.10, 121.40, 121.05, 121.45, 121.20], $sesi->hasil_autoclave['lembar']['suhu']['disk'][0]);
        $this->assertSame(1, CalibrationSession::count());
    }

    public function test_admin_mengoreksi_lembar_terkirim_wajib_alasan_dan_tercatat(): void
    {
        $sesi = $this->kirimanTeknisi();

        // Tanpa alasan → ditolak, lembar teknisi tetap utuh.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah())
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan_koreksi');
        $this->assertEquals($sesi->hasil_autoclave, $sesi->fresh()->hasil_autoclave);

        // Dengan alasan → tersimpan, dan jejaknya memuat nilai lama & baru.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah([
                'alasan_koreksi' => 'Angka disk 1 salah salin dari rekaman logger.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $jejak = AuditLog::query()
            ->where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->where('note', 'like', AuditLog::CATATAN_KOREKSI_PEMBACAAN.'%')
            ->sole();
        $this->assertSame($this->admin->id, (int) $jejak->changed_by);
        $this->assertSame([121.27, 121.26, 121.26, 121.26, 121.28], $jejak->old_data['lembar']['suhu']['disk'][0]);
        $this->assertSame([121.10, 121.40, 121.05, 121.45, 121.20], $jejak->new_data['lembar']['suhu']['disk'][0]);

        // Admin yang mengoreksi tidak boleh menyetujui sesi yang sama.
        $periksa = app(PemisahanWewenang::class)->periksaPersetujuan($sesi->fresh(), $this->admin);
        $this->assertTrue($periksa['memblokir']);
        $this->assertContains(
            PemisahanWewenang::KODE_PENYETUJU_MENGOREKSI,
            array_column($periksa['temuan'], 'kode'),
        );
    }

    public function test_admin_menyimpan_ulang_lembar_tanpa_ubah_angka_tidak_diminta_alasan(): void
    {
        $sesi = $this->kirimanTeknisi();

        // Angka sama persis (lewat bolak-balik JSON di DB) — bukan koreksi.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payload(['alat_serial_number' => 'SN-BARU-01']))
            ->assertOk();

        $this->assertSame('SN-BARU-01', $sesi->fresh()->alat_serial_number);
        $this->assertFalse(
            AuditLog::query()
                ->where('entity_id', $sesi->id)
                ->where('note', 'like', AuditLog::CATATAN_KOREKSI_PEMBACAAN.'%')
                ->exists(),
        );
    }

    /** Sesi terkirim yang sudah dikembalikan admin, nomor order-nya sudah diisi admin. */
    private function lembarDikembalikan(): CalibrationSession
    {
        $sesi = $this->kirimanTeknisi();
        CalibrationSession::query()->whereKey($sesi->id)->update([
            'status' => CalibrationSession::STATUS_PERLU_REVISI,
            'nomor_order' => 'ORD-ADMIN-01',
            'catatan_revisi' => 'Disk 1 kolom 3 nggak kebaca.',
        ]);

        return $sesi->fresh();
    }

    public function test_teknisi_tidak_bisa_menimpa_nomor_order_admin_lewat_put(): void
    {
        $sesi = $this->lembarDikembalikan();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah([
                'nomor_order' => 'ORD-TEKNISI-99',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $this->assertSame('ORD-ADMIN-01', $sesi->fresh()->nomor_order);
    }

    public function test_teknisi_tidak_bisa_mengisi_field_admin_lewat_post(): void
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload([
                'nomor_order' => 'ORD-TEKNISI-99',
                'suhu_ketidakpastian' => 0.5,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(CalibrationSession::findOrFail($id)->nomor_order);
    }

    public function test_field_admin_bernilai_angka_divalidasi(): void
    {
        $sesi = $this->kirimanTeknisi();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payload([
                'suhu_ketidakpastian' => 'bukan-angka',
                'kelembaban_ketidakpastian' => -1,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['suhu_ketidakpastian', 'kelembaban_ketidakpastian']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload(['suhu_ketidakpastian' => 'bukan-angka']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('suhu_ketidakpastian');
    }

    public function test_teknisi_boleh_simpan_draf_revisi_lembar_yang_dikembalikan(): void
    {
        // Sama kayak `update()`: revisi lembar yang dikembalikan boleh disimpan
        // dulu sebagai draft — antrean draft offline mengirimnya begitu.
        $dikembalikan = $this->lembarDikembalikan();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$dikembalikan->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DRAFT);

        $sesi = $dikembalikan->fresh();
        $this->assertSame(CalibrationSession::STATUS_DRAFT, $sesi->status);
        $this->assertNull($sesi->submitted_at);
        $this->assertSame([121.10, 121.40, 121.05, 121.45, 121.20], $sesi->hasil_autoclave['lembar']['suhu']['disk'][0]);
    }

    /** Lembar terkirim yang dikembalikan admin (keadaan lama; Tolak dicabut K-50-01), dengan kolom yang ditandai. */
    private function ditolakAdmin(string $catatan, array $kolom): CalibrationSession
    {
        $sesi = $this->kirimanTeknisi();

        return $this->sesiPerluRevisiLama($sesi, $catatan, $kolom, $this->admin);
    }

    public function test_simpan_draf_revisi_mempertahankan_catatan_dan_kolom_revisi_sampai_dikirim(): void
    {
        // Keputusan pemilik proyek 15 Okt 2026, sama dengan `PUT /calibrations/{id}`.
        // Dulu tiap PUT yang berhasil mengosongkan keduanya, termasuk Simpan
        // Draf pertama atas lembar `perlu_revisi`.
        $catatan = 'Disk 1 kolom 3 nggak kebaca, ulangi bacaannya.';
        $kolom = ['suhu', 'alat_serial_number'];
        $sesi = $this->ditolakAdmin($catatan, $kolom);

        // perlu_revisi → draft.
        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DRAFT)
            ->assertJsonPath('data.catatan_revisi', $catatan)
            ->assertJsonPath('data.revisi_field', $kolom);

        // draft → draft: simpan draf berikutnya juga membiarkannya.
        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payload(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DRAFT);

        $draft = $sesi->fresh();
        $this->assertSame($catatan, $draft->catatan_revisi);
        $this->assertSame($kolom, $draft->revisi_field);
        $this->assertNull($draft->submitted_at);

        // Dikirim → teguran sudah dijawab, dua-duanya dibuang.
        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah())
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            ->assertJsonPath('data.catatan_revisi', null)
            ->assertJsonPath('data.revisi_field', []);

        $terkirim = $sesi->fresh();
        $this->assertNull($terkirim->catatan_revisi);
        $this->assertNull($terkirim->revisi_field);
    }

    public function test_kirim_ulang_langsung_dari_perlu_revisi_mengosongkan_catatan_dan_kolom_revisi(): void
    {
        $sesi = $this->ditolakAdmin('Tekanan standar pembacaan ke-5 janggal.', ['tekanan']);

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah())
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            ->assertJsonPath('data.catatan_revisi', null)
            ->assertJsonPath('data.revisi_field', []);

        $segar = $sesi->fresh();
        $this->assertNull($segar->catatan_revisi);
        $this->assertNull($segar->revisi_field);
    }

    public function test_sesi_yang_menunggu_approval_tidak_bisa_disimpan_balik_jadi_draft(): void
    {
        $antre = $this->kirimanTeknisi();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$antre->id}", $this->payload(['status' => 'draft']))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Lembar ini sedang menunggu approval, jadi nggak bisa disimpan balik jadi draft. '
                    .'Simpan tanpa `status: draft` — Master Data mengoreksi lembarnya langsung, tidak lagi mengembalikannya ke teknisi.',
            );

        $sesi = $antre->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
        $this->assertTrue($antre->submitted_at->equalTo($sesi->submitted_at));
    }

    public function test_sunting_admin_mempertahankan_waktu_kirim_tapi_kirim_ulang_revisi_memperbaruinya(): void
    {
        $sesi = $this->kirimanTeknisi();
        $dikirim = $sesi->submitted_at;
        $this->assertNotNull($dikirim);

        $this->travel(3)->hours();

        // Admin menyunting kepala lembar sesi yang sedang antre — bukan
        // pengiriman baru.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payload(['alat_serial_number' => 'SN-02']))
            ->assertOk();
        $this->assertTrue($dikirim->equalTo($sesi->fresh()->submitted_at));

        // Kirim ulang sesudah dikembalikan = pengiriman baru, sama kayak `update()`.
        CalibrationSession::query()->whereKey($sesi->id)->update(['status' => CalibrationSession::STATUS_PERLU_REVISI]);
        $this->travel(1)->day();
        $kirimUlang = now()->startOfSecond();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadDiubah())
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $baru = $sesi->fresh()->submitted_at;
        $this->assertTrue($baru->greaterThan($dikirim));
        $this->assertTrue($baru->greaterThanOrEqualTo($kirimUlang));
    }

    public function test_draft_tanpa_set_point_tersimpan_lalu_terkirim_identik_dengan_post_langsung(): void
    {
        // Draft boleh belum ada set point (request: nullable kalau draft). Dulu
        // POST/PUT draft seperti itu 500 di `AutoclaveInputBuilder`.
        $tanpaSetPoint = $this->payload(['status' => 'draft']);
        unset($tanpaSetPoint['set_point']);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $tanpaSetPoint)
            ->assertCreated()
            ->assertJsonPath('data.hasil_autoclave.set_point', null)
            ->json('data.id');

        // Set point kosong TIDAK dikarang — angka lainnya tetap dihitung.
        $draft = CalibrationSession::findOrFail($id);
        $this->assertNull($draft->hasil_autoclave['set_point']);
        $this->assertArrayNotHasKey('set_point', $draft->hasil_autoclave['lembar']);
        $this->assertEqualsWithDelta(0.4419439029528431, $draft->hasil_autoclave['suhu']['u95'], 1e-9);

        $tanpaSetPointDiubah = $this->payloadDiubah(['status' => 'draft']);
        unset($tanpaSetPointDiubah['set_point']);

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$id}", $tanpaSetPointDiubah)
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DRAFT)
            ->assertJsonPath('data.hasil_autoclave.set_point', null);

        // Kirim tanpa set point tetap ditolak, di PUT sama kayak di POST.
        $kirimTanpaSetPoint = $this->payloadDiubah();
        unset($kirimTanpaSetPoint['set_point']);

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$id}", $kirimTanpaSetPoint)
            ->assertStatus(422)
            ->assertJsonValidationErrors('set_point');
        $this->assertSame(CalibrationSession::STATUS_DRAFT, CalibrationSession::findOrFail($id)->status);

        // Lengkapi set point lalu kirim → hasilnya identik dengan POST langsung.
        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$id}", $this->payloadDiubah())
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $langsung = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadDiubah())
            ->assertCreated()
            ->json('data.id');

        $terkirim = CalibrationSession::findOrFail($id)->hasil_autoclave;
        // `assertEquals`: kolom JSON menyimpan 121.0 sebagai `121` (perilaku
        // lama POST, bukan bagian perubahan ini).
        $this->assertEquals(121.0, $terkirim['set_point']);
        $this->assertEquals(CalibrationSession::findOrFail($langsung)->hasil_autoclave, $terkirim);
    }

    public function test_kiriman_tanpa_bacaan_tidak_menimpa_lembar_yang_berisi(): void
    {
        $draft = $this->draftTeknisi();
        $kosong = $this->payload(['status' => 'draft']);
        unset($kosong['suhu'], $kosong['tekanan']);

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $kosong)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lembar');

        $this->assertEquals($draft->hasil_autoclave, $draft->fresh()->hasil_autoclave);

        // Blok yang dikirim tapi barisnya kosong semua juga dihitung kosong.
        $barisKosong = $this->payload(['status' => 'draft']);
        unset($barisKosong['suhu']);
        $barisKosong['tekanan'] = ['satuan' => 'MPa', 'display' => 'Digital', 'pembacaan_standar' => [null, null]];

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$draft->id}", $barisKosong)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lembar');

        $this->assertEquals($draft->hasil_autoclave, $draft->fresh()->hasil_autoclave);
    }

    public function test_draft_yang_memang_masih_kosong_boleh_disimpan_kosong_lagi(): void
    {
        // `set_point` ikut terkirim: dia setelan, bukan bacaan, jadi tidak
        // menjadikan lembar ini "berisi". Draft tanpa set point diuji terpisah.
        $kosong = $this->payload(['status' => 'draft']);
        unset($kosong['suhu'], $kosong['tekanan']);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $kosong)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$id}", [...$kosong, 'alat_serial_number' => 'SN-03'])
            ->assertOk();

        $this->assertSame('SN-03', CalibrationSession::findOrFail($id)->alat_serial_number);
    }

    public function test_put_generik_tetap_ke_update_bukan_ke_jalur_autoklaf(): void
    {
        // `PUT /calibrations/{id}` dan `PUT /calibrations/autoclave/{id}` tidak
        // saling menangkap: id numerik di jalur generik tetap sampai `update()`
        // (yang memvalidasi `CalibrationRequest`, bukan `AutoclaveStoreRequest`).
        $draft = $this->draftTeknisi();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson("/api/calibrations/{$draft->id}", [])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('set_point');
    }
}
