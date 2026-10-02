<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\PemisahanWewenang;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pemisahan wewenang di PERSETUJUAN sesi — API dan panel.
 *
 * Temuan B01 & B02 (paket 30 Sep). Keputusan K-30-03 (1 Okt 2026): **blokir,
 * tanpa pengecualian, untuk semua peran.** Orang yang ikut mengisi data sesi —
 * pengisi lembar (`teknisi_id`), admin yang mengoreksi pembacaannya, atau yang
 * mengonfirmasi hasil pindai OCR — tidak boleh juga menyetujuinya. Dulu
 * `approve()` dan tombol Setujui panel tidak memeriksa satu pun, jadi satu admin
 * bisa mengisi lalu menerbitkan sertifikat berlogo akreditasi sendirian.
 *
 * Sesinya autoklaf karena itu satu-satunya bentuk yang lolos
 * `CalibrationValidator` tanpa menanam hasil hitung sendiri (alasan yang sama
 * dengan `GerbangPengesahanTest`).
 */
class PemisahanWewenangPersetujuanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminA;

    private User $adminB;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');
        config()->set('kalibrasi.gerbang_pengesahan', false);

        $this->org = Organization::factory()->create();
        $this->adminA = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->adminB = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'kode_teknisi' => 'RZP',
        ]);
    }

    public function test_pengisi_lembar_tidak_bisa_menyetujui_sesinya_sendiri(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesi($this->adminA);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pemisahan_wewenang')
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_SAMA_DENGAN_TEKNISI);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    public function test_admin_yang_mengoreksi_pembacaan_tidak_bisa_menyetujuinya(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesi($this->teknisi);

        // Bentuk baris persis yang ditulis `catatKoreksiPembacaan()` saat admin
        // mengganti angka teknisi dengan alasan.
        AuditLog::create([
            'organization_id' => $sesi->organization_id,
            'entity_type' => $sesi->getTable(),
            'entity_id' => $sesi->getKey(),
            'action' => AuditLog::ACTION_DIUBAH,
            'old_data' => ['pembacaan' => []],
            'new_data' => ['pembacaan' => []],
            'changed_by' => $this->adminA->id,
            'note' => AuditLog::CATATAN_KOREKSI_PEMBACAAN.'titik 2 salah ketik.',
        ]);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_MENGOREKSI);

        // Admin lain yang tidak ikut mengisi tetap bisa menyetujuinya.
        $this->actingAs($this->adminB, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    public function test_verifikator_hasil_pindai_tidak_bisa_menyetujuinya(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesi($this->teknisi);
        $this->tanamPembacaanPindai($sesi);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertOk()
            ->assertJsonPath('meta.diverifikasi', 1);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('wewenang.temuan.0.kode', PemisahanWewenang::KODE_PENYETUJU_MEMVERIFIKASI_OCR);
    }

    public function test_panel_menolak_pengisi_yang_menyetujui_sesinya_sendiri(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesi($this->adminA);

        Livewire::actingAs($this->adminA)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    public function test_panel_ikut_gerbang_pengesahan(): void
    {
        config()->set('kalibrasi.gerbang_pengesahan', true);
        Queue::fake();
        $sesi = $this->bikinSesi($this->teknisi);

        Livewire::actingAs($this->adminA)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['abaikan_peringatan' => true]);

        // Gerbang nyala → panel berhenti di antrean pengesah, persis seperti
        // API. Dulu panel langsung menerbitkan dan gerbangnya hanya menutup HP.
        $segar = $sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_PENGESAHAN, $segar->status);
        $this->assertSame($this->adminA->id, (int) $segar->diajukan_oleh);
        $this->assertNotNull($segar->diajukan_pada);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    public function test_verifikasi_mencatat_pelaku_dan_ditolak_sesudah_disetujui(): void
    {
        Queue::fake();
        $sesi = $this->bikinSesi($this->teknisi);
        $this->tanamPembacaanPindai($sesi);

        // Temuan B03. Dulu cuma boolean tanpa siapa & kapan — aturan "hasil
        // OCR wajib dikonfirmasi manusia" tidak bisa dibuktikan ke asesor.
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertOk();

        $baris = $sesi->rawMeasurements()->first();
        $this->assertSame($this->teknisi->id, (int) $baris->verified_by);
        $this->assertNotNull($baris->verified_at);

        $sesi->forceFill(['status' => CalibrationSession::STATUS_DISETUJUI])->save();
        $sesi->rawMeasurements()->update(['is_verified' => false]);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertStatus(422);
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

    private function bikinSesi(User $pengisi): CalibrationSession
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

        $id = $this->actingAs($pengisi, 'sanctum')
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
