<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use App\Services\PemisahanWewenang;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * K-49-14 (pemilik proyek, 15 Okt 2026): super admin boleh SEMUA aksi tulis
 * teknisi dan Master Data (admin) di organisasinya sendiri, dari aplikasi —
 * dan Order Number wajib terisi sebelum sesi disetujui.
 *
 * Yang dijaga di sini tiga lapis, karena masing-masing gagal tanpa error:
 *
 * 1. **Gerbang rute** (`EnsureUserHasRole::lolosTulisSuperAdmin`) — tanpa itu
 *    super admin kena 403 di rute admin/teknisi.
 * 2. **Cabang "admin boleh X" di controller** (`User::punyaHakAdmin`) — tanpa
 *    itu super admin lolos rute lalu ditolak di dalamnya ("cuma teknisi yang
 *    ngerjain sesi ini"), atau Order Number kirimannya dibuang diam-diam
 *    seperti kiriman teknisi.
 * 3. **Pemisahan wewenang K-30-03 TETAP** — super admin yang mengisi,
 *    mengoreksi, atau mengonfirmasi pindai sebuah sesi tidak bisa menyetujui
 *    maupun mengesahkannya. Tidak ada jalur pengecualian.
 *
 * Sesinya autoklaf karena itu satu-satunya bentuk yang lolos
 * `CalibrationValidator` tanpa menanam hasil hitung sendiri (alasan yang sama
 * dengan `PemisahanWewenangPersetujuanTest`).
 */
class SuperAdminSemuaAksiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('arsip');
        config()->set('kalibrasi.gerbang_pengesahan', false);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'kode_teknisi' => 'RZP',
        ]);
        $this->super = $this->superAdmin($this->org);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ISI LEMBAR — super admin sebagai pengisi
    // ─────────────────────────────────────────────────────────────────────────

    public function test_super_admin_membuat_sesi_dan_tercatat_sebagai_pengisinya(): void
    {
        $sesi = $this->bikinSesiAutoklaf($this->super, ['nomor_order' => 'ORD/UJI/0001']);

        // Pengisi = akun super admin itu sendiri, tidak menyamar sebagai
        // teknisi (AGENTS.md §Peran butir 3). Order Number kirimannya DISIMPAN
        // — dibuang cuma untuk teknisi & viewer.
        $this->assertSame($this->super->id, (int) $sesi->teknisi_id);
        $this->assertSame('ORD/UJI/0001', $sesi->nomor_order);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
    }

    public function test_super_admin_membuat_sesi_lewat_jalur_umum(): void
    {
        $id = $this->actingAs($this->super, 'sanctum')
            ->postJson('/api/calibrations', $this->payloadUmum($this->alatUmum()) + ['nomor_order' => 'ORD/UJI/0002'])
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame($this->super->id, (int) $sesi->teknisi_id);
        $this->assertSame('ORD/UJI/0002', $sesi->nomor_order);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AKSI MASTER DATA atas lembar teknisi
    // ─────────────────────────────────────────────────────────────────────────

    public function test_super_admin_mengubah_lembar_teknisi_dan_mengisi_order_number(): void
    {
        $alat = $this->alatUmum();
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payloadUmum($alat))
            ->assertCreated()
            ->json('data.id');

        // Lembar sudah dikirim (`menunggu_approval`) — jendela yang dulu cuma
        // terbuka untuk admin. Angkanya tidak diubah, jadi bukan koreksi.
        $this->actingAs($this->super, 'sanctum')
            ->putJson("/api/calibrations/{$id}", $this->payloadUmum($alat) + ['nomor_order' => 'ORD/UJI/0003'])
            ->assertOk()
            ->assertJsonPath('data.nomor_order', 'ORD/UJI/0003');

        $this->assertSame($this->teknisi->id, (int) CalibrationSession::findOrFail($id)->teknisi_id);
    }

    public function test_super_admin_mengisi_field_admin_lewat_patch(): void
    {
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);

        $this->actingAs($this->super, 'sanctum')
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => 'ORD/UJI/0004'])
            ->assertOk()
            ->assertJsonPath('data.nomor_order', 'ORD/UJI/0004');

        // Detail sesi untuk super admin membawa kolom administratifnya.
        $this->actingAs($this->super, 'sanctum')
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.nomor_order', 'ORD/UJI/0004');
    }

    public function test_lembar_kerja_untuk_super_admin_memuat_bagian_administratif(): void
    {
        $alat = $this->alatAutoklaf();

        $admin = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');
        $super = $this->actingAs($this->super, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');
        $teknisi = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');

        // Bentuk super admin = bentuk admin, dan beda dari bentuk teknisi
        // (yang sengaja tanpa kolom administratif).
        $this->assertSame($admin, $super);
        $this->assertNotSame($teknisi, $super);
    }

    /**
     * Dulu test ini "super admin menolak sesi teknisi" (`reject`). K-50-01
     * (15 Okt 2026) mencabut Tolak: super admin mengoreksi lewat `PUT` seperti
     * Master Data. Rute reject sendiri dijaga `TolakDicabutTest`.
     */
    public function test_super_admin_mengoreksi_sesi_teknisi_lewat_put_bukan_menolak(): void
    {
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);

        $this->actingAs($this->super, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Ulangi.'])
            ->assertNotFound();

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
    }

    public function test_super_admin_menyetujui_sesi_teknisi(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->isiOrderNumber($sesi);

        $this->setujui($sesi, $this->super)->assertOk();

        $segar = $sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $segar->status);
        $this->assertSame($this->super->id, (int) $segar->reviewed_by);
        Queue::assertPushed(GenerateCertificate::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PEMISAHAN WEWENANG (K-30-03) — tetap, tanpa pengecualian
    // ─────────────────────────────────────────────────────────────────────────

    public function test_super_admin_pengisi_tidak_bisa_menyetujui_dan_admin_lain_boleh(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->super, ['nomor_order' => 'ORD/UJI/0005']);

        $this->setujui($sesi, $this->super)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pemisahan_wewenang')
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_SAMA_DENGAN_TEKNISI);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());

        $this->setujui($sesi, $this->admin)->assertOk();
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    public function test_super_admin_pengoreksi_tidak_bisa_menyetujui(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->isiOrderNumber($sesi);

        // Koreksi SUNGGUHAN lewat jalur aplikasi: angka disk 1 diganti dengan
        // alasan. Cabang koreksinya dulu `isAdmin()` — super admin yang
        // mengubah angka tidak tercatat sebagai pengoreksi dan lolos
        // menyetujui hasil koreksinya sendiri.
        $payload = $this->payloadAutoklaf($sesi->equipment_id);
        $payload['suhu']['disk'][0][0] = 121.28;

        $this->actingAs($this->super, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $payload + [
                'alasan_koreksi' => 'Disk 1 ulangan 1 salah salin dari kertas.',
            ])
            ->assertOk();

        $this->assertTrue(AuditLog::query()
            ->where('entity_type', $sesi->getTable())
            ->where('entity_id', $sesi->id)
            ->where('changed_by', $this->super->id)
            ->where('note', 'like', AuditLog::CATATAN_KOREKSI_PEMBACAAN.'%')
            ->exists(), 'Koreksi super admin tidak tercatat sebagai koreksi pembacaan.');

        $this->setujui($sesi, $this->super)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pemisahan_wewenang')
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_MENGOREKSI);

        $this->setujui($sesi, $this->admin)->assertOk();
    }

    public function test_super_admin_verifikator_pindai_tidak_bisa_menyetujui(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->isiOrderNumber($sesi);
        $this->tanamPembacaanPindai($sesi);

        $this->actingAs($this->super, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertOk()
            ->assertJsonPath('meta.diverifikasi', 1);

        $this->assertSame($this->super->id, (int) $sesi->rawMeasurements()->first()->verified_by);

        $this->setujui($sesi, $this->super)
            ->assertStatus(422)
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_MEMVERIFIKASI_OCR);

        $this->setujui($sesi, $this->admin)->assertOk();
    }

    public function test_super_admin_pengisi_atau_pengoreksi_tidak_bisa_mengesahkan(): void
    {
        config()->set('kalibrasi.gerbang_pengesahan', true);
        Queue::fake();
        $superLain = $this->superAdmin($this->org);

        // (a) Diisi super admin, diperiksa admin, disahkan super admin yang SAMA.
        $diisi = $this->bikinSesiAutoklaf($this->super, ['nomor_order' => 'ORD/UJI/0006']);
        $this->setujui($diisi, $this->admin)->assertOk();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_PENGESAHAN, $diisi->fresh()->status);

        $this->sahkan($diisi, $this->super)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pemisahan_wewenang')
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENGESAH_SAMA_DENGAN_TEKNISI);

        // (b) Diisi teknisi, dikoreksi super admin, diperiksa admin.
        $dikoreksi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->isiOrderNumber($dikoreksi);
        $payload = $this->payloadAutoklaf($dikoreksi->equipment_id);
        $payload['suhu']['disk'][1][0] = 121.29;
        $this->actingAs($this->super, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$dikoreksi->id}", $payload + [
                'alasan_koreksi' => 'Disk 2 ulangan 1 salah salin dari kertas.',
            ])
            ->assertOk();
        $this->setujui($dikoreksi, $this->admin)->assertOk();

        $this->sahkan($dikoreksi, $this->super)
            ->assertStatus(422)
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENGESAH_MENGOREKSI);

        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());

        // Pengesah lain yang tidak ikut mengisi tetap bisa — penjagaannya per
        // orang, bukan per peran.
        $this->sahkan($diisi, $superLain)->assertOk();
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $diisi->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORDER NUMBER WAJIB
    // ─────────────────────────────────────────────────────────────────────────

    public function test_approve_tanpa_order_number_ditolak(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->assertNull($sesi->nomor_order);

        foreach ([$this->admin, $this->super] as $penyetuju) {
            $this->setujui($sesi, $penyetuju)
                ->assertStatus(422)
                ->assertJsonPath('kode', CalibrationSession::KODE_NOMOR_ORDER_WAJIB)
                ->assertJsonValidationErrors('nomor_order');
        }

        // Spasi saja = kosong.
        $sesi->forceFill(['nomor_order' => '   '])->save();
        $this->setujui($sesi, $this->admin)
            ->assertStatus(422)
            ->assertJsonPath('kode', CalibrationSession::KODE_NOMOR_ORDER_WAJIB);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());

        // Sesudah Master Data mengisinya, persetujuan jalan.
        $this->isiOrderNumber($sesi);
        $this->setujui($sesi, $this->admin)->assertOk();
    }

    public function test_panel_menahan_persetujuan_tanpa_order_number(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    public function test_teknisi_tidak_bisa_mengisi_order_number(): void
    {
        $sesi = $this->bikinSesiAutoklaf($this->teknisi, ['nomor_order' => 'ORD/TEKNISI']);
        $this->assertNull($sesi->nomor_order, 'Order Number kiriman teknisi lolos ke database.');

        $this->actingAs($this->teknisi, 'sanctum')
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => 'ORD/TEKNISI'])
            ->assertForbidden();

        // Sesudah Master Data mengisinya, simpan ulang lembar oleh teknisi tidak
        // menimpanya (lembar dikembalikan dulu supaya teknisi boleh menyimpan).
        $this->isiOrderNumber($sesi, 'ORD/ADMIN/0007');
        $sesi->forceFill(['status' => CalibrationSession::STATUS_PERLU_REVISI])->save();

        $this->actingAs($this->teknisi, 'sanctum')
            ->putJson(
                "/api/calibrations/autoclave/{$sesi->id}",
                $this->payloadAutoklaf($sesi->equipment_id) + ['nomor_order' => 'ORD/TEKNISI'],
            )
            ->assertOk();

        $this->assertSame('ORD/ADMIN/0007', $sesi->fresh()->nomor_order);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SERTIFIKAT — retry & revisi
    // ─────────────────────────────────────────────────────────────────────────

    public function test_super_admin_retry_sertifikat_gagal(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL, 'pdf_path' => null]);

        Queue::fake();

        $this->actingAs($this->super, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertOk();

        Queue::assertPushed(
            GenerateCertificate::class,
            fn (GenerateCertificate $job): bool => $job->issuedBy === $this->super->id,
        );
    }

    public function test_super_admin_merevisi_sertifikat_terbit(): void
    {
        $asal = $this->sertifikatTerbit();

        $id = $this->actingAs($this->super, 'sanctum')
            ->postJson("/api/certificates/{$asal->id}/revisi", [
                'perubahan' => ['nomor_seri' => 'SN-BARU-01'],
                'alasan' => 'Salah ketik nomor seri',
                'catatan_pelanggan' => 'Nomor seri disesuaikan dengan pelat nama.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.revisi_ke', 1)
            ->json('data.id');

        $this->assertSame($asal->id, (int) Certificate::findOrFail($id)->revision_of);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // YANG TETAP TERTUTUP
    // ─────────────────────────────────────────────────────────────────────────

    /** Tulis lintas organisasi TIDAK dibuka — penyaring organisasi tidak berubah. */
    public function test_super_admin_lab_lain_dijawab_404(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesiAutoklaf($this->teknisi);
        $this->isiOrderNumber($sesi);
        $labLain = Organization::factory()->create();
        $superLabLain = $this->superAdmin($labLain);

        $this->actingAs($superLabLain, 'sanctum')
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => 'ORD/LAIN'])
            ->assertNotFound();
        $this->setujui($sesi, $superLabLain)->assertNotFound();

        // Payload-nya sah untuk lab SENDIRI (alatnya milik lab lain itu) —
        // supaya yang menolak penyaring organisasi sesinya, bukan validasi
        // `equipment_id` yang jalan lebih dulu.
        $alatLabLain = Equipment::factory()->create([
            'organization_id' => $labLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $labLain->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $labLain->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
        $this->actingAs($superLabLain, 'sanctum')
            ->putJson("/api/calibrations/autoclave/{$sesi->id}", $this->payloadAutoklaf($alatLabLain->id))
            ->assertNotFound();

        $segar = $sesi->fresh();
        $this->assertSame('ORD/UJI/9999', $segar->nomor_order);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $segar->status);
    }

    /** Dunia pelanggan tidak ikut kebuka — gerbangnya beda dunia, bukan beda tingkat. */
    public function test_super_admin_tetap_ditolak_rute_pelanggan(): void
    {
        config(['pelanggan.fitur' => true]);
        $token = $this->super->createToken('hp', ['internal'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/pelanggan/v1/saya')
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_aplikasi_ini');

        $this->withToken($token)
            ->postJson('/api/pelanggan/v1/permintaan', [])
            ->assertForbidden();
    }

    /** Eskalasi tetap tertahan: super admin tidak bisa mencetak super admin. */
    public function test_super_admin_tidak_bisa_mencetak_super_admin(): void
    {
        $this->assertNotContains(User::ROLE_SUPER_ADMIN, User::roles());

        $this->actingAs($this->super, 'sanctum')
            ->putJson("/api/users/{$this->teknisi->id}", ['role' => User::ROLE_SUPER_ADMIN])
            ->assertStatus(422);

        $this->assertSame(User::ROLE_TEKNISI, $this->teknisi->fresh()->role);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bantuan
    // ─────────────────────────────────────────────────────────────────────────

    private function superAdmin(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);
    }

    private function setujui(CalibrationSession $sesi, User $oleh): TestResponse
    {
        return $this->actingAs($oleh, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true]);
    }

    private function sahkan(CalibrationSession $sesi, User $oleh): TestResponse
    {
        return $this->actingAs($oleh, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/sahkan");
    }

    /** Order Number diisi Master Data lewat pintu resminya. */
    private function isiOrderNumber(CalibrationSession $sesi, string $nomor = 'ORD/UJI/9999'): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/calibrations/{$sesi->id}/admin", ['nomor_order' => $nomor])
            ->assertOk();

        $sesi->refresh();
    }

    private function alatAutoklaf(): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * Angkanya disalin apa adanya dari `PemisahanWewenangPersetujuanTest` —
     * dipilih supaya hitung ulang validator cocok, jadi jangan dibulatkan.
     *
     * @return array<string, mixed>
     */
    private function payloadAutoklaf(int $alatId): array
    {
        return [
            'equipment_id' => $alatId,
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
        ];
    }

    /** @param  array<string, mixed>  $tambahan */
    private function bikinSesiAutoklaf(User $pengisi, array $tambahan = []): CalibrationSession
    {
        $id = $this->actingAs($pengisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadAutoklaf($this->alatAutoklaf()->id) + $tambahan)
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /**
     * Sesi autoklaf tidak menyimpan baris `raw_measurements`, jadi satu
     * pembacaan hasil pindai yang belum dikonfirmasi ditanam di sini.
     */
    private function tanamPembacaanPindai(CalibrationSession $sesi): void
    {
        RawMeasurement::create([
            'calibration_session_id' => $sesi->id,
            'titik_ke' => 1,
            'pembacaan_ke' => 1,
            'titik_ukur' => 121.0,
            'pembacaan' => 121.2,
            'satuan' => '°C',
            'input_source' => 'ocr',
            'is_verified' => false,
        ]);
    }

    private function alatUmum(): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadUmum(Equipment $alat): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::query()->where('organization_id', $this->org->id)->value('id')
                ?? Standard::factory()->create(['organization_id' => $this->org->id])->id,
            'tanggal_kalibrasi' => '2026-07-20',
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ];
    }

    /** Sertifikat lewat jalur SUNGGUHAN, pola `RevisiSertifikatTest`. */
    private function sertifikatTerbit(): Certificate
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payloadUmum($this->alatUmum()))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $sesi->update([
            'nomor_order' => 'ORD/UJI/0008',
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $this->admin->id,
        ]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }
}
