<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Arsip draf lembar kerja: arsipkan, pulihkan, daftar arsip (paket CRUD
 * 18 Nov 2026, `docs/perintah-frontend-crud-lengkap.md` §3).
 *
 * Inti yang dijaga: HANYA draf; pembuatnya atau Master Data; sesi yang sudah
 * dikirim 422; milik lab lain/teknisi lain 404; nilai mentah TIDAK terhapus;
 * nomor sesi tidak dipakai ulang; tidak ada sertifikat yang tersentuh; alasan
 * muat di `audit_logs.note` (500) dengan huruf multibyte.
 */
class ArsipDrafSesiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $superAdmin;

    private User $teknisi;

    private User $teknisiLain;

    private User $viewer;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Lab pertama = lab bawaan semua factory.
        $this->lab = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $this->teknisiLain = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->lab->id,
            'nama_alat' => 'Mistar Baja Mitutoyo',
            'customer_id' => Customer::factory()->create(['organization_id' => $this->lab->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->lab->id, 'kode' => 'panjang', 'nama' => 'Panjang',
            ])->id,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create(['organization_id' => $this->lab->id]);
    }

    /** @param  array<string, mixed>  $ubah */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'manual',
            // `draft` EKSPLISIT: tanpa ini POST /calibrations mengirim sesinya
            // ke approval (`menunggu_approval`), bukan menyimpan draf.
            'status' => 'draft',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],
            ],
            ...$ubah,
        ];
    }

    /** Draf nyata lewat jalur API yang dipakai HP (pembacaan mentah ikut tersimpan). */
    private function buatDraf(User $teknisi, array $ubah = []): CalibrationSession
    {
        $this->actingAs($teknisi)->postJson('/api/calibrations', $this->payload($ubah))->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    private function sesiStatus(string $status, ?User $teknisi = null): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'organization_id' => $this->lab->id,
            'equipment_id' => $this->alat->id,
            'teknisi_id' => ($teknisi ?? $this->teknisi)->id,
            'status' => $status,
            'keputusan' => null,
        ]);
    }

    private function jejak(string $aksi, int $sesiId): ?AuditLog
    {
        return AuditLog::query()
            ->where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesiId)
            ->where('action', $aksi)
            ->latest('id')
            ->first();
    }

    private function arsipkan(User $akun, CalibrationSession $sesi, ?string $alasan = 'Salah pilih alat, dibuat ulang.')
    {
        return $this->actingAs($akun)->postJson(
            "/api/calibrations/{$sesi->id}/arsipkan",
            $alasan === null ? [] : ['alasan' => $alasan],
        );
    }

    // ───────────────────────────── arsipkan ─────────────────────────────

    public function test_pembuat_draf_mengarsipkannya_nilai_mentah_dan_nomor_tidak_tersentuh(): void
    {
        $sesi = $this->buatDraf($this->teknisi);
        $mentah = RawMeasurement::where('calibration_session_id', $sesi->id)->orderBy('id')->get(['id', 'pembacaan', 'titik_ukur'])->toArray();
        $hitung = UncertaintyCalculation::where('calibration_session_id', $sesi->id)->count();
        $nomor = $sesi->nomor_sesi;
        $sertifikat = Certificate::count();
        $this->assertCount(3, $mentah);

        $this->arsipkan($this->teknisi, $sesi)
            ->assertOk()
            ->assertJsonPath('message', 'Draf diarsipkan.')
            ->assertJsonPath('data.id', $sesi->id)
            ->assertJsonPath('data.nomor_sesi', $nomor)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.diarsipkan', true)
            ->assertJsonPath('data.arsip.alasan', 'Salah pilih alat, dibuat ulang.')
            ->assertJsonPath('data.arsip.oleh.id', $this->teknisi->id)
            ->assertJsonPath('data.arsip.oleh.nama', $this->teknisi->name);

        // Soft delete: barisnya ada, tersaring dari kueri biasa.
        $this->assertSoftDeleted('calibration_sessions', ['id' => $sesi->id]);
        $this->assertNull(CalibrationSession::find($sesi->id));
        $segar = CalibrationSession::withTrashed()->findOrFail($sesi->id);
        $this->assertSame($nomor, $segar->nomor_sesi, 'nomor sesi tidak berubah');
        $this->assertSame('draft', $segar->status);

        // Nilai teknisi TIDAK dihapus (ISO/IEC 17025 7.5.2).
        $this->assertSame(
            $mentah,
            RawMeasurement::where('calibration_session_id', $sesi->id)->orderBy('id')->get(['id', 'pembacaan', 'titik_ukur'])->toArray(),
            'raw_measurements harus utuh',
        );
        $this->assertSame($hitung, UncertaintyCalculation::where('calibration_session_id', $sesi->id)->count());
        $this->assertSame($sertifikat, Certificate::count(), 'tidak ada sertifikat yang berubah');
    }

    public function test_arsip_menulis_satu_baris_audit_lengkap(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($this->teknisi, $sesi, 'Salah pilih alat')->assertOk();

        $audit = $this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id);
        $this->assertNotNull($audit);
        $this->assertSame($this->lab->id, $audit->organization_id);
        $this->assertSame($this->teknisi->id, $audit->changed_by);
        $this->assertSame('Arsipkan draf: Salah pilih alat', $audit->note);
        $this->assertSame('Salah pilih alat', $audit->new_data['alasan']);
        $this->assertNotNull($audit->new_data['deleted_at']);
        $this->assertSame('draft', $audit->old_data['status']);
        $this->assertNull($audit->old_data['deleted_at']);

        // Tidak ada baris `dihapus` kedua dari event model.
        $this->assertNull($this->jejak(AuditLog::ACTION_DIHAPUS, $sesi->id));
        $this->assertSame(1, AuditLog::where('entity_type', 'calibration_sessions')->where('entity_id', $sesi->id)
            ->where('action', AuditLog::ACTION_DIARSIPKAN)->count());
    }

    public function test_draf_terarsip_hilang_dari_daftar_dan_tidak_bisa_dibuka_atau_diubah(): void
    {
        $sesi = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $sesi)->assertOk();

        $this->actingAs($this->teknisi);
        $this->assertNotContains($sesi->id, collect($this->getJson('/api/calibrations')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/calibrations/{$sesi->id}")->assertNotFound();
        $this->putJson("/api/calibrations/{$sesi->id}", $this->payload())->assertNotFound();

        $this->actingAs($this->admin);
        $this->assertNotContains($sesi->id, collect($this->getJson('/api/calibrations')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_master_data_dan_super_admin_boleh_mengarsipkan_draf_teknisi(): void
    {
        $a = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->admin, $a, 'Draf salah input')->assertOk()
            ->assertJsonPath('data.arsip.oleh.id', $this->admin->id);

        $b = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->superAdmin, $b, 'Draf salah input')->assertOk()
            ->assertJsonPath('data.arsip.oleh.id', $this->superAdmin->id);
    }

    public function test_teknisi_tidak_bisa_mengarsipkan_draf_teknisi_lain(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($this->teknisiLain, $sesi)->assertNotFound();

        $this->assertNull(CalibrationSession::findOrFail($sesi->id)->deleted_at);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id));
    }

    public function test_viewer_pelanggan_dan_tanpa_login_ditolak(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($this->viewer, $sesi)->assertForbidden();

        $pelanggan = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_PELANGGAN]);
        Sanctum::actingAs($pelanggan, ['pelanggan']);
        $this->postJson("/api/calibrations/{$sesi->id}/arsipkan", ['alasan' => 'bukan hakmu'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/calibrations/{$sesi->id}/arsipkan", ['alasan' => 'tanpa login'])->assertUnauthorized();

        $this->assertNull(CalibrationSession::findOrFail($sesi->id)->deleted_at);
    }

    /** MUTAN "hanya draf": kalau pemeriksaan status dicabut, test ini yang merah. */
    public function test_sesi_yang_sudah_dikirim_atau_disetujui_ditolak_dengan_kode_bukan_draf(): void
    {
        foreach ([
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            CalibrationSession::STATUS_PERLU_REVISI,
            CalibrationSession::STATUS_DISETUJUI,
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
        ] as $status) {
            $sesi = $this->sesiStatus($status);

            foreach ([$this->teknisi, $this->admin] as $akun) {
                $this->arsipkan($akun, $sesi)
                    ->assertUnprocessable()
                    ->assertJsonPath('kode', 'bukan_draf')
                    ->assertJsonPath('status', $status);
            }

            $this->assertNull(CalibrationSession::withTrashed()->findOrFail($sesi->id)->deleted_at, "status {$status} ikut terarsip");
            $this->assertNull($this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id));
        }
    }

    public function test_sesi_lab_lain_dijawab_404_dan_dicatat_sebagai_akses_lintas_lab(): void
    {
        $adminLain = User::factory()->admin()->create(['organization_id' => $this->labLain->id]);
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($adminLain, $sesi)->assertNotFound();

        $this->assertNull(CalibrationSession::findOrFail($sesi->id)->deleted_at);
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->where('organization_id', $this->labLain->id)->count());

        // Dan sebaliknya: admin lab ini tidak bisa menyentuh draf lab lain.
        $teknisiLain = User::factory()->create(['organization_id' => $this->labLain->id, 'role' => User::ROLE_TEKNISI]);
        $milikLain = CalibrationSession::factory()->create([
            'organization_id' => $this->labLain->id,
            'equipment_id' => Equipment::factory()->create(['organization_id' => $this->labLain->id])->id,
            'teknisi_id' => $teknisiLain->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
        $this->arsipkan($this->admin, $milikLain)->assertNotFound();
        $this->actingAs($this->superAdmin)->postJson("/api/calibrations/{$milikLain->id}/pulihkan", [])->assertNotFound();
        $this->assertNull(CalibrationSession::findOrFail($milikLain->id)->deleted_at);
    }

    public function test_mengarsipkan_dua_kali_idempoten_tanpa_baris_audit_kedua(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($this->teknisi, $sesi, 'Alasan pertama')->assertOk();
        $this->arsipkan($this->teknisi, $sesi, 'Alasan kedua yang tidak dipakai')->assertOk()
            ->assertJsonPath('message', 'Draf sudah diarsipkan.')
            ->assertJsonPath('data.diarsipkan', true)
            ->assertJsonPath('data.arsip.alasan', 'Alasan pertama');

        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_DIARSIPKAN)->where('entity_id', $sesi->id)->count());
    }

    public function test_status_yang_berubah_di_antara_pemeriksaan_dan_tulis_dijawab_409_tanpa_menimpa(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        // Simulasikan kiriman lain yang menang balapan: tepat sesudah kueri
        // KUNCI di dalam transaksi (SELECT kedua atas calibration_sessions;
        // yang pertama binding rute), status digeser ke menunggu_approval.
        $selectKe = 0;
        $sudah = false;
        DB::listen(function ($q) use (&$selectKe, &$sudah, $sesi): void {
            if ($sudah || ! str_starts_with(strtolower($q->sql), 'select') || ! str_contains($q->sql, 'calibration_sessions')) {
                return;
            }

            if (++$selectKe === 2) {
                $sudah = true;
                DB::table('calibration_sessions')->where('id', $sesi->id)->update(['status' => 'menunggu_approval']);
            }
        });

        $this->arsipkan($this->teknisi, $sesi)
            ->assertStatus(409)
            ->assertJsonPath('kode', 'status_berubah');

        $this->assertTrue($sudah, 'simulasi balapan tidak terpicu — test ini hijau palsu');
        $baris = CalibrationSession::withTrashed()->findOrFail($sesi->id);
        $this->assertNull($baris->deleted_at);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id));
    }

    public function test_validasi_alasan_wajib_dan_batas_panjangnya(): void
    {
        $sesi = $this->buatDraf($this->teknisi);

        $this->arsipkan($this->teknisi, $sesi, null)->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->arsipkan($this->teknisi, $sesi, '   ')->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->arsipkan($this->teknisi, $sesi, 'abcd')->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->arsipkan($this->teknisi, $sesi, str_repeat('é', 401))->assertUnprocessable()->assertJsonValidationErrors('alasan');

        $this->assertNull(CalibrationSession::findOrFail($sesi->id)->deleted_at);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id));
    }

    /**
     * Pelajaran CI MySQL 18 Nov 2026: alasan panjang + awalan meledak
     * `Data too long for column 'note'` di MySQL, lolos di SQLite. Tiap lebar
     * byte diuji PERSIS di batasnya (400 karakter), dan panjang `note`
     * ditagih terhadap 500.
     */
    public function test_alasan_400_karakter_multibyte_muat_di_note_audit(): void
    {
        foreach (['é' => 2, '漢' => 3, '😀' => 4] as $huruf => $byte) {
            $sesi = $this->buatDraf($this->teknisi);
            $alasan = str_repeat($huruf, 400);
            $this->assertSame(400 * $byte, strlen($alasan), 'fixture harus benar-benar multibyte');

            $this->arsipkan($this->teknisi, $sesi, $alasan)->assertOk();

            $audit = $this->jejak(AuditLog::ACTION_DIARSIPKAN, $sesi->id);
            $this->assertSame($alasan, $audit->new_data['alasan'], 'alasan LENGKAP tersimpan');
            $this->assertLessThanOrEqual(500, mb_strlen($audit->note), "note ({$byte} byte/huruf) melewati kolom audit_logs.note");
            $this->assertStringStartsWith('Arsipkan draf: ', $audit->note);
        }
    }

    // ───────────────────────────── daftar arsip ─────────────────────────────

    public function test_daftar_arsip_hanya_untuk_master_data_dan_memuat_alasan(): void
    {
        $arsip = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $arsip, 'Salah pilih alat')->assertOk();
        $hidup = $this->buatDraf($this->teknisi);

        $respons = $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=1')->assertOk();
        $ids = collect($respons->json('data'))->pluck('id')->all();

        $this->assertSame([$arsip->id], $ids, 'hanya yang terarsip, dan yang hidup tidak ikut');
        $respons->assertJsonPath('data.0.nomor_sesi', $arsip->nomor_sesi)
            ->assertJsonPath('data.0.status', 'draft')
            ->assertJsonPath('data.0.arsip.alasan', 'Salah pilih alat')
            ->assertJsonPath('data.0.arsip.oleh.nama', $this->teknisi->name)
            ->assertJsonPath('data.0.equipment.id', $this->alat->id)
            ->assertJsonPath('data.0.teknisi.id', $this->teknisi->id)
            ->assertJsonStructure(['data' => [['id', 'nomor_sesi', 'status', 'tanggal_kalibrasi', 'equipment', 'pelanggan', 'teknisi', 'arsip' => ['alasan', 'oleh' => ['id', 'nama'], 'pada']]], 'links', 'meta']);

        $this->actingAs($this->superAdmin)->getJson('/api/calibrations?diarsipkan=1')->assertOk()->assertJsonCount(1, 'data');

        // Daftar biasa tidak pernah memuat yang terarsip, dan bentuknya tidak
        // punya kunci `arsip`.
        $biasa = $this->actingAs($this->admin)->getJson('/api/calibrations')->assertOk();
        $this->assertSame([$hidup->id], collect($biasa->json('data'))->pluck('id')->all());
        $this->assertArrayNotHasKey('arsip', $biasa->json('data.0'));
        $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=0')->assertOk();
    }

    public function test_daftar_arsip_ditolak_untuk_teknisi_dan_viewer(): void
    {
        $arsip = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $arsip)->assertOk();

        foreach ([$this->teknisi, $this->viewer] as $akun) {
            $this->actingAs($akun)->getJson('/api/calibrations?diarsipkan=1')
                ->assertForbidden()
                ->assertJsonPath('kode', 'hanya_admin')
                ->assertJsonMissingPath('data');
        }
    }

    public function test_daftar_arsip_tidak_memuat_arsip_lab_lain_dan_bisa_difilter(): void
    {
        $milikKita = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $milikKita)->assertOk();

        $teknisiLain = User::factory()->create(['organization_id' => $this->labLain->id, 'role' => User::ROLE_TEKNISI]);
        $milikLain = CalibrationSession::factory()->create([
            'organization_id' => $this->labLain->id,
            'equipment_id' => Equipment::factory()->create(['organization_id' => $this->labLain->id])->id,
            'teknisi_id' => $teknisiLain->id,
            'nomor_sesi' => 'BOCOR-ARSIP-LAB2',
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
        CalibrationSession::whereKey($milikLain->id)->delete();

        $respons = $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=1')->assertOk();
        $this->assertSame([$milikKita->id], collect($respons->json('data'))->pluck('id')->all());
        $this->assertStringNotContainsString('BOCOR-ARSIP-LAB2', (string) $respons->getContent());

        $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=1&q=tidak-ada-yang-begini')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=1&per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    // ───────────────────────────── pulihkan ─────────────────────────────

    public function test_master_data_memulihkan_draf_dan_semuanya_kembali_seperti_semula(): void
    {
        $sesi = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $sesi)->assertOk();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/pulihkan", ['alasan' => 'Ternyata masih dipakai'])
            ->assertOk()
            ->assertJsonPath('message', 'Draf dipulihkan.')
            ->assertJsonPath('data.diarsipkan', false)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nomor_sesi', $sesi->nomor_sesi);

        $segar = CalibrationSession::findOrFail($sesi->id);
        $this->assertNull($segar->deleted_at);
        $this->assertSame('draft', $segar->status);
        $this->assertSame(3, RawMeasurement::where('calibration_session_id', $sesi->id)->count());

        $audit = $this->jejak(AuditLog::ACTION_DIPULIHKAN, $sesi->id);
        $this->assertNotNull($audit);
        $this->assertSame($this->admin->id, $audit->changed_by);
        $this->assertSame('Pulihkan draf: Ternyata masih dipakai', $audit->note);
        $this->assertSame('Ternyata masih dipakai', $audit->new_data['alasan']);

        // Teknisi pemiliknya melihatnya lagi dan bisa membukanya.
        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")->assertOk();
        $this->assertContains($sesi->id, collect($this->getJson('/api/calibrations')->json('data'))->pluck('id')->all());
        $this->actingAs($this->admin)->getJson('/api/calibrations?diarsipkan=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pulihkan_tanpa_alasan_boleh_dan_super_admin_ikut_boleh(): void
    {
        $sesi = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $sesi)->assertOk();

        $this->actingAs($this->superAdmin)->postJson("/api/calibrations/{$sesi->id}/pulihkan")->assertOk();
        $this->assertSame('Pulihkan draf', $this->jejak(AuditLog::ACTION_DIPULIHKAN, $sesi->id)->note);
    }

    /** MUTAN gerbang peran: yang boleh memulihkan hanya Master Data/super admin. */
    public function test_teknisi_pembuat_viewer_dan_tanpa_login_tidak_bisa_memulihkan(): void
    {
        $sesi = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $sesi)->assertOk();

        $this->actingAs($this->teknisi)->postJson("/api/calibrations/{$sesi->id}/pulihkan", [])->assertForbidden();
        $this->actingAs($this->teknisiLain)->postJson("/api/calibrations/{$sesi->id}/pulihkan", [])->assertForbidden();
        $this->actingAs($this->viewer)->postJson("/api/calibrations/{$sesi->id}/pulihkan", [])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/calibrations/{$sesi->id}/pulihkan", [])->assertUnauthorized();

        $this->assertNotNull(CalibrationSession::withTrashed()->findOrFail($sesi->id)->deleted_at);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIPULIHKAN, $sesi->id));
    }

    public function test_memulihkan_sesi_yang_tidak_diarsipkan_422_dan_alasan_dibatasi(): void
    {
        $hidup = $this->buatDraf($this->teknisi);

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$hidup->id}/pulihkan", [])
            ->assertUnprocessable()->assertJsonPath('kode', 'tidak_diarsipkan');

        $arsip = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $arsip)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/calibrations/{$arsip->id}/pulihkan", ['alasan' => str_repeat('é', 401)])
            ->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->assertNotNull(CalibrationSession::withTrashed()->findOrFail($arsip->id)->deleted_at);

        foreach (['é', '漢', '😀'] as $huruf) {
            $s = $this->buatDraf($this->teknisi);
            $this->arsipkan($this->teknisi, $s)->assertOk();
            $alasan = str_repeat($huruf, 400);
            $this->actingAs($this->admin)->postJson("/api/calibrations/{$s->id}/pulihkan", ['alasan' => $alasan])->assertOk();
            $this->assertLessThanOrEqual(500, mb_strlen($this->jejak(AuditLog::ACTION_DIPULIHKAN, $s->id)->note));
        }
    }

    // ───────────────────────────── nomor & idempotensi pembuatan ─────────────────────────────

    public function test_nomor_sesi_yang_terarsip_tidak_dipakai_ulang(): void
    {
        $pertama = $this->buatDraf($this->teknisi);
        $this->arsipkan($this->teknisi, $pertama)->assertOk();

        $kedua = $this->buatDraf($this->teknisi);

        $this->assertNotSame($pertama->nomor_sesi, $kedua->nomor_sesi);
        $this->assertSame(
            (int) substr($pertama->nomor_sesi, -4) + 1,
            (int) substr($kedua->nomor_sesi, -4),
            'nomor berikutnya melanjutkan, bukan mengisi lubang',
        );
    }

    public function test_kiriman_ulang_untuk_draf_yang_sudah_diarsipkan_bukan_500(): void
    {
        $idKiriman = 'a1b2c3d4-0000-4000-8000-000000000001';
        $sesi = $this->buatDraf($this->teknisi, ['client_request_id' => $idKiriman]);
        $this->arsipkan($this->admin, $sesi)->assertOk();

        // HP yang respons pertamanya hilang mengirim ulang kiriman yang sama.
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload(['client_request_id' => $idKiriman]))
            ->assertOk()
            ->assertJsonPath('data.id', $sesi->id);

        $this->assertSame(1, CalibrationSession::withTrashed()->where('client_request_id', $idKiriman)->count());
    }

    // ───────────────────────────── izin & throttle ─────────────────────────────

    public function test_kunci_izin_arsip_per_peran(): void
    {
        $boleh = fn (User $u) => $this->actingAs($u)->getJson('/api/me/permissions')->json('data.boleh');

        foreach ([$this->admin, $this->superAdmin] as $akun) {
            $this->assertContains('kalibrasi.arsipkan', $boleh($akun));
            $this->assertContains('kalibrasi.pulihkan', $boleh($akun));
        }
        $this->assertContains('kalibrasi.arsipkan', $boleh($this->teknisi));
        $this->assertNotContains('kalibrasi.pulihkan', $boleh($this->teknisi));
        $this->assertNotContains('kalibrasi.arsipkan', $boleh($this->viewer));
        $this->assertNotContains('kalibrasi.pulihkan', $boleh($this->viewer));
    }

    public function test_rute_arsip_ber_throttle_dan_batasnya_ditegakkan(): void
    {
        foreach (['arsipkan', 'pulihkan'] as $aksi) {
            $rute = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === "api/calibrations/{calibration}/{$aksi}" && in_array('POST', $r->methods(), true));
            $this->assertNotNull($rute);
            $this->assertContains('throttle:sesi-arsip', $rute->gatherMiddleware());
        }

        $sesi = $this->buatDraf($this->teknisi);
        $this->actingAs($this->teknisi);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson("/api/calibrations/{$sesi->id}/arsipkan", [])->assertUnprocessable();
        }
        $this->postJson("/api/calibrations/{$sesi->id}/arsipkan", [])->assertStatus(429);
    }
}
