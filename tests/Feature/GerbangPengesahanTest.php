<?php

namespace Tests\Feature;

use App\Events\PerubahanDataOrganisasi;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\PemisahanWewenang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gerbang pengesahan sertifikat — "sahkan dulu, terbit belakangan".
 *
 * ## Satu test di berkas ini lebih penting dari yang lain
 *
 * `test_approve_tidak_lagi_menerbitkan_sertifikat()`.
 *
 * Seluruh gerbang ini bergantung pada satu fakta: `dispatch(new
 * GenerateCertificate(...))` **pindah** dari `CalibrationController::approve()`
 * ke `PengesahanController::sahkan()`. Kalau pemindahan itu ketinggalan, tidak
 * ada yang memberi tahu — migrasinya jalan, rutenya ada, antreannya terisi,
 * layarnya cakep, dan sertifikat **tetap** lahir waktu admin mencet Setujui.
 * Yang ditambahkan cuma satu klik, dan janji "masih bisa dibalik" jadi bohong.
 *
 * Test itu satu-satunya hal yang berteriak.
 *
 * ## Kenapa sesinya autoklaf
 *
 * Alasan yang sama dengan `ChaosTerbitSertifikatTest` dan
 * `ApproveDuaKaliSatuSertifikatTest`: itu satu-satunya bentuk sesi yang lolos
 * `CalibrationValidator` tanpa menanam titik hasil hitung sendiri. Angkanya
 * disalin dari sana apa adanya — jangan "dirapikan", nilainya dipilih supaya
 * hitung ulangnya cocok.
 *
 * ## Kenapa sakelarnya dinyalakan di setUp, bukan di phpunit.xml
 *
 * `kalibrasi.gerbang_pengesahan` default `false` supaya PR pertamanya mendarat
 * tanpa menyentuh ratusan test lama. Menyalakannya global di `phpunit.xml`
 * membatalkan seluruh gunanya. Jadi dia dinyalakan di sini, per berkas.
 */
class GerbangPengesahanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private User $superAdmin;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');
        config()->set('kalibrasi.gerbang_pengesahan', true);
        config()->set('kalibrasi.pemisahan_wewenang_memblokir', false);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'kode_teknisi' => 'RZP',
        ]);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->sesi = $this->bikinSesiSiapDisetujui();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTI
    // ─────────────────────────────────────────────────────────────────────────

    public function test_approve_tidak_lagi_menerbitkan_sertifikat(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/approve", [
                'abaikan_peringatan' => true,
                'berlaku_sampai' => now()->addMonths(7)->toDateString(),
            ])
            ->assertOk();

        $this->assertTrue(
            Queue::pushed(GenerateCertificate::class)->isEmpty(),
            'approve() masih mengantrekan GenerateCertificate. Pemindahan dispatch-nya ke '
            .'PengesahanController::sahkan() ketinggalan — dan tanpa itu seluruh gerbang '
            .'pengesahan cuma menambah satu klik. Lihat BEDAH-01 Potongan 3.',
        );

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
            $this->sesi->fresh()->status,
        );

        $this->assertSame(
            0,
            Certificate::query()->count(),
            'Ada baris sertifikat sesudah approve. Nomor sertifikat cuma boleh '
            .'dialokasikan waktu disahkan.',
        );
    }

    public function test_approve_menitipkan_masa_berlaku_dan_penandatangan_ke_pengesah(): void
    {
        Queue::fake();
        $berlaku = now()->addMonths(7)->toDateString();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/approve", [
                'abaikan_peringatan' => true,
                'berlaku_sampai' => $berlaku,
                'penandatangan_user_id' => $this->superAdmin->id,
                'catatan_pengajuan' => 'Alat FAIL, pelanggan sudah ditelepon.',
            ])
            ->assertOk();

        $segar = $this->sesi->fresh();

        // Kalau ketiga field ini tidak tersimpan, pengesah kehilangan keputusan
        // yang sudah diambil admin dan harus menebaknya ulang — dan yang
        // ditebak ulang itu tanggal yang tercetak di sertifikat.
        $this->assertSame($berlaku, $segar->berlaku_sampai_diminta?->toDateString());
        $this->assertSame($this->superAdmin->id, $segar->penandatangan_user_id);
        $this->assertSame('Alat FAIL, pelanggan sudah ditelepon.', $segar->catatan_pengajuan);
        $this->assertSame($this->admin->id, $segar->diajukan_oleh);
        $this->assertNotNull($segar->diajukan_pada);
    }

    public function test_sahkan_yang_menerbitkan_sertifikat(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        $this->assertNotNull(
            Queue::pushed(GenerateCertificate::class)->first(),
            'sahkan() tidak mengantrekan penerbitan. Ini satu-satunya pintu yang boleh.',
        );

        $segar = $this->sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $segar->status);
        $this->assertSame($this->superAdmin->id, $segar->disahkan_oleh);
        $this->assertNotNull($segar->disahkan_pada);

        // Kolom yang ditunjuk auditor kalau bertanya "siapa yang mengesahkan
        // dokumen ini" harus berdiri sendiri — bukan menumpang `reviewed_by`,
        // yang tertimpa lagi kalau sesinya dikembalikan lalu diajukan ulang.
        $this->assertSame($this->admin->id, $segar->reviewed_by);
        $this->assertNotSame($segar->reviewed_by, $segar->disahkan_oleh);
    }

    public function test_pengesahan_tercatat_di_jejak_audit(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        // UPDATE bersyarat di `sahkan()` lewat query builder, dan query builder
        // tidak memicu event model — jadi `Diaudit` tidak pernah tahu kecuali
        // dicatat tangan. Ini keputusan yang menerbitkan dokumen berlogo
        // akreditasi; baris auditnya nggak boleh hilang.
        $baris = AuditLog::query()
            ->where('entity_type', 'calibration_sessions')
            ->where('entity_id', $this->sesi->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->get();

        $this->assertTrue(
            $baris->contains(fn (AuditLog $l): bool => ($l->new_data['status'] ?? null)
                === CalibrationSession::STATUS_DISETUJUI),
            'Pengesahan tidak meninggalkan baris audit dengan status barunya.',
        );

        $this->assertTrue(
            $baris->contains(fn (AuditLog $l): bool => $l->changed_by === $this->superAdmin->id),
            'Baris audit pengesahan tidak menyebut siapa yang mengesahkan.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEWENANG
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_tidak_bisa_mengesahkan(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertForbidden();

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
            $this->sesi->fresh()->status,
            'Admin berhasil mengesahkan sertifikatnya sendiri. Pemisahan wewenang '
            .'(ISO/IEC 17025 §6.2) ditegakkan sistem, bukan disiplin.',
        );
    }

    public function test_super_admin_tetap_tidak_bisa_menyetujui_sesi(): void
    {
        // Yang dibuka keputusan 26 Sep cuma PENGESAHAN. Wewenang lain di dokumen
        // Super Admin §9 tidak berubah: dia tetap tidak menyetujui sesi, tidak
        // mengedit lembar kerja, tidak mengelola pengguna. Test ini yang menahan
        // `lolosBacaSuperAdmin` dari dilonggarkan jadi "super admin boleh POST
        // apa saja" waktu ada yang mengejar 403 yang membingungkan.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertForbidden();
    }

    public function test_teknisi_tidak_bisa_mengesahkan(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JANJI "MASIH BISA DIBALIK"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_bisa_menarik_pengajuannya_selama_belum_disahkan(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/tarik-pengajuan", [
                'alasan' => 'Salah pilih penandatangan.',
            ])
            ->assertOk();

        $segar = $this->sesi->fresh();

        // Kembali ke antrean ADMIN, bukan ke teknisi. `perlu_revisi` adalah
        // kotak masuk teknisi dan membuka lagi lembar kerja untuk diedit —
        // padahal angkanya tidak dipersoalkan sama sekali.
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $segar->status);
        $this->assertNull($segar->diajukan_pada);
        $this->assertNull($segar->diajukan_oleh);

        $this->assertSame(0, Certificate::query()->count());
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    public function test_tarik_pengajuan_ditolak_sesudah_disahkan(): void
    {
        Queue::fake();
        $this->ajukan();
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        // Sesudah disahkan, sertifikat sudah di tangan orang lain — dicetak,
        // dilampirkan ke audit pelanggan, dipindai asesor. ISO/IEC 17025 §7.8.8:
        // amandemen wajib diterbitkan sebagai dokumen baru yang menunjuk
        // aslinya. Jadi yang tersisa cuma Revisi (`-R1`) atau Pembatalan.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/tarik-pengajuan", [
                'alasan' => 'Ternyata mau diubah.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Revisi'));
    }

    public function test_admin_lain_tidak_bisa_menarik_pengajuan_orang_lain(): void
    {
        Queue::fake();
        $this->ajukan();

        $adminLain = User::factory()->admin()->create(['organization_id' => $this->org->id]);

        // Temuan B04 (paket 30 Sep). Pengajuan itu pemeriksaan milik admin yang
        // mengajukannya; admin lain yang menariknya membatalkan pemeriksaan
        // orang lain tanpa sepengetahuannya. Dulu `tarikPengajuan()` tidak
        // membandingkan `diajukan_oleh` sama sekali.
        $this->actingAs($adminLain)
            ->postJson("/api/calibrations/{$this->sesi->id}/tarik-pengajuan", [
                'alasan' => 'Ditarik admin lain.',
            ])
            ->assertForbidden();

        $segar = $this->sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_PENGESAHAN, $segar->status);
        $this->assertSame($this->admin->id, $segar->diajukan_oleh);
    }

    public function test_pengesah_mengembalikan_ke_admin_dan_wajib_beralasan(): void
    {
        Queue::fake();
        $this->ajukan();

        // Tanpa alasan → 422. Yang menerima pesan ini harus memperbaiki sesuatu
        // tanpa bisa balik bertanya.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/kembalikan-dari-pengesahan", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/kembalikan-dari-pengesahan", [
                'alasan' => 'Lampiran sertifikat standar RZ-04 belum diunggah.',
            ])
            ->assertOk();

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            $this->sesi->fresh()->status,
        );
        $this->assertSame(0, Certificate::query()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SINKRON ANTAR-PERANGKAT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Super admin bisa masuk dari dua HP sekaligus, dan admin yang mengajukan
     * menonton antreannya dari laptop. Tanpa siaran, pengesahan dari satu
     * perangkat baru terlihat di perangkat lain sesudah tarikan berkala — dan
     * dua super admin yang sama-sama menekan "Sahkan" pada kartu yang sudah
     * basi adalah persis balapan yang dijaga `test_sahkan_dua_kali_cuma_satu_sertifikat`.
     */
    public function test_sahkan_kembalikan_dan_tarik_menyiarkan_sinyal_ke_perangkat_lain(): void
    {
        Queue::fake();
        $this->ajukan();
        Event::fake([PerubahanDataOrganisasi::class]);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/kembalikan-dari-pengesahan", [
                'alasan' => 'Lampiran sertifikat standar belum diunggah.',
            ])
            ->assertOk();

        $this->ajukan();
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/tarik-pengajuan", ['alasan' => 'Salah ttd.'])
            ->assertOk();

        $this->ajukan();
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        foreach (['dikembalikan', 'ditarik', 'disahkan'] as $aksi) {
            Event::assertDispatched(
                PerubahanDataOrganisasi::class,
                fn (PerubahanDataOrganisasi $e): bool => $e->jenis === 'kalibrasi'
                    && $e->aksi === $aksi
                    && $e->id === $this->sesi->id
                    && $e->organizationId === $this->org->id,
            );
        }
    }

    public function test_sahkan_tetap_tersimpan_walau_siaran_meledak(): void
    {
        Queue::fake();
        $this->ajukan();

        // Driver siaran yang tidak bisa dihubungi = exception waktu dispatch,
        // persis Reverb yang sedang mati. Pola yang sama dengan
        // `KabarGagalTidakGagalinSesiTest`.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'x',
            'secret' => 'x',
            'app_id' => 'x',
            'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http'],
            'client_options' => ['timeout' => 1],
        ]]);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $this->sesi->fresh()->status);
        $this->assertNotNull(Queue::pushed(GenerateCertificate::class)->first());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NOMOR SERTIFIKAT
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pengajuan_yang_ditarik_tidak_menghabiskan_nomor(): void
    {
        Queue::fake();

        // Ini alasan paling kuat kenapa gerbangnya ditaruh SEBELUM penerbitan,
        // bukan sesudah. Kalau sertifikat lahir di approve() lalu "dibatalkan",
        // nomornya sudah terpakai dan urutan lab jadi bolong — dan nomor yang
        // bolong itu pertanyaan asesor, bukan cuma bug.
        $sesiKedua = $this->bikinSesiSiapDisetujui();

        $this->ajukan($this->sesi);
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$this->sesi->id}/tarik-pengajuan", ['alasan' => 'Salah ttd.'])
            ->assertOk();

        $this->ajukan($sesiKedua);
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$sesiKedua->id}/sahkan")
            ->assertOk();

        /** @var GenerateCertificate $job */
        $job = Queue::pushed(GenerateCertificate::class)->first();
        app()->call([$job, 'handle']);

        $nomor = Certificate::query()->pluck('nomor');

        $this->assertCount(
            1,
            $nomor,
            'Pengajuan yang ditarik ikut melahirkan sertifikat. Nomornya terpakai untuk '
            .'dokumen yang tidak pernah ada.',
        );
        $this->assertStringEndsWith(
            '0001',
            (string) $nomor->first(),
            'Sertifikat pertama lab ini tidak bernomor 0001 — ada nomor yang sudah habis '
            .'dipakai pengajuan yang ditarik.',
        );
    }

    public function test_sahkan_dua_kali_cuma_satu_sertifikat(): void
    {
        Queue::fake();
        $this->ajukan();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertOk();

        // Panggilan kedua ditahan pemeriksaan status. Balapan yang SUNGGUHAN —
        // dua request yang sama-sama lolos pemeriksaan status lalu sama-sama
        // sampai ke UPDATE — ditahan `where('status', ...)` di UPDATE-nya dan
        // dijawab 409; tekniknya dicontoh `ApproveDuaKaliSatuSertifikatTest`.
        // Yang diuji di sini akibat yang kelihatan penggunanya: tidak ada job
        // kedua, jadi tidak ada nomor kedua.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertStatus(422);

        $this->assertCount(
            1,
            Queue::pushed(GenerateCertificate::class),
            'Dua pengesahan melahirkan dua job. Satu sesi bisa dapat dua nomor sertifikat.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PEMISAHAN WEWENANG
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pemisahan_wewenang_memperingatkan_sekali_lalu_boleh_dilanjut(): void
    {
        Queue::fake();
        $this->ajukan();

        // Super admin yang juga teknisi pengisi lembar kerja ini.
        $this->sesi->forceFill(['teknisi_id' => $this->superAdmin->id])->save();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertStatus(422)
            ->assertJsonPath('butuh_konfirmasi', true)
            ->assertJsonPath(
                'wewenang.temuan.0.kode',
                PemisahanWewenang::KODE_PENGESAH_SAMA_DENGAN_TEKNISI,
            );

        // Diakui → lanjut. Pelanggarannya tercatat, bukan diblokir: lab yang
        // cuma punya satu pengesah dan diblokir akan saling pinjam akun, dan
        // jejak audit yang bohong jauh lebih buruk daripada satu peringatan.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan", ['abaikan_peringatan' => true])
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()
                ->where('entity_id', $this->sesi->id)
                ->where('note', 'like', '%'.PemisahanWewenang::KODE_PENGESAH_SAMA_DENGAN_TEKNISI.'%')
                ->exists(),
            'Pelanggaran pemisahan wewenang dilanjutkan tanpa tercatat di riwayat. '
            .'Peringatan yang tidak meninggalkan jejak sama saja dengan tidak ada.',
        );
    }

    public function test_pemisahan_wewenang_memblokir_kalau_sakelarnya_nyala(): void
    {
        config()->set('kalibrasi.pemisahan_wewenang_memblokir', true);

        Queue::fake();
        $this->ajukan();
        $this->sesi->forceFill(['teknisi_id' => $this->superAdmin->id])->save();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan", ['abaikan_peringatan' => true])
            ->assertStatus(422);

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
            $this->sesi->fresh()->status,
            '`abaikan_peringatan` menembus mode memblokir. Sakelar yang bisa dilewati '
            .'flag bukan sakelar.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ISOLASI ANTAR-LAB
    // ─────────────────────────────────────────────────────────────────────────

    public function test_antrean_tidak_menampilkan_sesi_lab_lain(): void
    {
        Queue::fake();
        $this->ajukan();

        $labLain = Organization::factory()->create(['nama' => 'Lab Sebelah']);
        $superAdminLabLain = User::factory()->create([
            'organization_id' => $labLain->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $isi = (string) $this->actingAs($superAdminLabLain)
            ->getJson('/api/pengesahan/antrean')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            (string) $this->sesi->nomor_sesi,
            $isi,
            'Antrean pengesahan lab sebelah memuat lembar kerja lab ini. Kerahasiaan '
            .'antar pelanggan, ISO/IEC 17025 klausul 4.2.',
        );
    }

    public function test_sahkan_sesi_lab_lain_dijawab_404_bukan_403(): void
    {
        Queue::fake();
        $this->ajukan();

        $labLain = Organization::factory()->create();
        $superAdminLabLain = User::factory()->create([
            'organization_id' => $labLain->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        // 403 mengonfirmasi bahwa id itu ADA di lab lain; 404 tidak membocorkan
        // apa pun. Pola yang sama dipakai enam controller lain lewat
        // `pastikanSatuOrganisasi`. Lihat ../../B-data-tidak-bocor/README.md.
        $this->actingAs($superAdminLabLain)
            ->postJson("/api/calibrations/{$this->sesi->id}/sahkan")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penolong
    // ─────────────────────────────────────────────────────────────────────────

    private function ajukan(?CalibrationSession $sesi = null): void
    {
        $sesi ??= $this->sesi;

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", [
                'abaikan_peringatan' => true,
                'berlaku_sampai' => now()->addMonths(7)->toDateString(),
            ])
            ->assertOk();

        $sesi->refresh();
    }

    /**
     * Sesi autoklaf yang lolos `CalibrationValidator`.
     *
     * Angkanya disalin apa adanya dari `ChaosTerbitSertifikatTest` —
     * nilai-nilainya dipilih supaya hitung ulang validator cocok, jadi jangan
     * dibulatkan "biar rapi".
     */
    private function bikinSesiSiapDisetujui(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $alat->id,
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
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }
}
