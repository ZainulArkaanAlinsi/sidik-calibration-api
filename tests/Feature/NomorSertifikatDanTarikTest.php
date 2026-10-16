<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * K-50-04/13 (No. Sertifikat + Order Number diisi saat Setujui) dan K-50-03
 * (tarik sertifikat -> menunggu_approval -> terbit ulang `<nomor>-R1`).
 *
 * Sesinya autoklaf: satu-satunya bentuk yang lolos `CalibrationValidator`
 * tanpa menanam hasil hitung sendiri (alasan sama dengan `GerbangPengesahanTest`
 * dulu), dan lembarnya tidak menulis `raw_measurements` sehingga hash input
 * diuji lewat `hasil_autoclave` + `input_asli`.
 */
class NomorSertifikatDanTarikTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $adminLain;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->adminLain = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
    }

    // ── No. Sertifikat saat Setujui ────────────────────────────────────────

    public function test_approve_tanpa_nomor_sertifikat_ditolak_422(): void
    {
        $sesi = $this->sesiAntre();

        foreach ([[], ['nomor_sertifikat' => '   ']] as $body) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson("/api/calibrations/{$sesi->id}/approve", $body)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'nomor_sertifikat_wajib')
                ->assertJsonValidationErrors('nomor_sertifikat');
        }

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertFalse(Certificate::where('calibration_session_id', $sesi->id)->exists());
    }

    public function test_karakter_tidak_aman_dan_akhiran_revisi_ditolak(): void
    {
        $sesi = $this->sesiAntre();

        foreach (['CAL 001', 'CAL<1>', 'CAL\\001', 'CAL/001-R1', 'CAL/001-r12', str_repeat('A', 101)] as $nomor) {
            $this->setujui($sesi, $nomor)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'nomor_sertifikat_tidak_valid');
        }

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
    }

    public function test_nomor_bentrok_dijawab_422_menyebut_sertifikat_pemiliknya(): void
    {
        $pertama = $this->sesiAntre();
        $this->setujui($pertama, 'KAL/2026/001')->assertOk();
        $milik = Certificate::where('calibration_session_id', $pertama->id)->sole();

        $kedua = $this->sesiAntre();
        $this->setujui($kedua, 'KAL/2026/001', $this->adminLain)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'nomor_sertifikat_dipakai')
            ->assertJsonPath('sertifikat.id', $milik->id)
            ->assertJsonPath('sertifikat.nomor', 'KAL/2026/001')
            ->assertJsonPath('sertifikat.nomor_sesi', $pertama->nomor_sesi);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $kedua->fresh()->status);
        $this->setujui($kedua, 'KAL/2026/002', $this->adminLain)->assertOk();
    }

    public function test_balapan_dua_persetujuan_nomor_sama_yang_kalah_422_dan_tetap_menunggu_approval(): void
    {
        $pemenang = $this->sesiAntre();
        $kalah = $this->sesiAntre();
        $nomor = 'KAL/2026/BALAP';

        // Persetujuan lain "menang" TEPAT sesudah `periksa()` kalah lolos: baris
        // pemegang nomor muncul ketika UPDATE status sesi yang kalah berjalan.
        $siap = true;
        DB::beforeExecuting(function (string $sql) use (&$siap, $pemenang, $nomor): void {
            if ($siap && preg_match('/^update ["`]calibration_sessions["`]/i', $sql) === 1) {
                $siap = false;
                DB::table('certificates')->insert([
                    'organization_id' => $this->org->id,
                    'calibration_session_id' => $pemenang->id,
                    'nomor' => $nomor,
                    'qr_token' => 'tokenbalap1',
                    'qr_payload' => 'http://x/verify/tokenbalap1',
                    'status' => Certificate::STATUS_GAGAL,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->setujui($kalah, $nomor, $this->adminLain)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'nomor_sertifikat_dipakai');

        $segar = $kalah->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $segar->status);
        $this->assertNull($segar->reviewed_by);
        $this->assertFalse(Certificate::where('calibration_session_id', $kalah->id)->exists());
    }

    public function test_tinggalkan_jalan_pulih_tidak_melempar_saat_nomor_bentrok(): void
    {
        $pemilik = $this->sesiAntre();
        Certificate::forceCreate([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $pemilik->id,
            'nomor' => 'KAL/2026/DUP',
            'qr_token' => 'tokendup001',
            'qr_payload' => 'http://x/verify/tokendup001',
            'status' => Certificate::STATUS_TERBIT,
        ]);

        $sesi = $this->sesiAntre();
        $sesi->forceFill(['status' => CalibrationSession::STATUS_DISETUJUI])->saveQuietly();

        (new GenerateCertificate($sesi->id, $this->admin->id, null, 'KAL/2026/DUP'))
            ->tinggalkanJalanPulih(new \RuntimeException('antrean mati'));

        $baris = Certificate::where('calibration_session_id', $sesi->id)->sole();
        $this->assertSame(Certificate::STATUS_GAGAL, $baris->status);
        $this->assertNull($baris->nomor);
    }

    public function test_nomor_yang_diketik_dipakai_sertifikat_dan_snapshotnya(): void
    {
        $sesi = $this->sesiAntre();
        $this->setujui($sesi, 'KAL/2026/777')->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->sole();
        $this->assertSame('KAL/2026/777', $sertifikat->nomor);
        $this->assertSame('KAL/2026/777', $sertifikat->snapshot['header']['certificate_number']);
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
    }

    public function test_unik_per_organisasi_bukan_global(): void
    {
        $labLain = Organization::factory()->create();
        Certificate::factory()->create(['organization_id' => $labLain->id, 'nomor' => 'KAL/BERSAMA/1']);

        $sesi = $this->sesiAntre();
        $this->setujui($sesi, 'KAL/BERSAMA/1')->assertOk();
    }

    public function test_penomoran_otomatis_tinggal_untuk_jalur_lama(): void
    {
        $sesi = $this->sesiAntre();
        $sesi->forceFill(['status' => CalibrationSession::STATUS_DISETUJUI])->save();

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $this->assertMatchesRegularExpression('#^CAL/\d{4}/\d{2}/0001$#', Certificate::where('calibration_session_id', $sesi->id)->value('nomor'));
    }

    public function test_panel_menuntut_dan_memakai_nomor_sertifikat(): void
    {
        $sesi = $this->sesiAntre();

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['nomor_sertifikat' => 'PANEL/001-R1', 'abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['nomor_sertifikat' => 'PANEL/001', 'abaikan_peringatan' => true]);

        $this->assertSame('PANEL/001', Certificate::where('calibration_session_id', $sesi->id)->value('nomor'));
    }

    public function test_panel_balapan_nomor_sama_yang_kalah_tetap_menunggu_approval(): void
    {
        $pemenang = $this->sesiAntre();
        $kalah = $this->sesiAntre();
        $nomor = 'PANEL/BALAP';

        $siap = true;
        DB::beforeExecuting(function (string $sql) use (&$siap, $pemenang, $nomor): void {
            if ($siap && preg_match('/^update ["`]calibration_sessions["`]/i', $sql) === 1) {
                $siap = false;
                DB::table('certificates')->insert([
                    'organization_id' => $this->org->id,
                    'calibration_session_id' => $pemenang->id,
                    'nomor' => $nomor,
                    'qr_token' => 'tokenpanel1',
                    'qr_payload' => 'http://x/verify/tokenpanel1',
                    'status' => Certificate::STATUS_GAGAL,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($kalah), ['nomor_sertifikat' => $nomor, 'abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $kalah->fresh()->status);
        $this->assertFalse(Certificate::where('calibration_session_id', $kalah->id)->exists());
    }

    // ── Tarik sertifikat ───────────────────────────────────────────────────

    public function test_tarik_mengembalikan_sesi_dan_tidak_menyentuh_input_mentah(): void
    {
        [$sesi, $sertifikat] = $this->sesiTerbit('KAL/T/001');
        $sebelum = $this->hashInput($sesi);

        $this->tarik($sertifikat)
            ->assertOk()
            ->assertJsonPath('data.status', Certificate::STATUS_DITARIK)
            ->assertJsonPath('data.status_dokumen', 'ditarik');

        $sertifikat->refresh();
        $this->assertSame(Certificate::STATUS_DITARIK, $sertifikat->status);
        $this->assertSame($this->admin->id, (int) $sertifikat->ditarik_oleh);
        $this->assertNotNull($sertifikat->ditarik_pada);
        $this->assertSame('Angka disk 1 salah salin dari logger.', $sertifikat->alasan_ditarik);
        $this->assertSame('KAL/T/001', $sertifikat->nomor);
        $this->assertNotNull($sertifikat->pdf_path, 'Baris & PDF yang ditarik tidak dihapus.');

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertSame($sebelum, $this->hashInput($sesi), 'Input mentah berubah akibat tarik.');
    }

    public function test_tarik_butuh_alasan_dan_sandi_benar(): void
    {
        [$sesi, $sertifikat] = $this->sesiTerbit('KAL/T/002');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'pendek', 'password' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors('alasan');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka salah salin dari logger.'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka salah salin dari logger.', 'password' => 'salah-total'])
            ->assertStatus(422)->assertJsonPath('kode', 'konfirmasi_sandi_salah');

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->fresh()->status);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    public function test_hanya_admin_satu_organisasi_dan_hanya_yang_terbit(): void
    {
        [, $sertifikat] = $this->sesiTerbit('KAL/T/003');

        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka salah salin dari logger.', 'password' => 'password'])
            ->assertForbidden();

        $adminLab2 = User::factory()->admin()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($adminLab2, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka salah salin dari logger.', 'password' => 'password'])
            ->assertNotFound();

        $this->tarik($sertifikat)->assertOk();
        $this->tarik($sertifikat)->assertStatus(422)->assertJsonValidationErrors('sertifikat');
    }

    public function test_super_admin_boleh_menarik(): void
    {
        [, $sertifikat] = $this->sesiTerbit('KAL/T/004');
        $super = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($super, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka salah salin dari logger.', 'password' => 'password'])
            ->assertOk();
    }

    public function test_setujui_ulang_menerbitkan_nomor_dasar_R1_tanpa_mengetik_nomor(): void
    {
        [$sesi, $lama] = $this->sesiTerbit('KAL/T/005');
        $this->tarik($lama)->assertOk();

        // Tidak ada nomor yang diketik — terbit ulang otomatis.
        $this->actingAs($this->adminLain, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $baru = Certificate::where('revision_of', $lama->id)->sole();
        $this->assertSame('KAL/T/005-R1', $baru->nomor);
        $this->assertSame(1, (int) $baru->revisi_ke);
        $this->assertSame(Certificate::STATUS_TERBIT, $baru->status);
        $this->assertSame('KAL/T/005-R1', $baru->snapshot['header']['certificate_number']);
        $this->assertSame('KAL/T/005', $baru->snapshot['meta']['revisi']['menggantikan']);
        $this->assertSame(Certificate::STATUS_DITARIK, $lama->fresh()->status);
        $this->assertSame('berlaku', $baru->statusDokumen());
        $this->assertSame('ditarik', $lama->fresh()->statusDokumen());

        // Tarik lagi -> R2 (nomor dasar tidak ditumpuk jadi -R1-R2).
        $this->tarik($baru)->assertOk();
        $this->actingAs($this->adminLain, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();
        $this->assertSame('KAL/T/005-R2', Certificate::where('revision_of', $baru->id)->value('nomor'));
    }

    public function test_qr_lama_menampilkan_ditarik_dan_penggantinya(): void
    {
        [$sesi, $lama] = $this->sesiTerbit('KAL/T/006');
        $this->tarik($lama)->assertOk();

        // Sebelum diganti: ditarik, belum ada pengganti.
        $this->get("/verify/{$lama->qr_token}")->assertOk()->assertSee('Sertifikat ini ditarik')->assertSee('sedang disiapkan');

        $this->actingAs($this->adminLain, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])->assertOk();

        $this->get("/verify/{$lama->qr_token}")
            ->assertOk()
            ->assertSee('Sertifikat ini ditarik')
            ->assertSee('KAL/T/006-R1');

        $this->getJson("/api/verify/{$lama->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'ditarik')
            ->assertJsonPath('data.digantikan_oleh.nomor', 'KAL/T/006-R1');

        $this->get("/verify/{$lama->qr_token}/download")->assertStatus(410);
    }

    public function test_audit_tarik_mencatat_pelaku_dan_status_lama_baru(): void
    {
        [$sesi, $sertifikat] = $this->sesiTerbit('KAL/T/007');
        $this->tarik($sertifikat)->assertOk();

        $log = DB::table('audit_logs')->where('entity_type', 'certificates')->where('entity_id', $sertifikat->id)
            ->orderByDesc('id')->first();
        $this->assertSame($this->admin->id, (int) $log->changed_by);
        $this->assertStringContainsString('ditarik', (string) $log->new_data);
        $this->assertStringContainsString('terbit', (string) $log->old_data);

        $logSesi = DB::table('audit_logs')->where('entity_type', 'calibration_sessions')->where('entity_id', $sesi->id)
            ->orderByDesc('id')->first();
        $this->assertStringContainsString('menunggu_approval', (string) $logSesi->new_data);
        $this->assertStringContainsString('disetujui', (string) $logSesi->old_data);
    }

    // ── bantuan ────────────────────────────────────────────────────────────

    private function setujui(CalibrationSession $sesi, string $nomor, ?User $oleh = null): TestResponse
    {
        return $this->actingAs($oleh ?? $this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $nomor, 'abaikan_peringatan' => true]);
    }

    private function tarik(Certificate $sertifikat): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/tarik", [
                'alasan' => 'Angka disk 1 salah salin dari logger.',
                'password' => 'password',
            ]);
    }

    /** @return array{CalibrationSession, Certificate} */
    private function sesiTerbit(string $nomor): array
    {
        $sesi = $this->sesiAntre();
        $this->setujui($sesi, $nomor)->assertOk();

        return [$sesi->fresh(), Certificate::where('calibration_session_id', $sesi->id)->sole()];
    }

    /** Sesi autoklaf kiriman teknisi, Order Number sudah diisi Master Data. */
    private function sesiAntre(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $alat->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.4, 'suhu_akhir' => 24.5,
                'kelembaban_awal' => 55, 'kelembaban_akhir' => 56,
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
                    'uut_setting' => 0.112, 'satuan' => 'MPa', 'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->denganOrderNumber($id);

        return CalibrationSession::findOrFail($id);
    }

    private function hashInput(CalibrationSession $sesi): string
    {
        $baris = DB::table('calibration_sessions')->where('id', $sesi->id)->first(['hasil_autoclave', 'input_asli']);

        return hash('sha256', json_encode([
            $baris->hasil_autoclave,
            $baris->input_asli,
            RawMeasurement::where('calibration_session_id', $sesi->id)->orderBy('id')->get()->toArray(),
            DB::table('uncertainty_calculations')->where('calibration_session_id', $sesi->id)->orderBy('id')->get()->toArray(),
        ]));
    }
}
