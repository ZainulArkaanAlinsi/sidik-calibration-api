<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\CalibrationValidator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Chaos review 25 Sep 2026 — terbit sertifikat.
 *
 * Steady state yang dijaga: sesi yang sudah `disetujui` SELALU berujung ke
 * sertifikat `terbit` atau `gagal` yang bisa diterbitkan ulang — tidak pernah
 * berhenti di keadaan tanpa jalan keluar — dan jejaknya menyebut orang yang
 * benar-benar menyetujui.
 *
 * Tiap gangguan di sini disuntikkan, bukan ditunggu terjadi: kegagalan yang
 * cuma muncul sekali sebulan di produksi tidak bisa jadi dasar keyakinan.
 */
class ChaosTerbitSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        // Autoklaf, sama alasannya dengan `ApproveDuaKaliSatuSertifikatTest`:
        // satu-satunya bentuk sesi yang lolos `CalibrationValidator` tanpa
        // menanam titik hasil hitung sendiri.
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($teknisi, 'sanctum')
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

        $this->sesi = CalibrationSession::findOrFail($id);
    }

    /**
     * Gangguan F1-1a: job penerbitan gagal di SEMUA percobaannya sebelum baris
     * sertifikatnya sempat ada.
     *
     * Transaksi pertama `handle()` — yang mengalokasikan nomor — duduk di luar
     * `try`, jadi deadlock atau koneksi putus di sana tidak pernah menulis
     * baris `gagal`. Kalau tiga percobaannya habis dengan cara itu, sesinya
     * `disetujui` tanpa baris sertifikat: penyapu hanya menyapu
     * `menunggu_generate`, dan tombol retry hanya muncul untuk `gagal`.
     *
     * Masa berlaku dipilih admin di sini dengan sengaja: nilai itu hanya hidup
     * di argumen job, jadi pemulihan yang menebak ulang argumennya akan
     * mencetak tanggal yang salah tanpa satu pun error.
     */
    public function test_job_gagal_sebelum_baris_sertifikat_ada_tetap_meninggalkan_jalan_pulih(): void
    {
        Queue::fake();
        $berlaku = now()->addMonths(7)->toDateString();

        $this->actingAs($this->admin)->postJson(
            "/api/calibrations/{$this->sesi->id}/approve",
            ['abaikan_peringatan' => true, 'berlaku_sampai' => $berlaku],
        )->assertOk();

        /** @var GenerateCertificate $job */
        $job = Queue::pushed(GenerateCertificate::class)->first();
        $this->assertNotNull($job, 'Approve tidak mengantrekan penerbitan sama sekali.');

        $gagalkan = true;
        Certificate::creating(function () use (&$gagalkan): void {
            if ($gagalkan) {
                throw new RuntimeException('simulasi: deadlock waktu mengalokasikan nomor');
            }
        });

        $galat = null;

        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException $e) {
            $galat = $e;
        }

        $this->assertNotNull($galat, 'Gangguannya tidak tersuntik — eksperimen tidak sah.');
        $this->assertSame(0, Certificate::where('calibration_session_id', $this->sesi->id)->count());

        // Yang dilakukan worker begitu percobaannya habis.
        $gagalkan = false;
        $job->failed($galat);

        $sertifikat = Certificate::where('calibration_session_id', $this->sesi->id)->first();

        $this->assertNotNull(
            $sertifikat,
            'Sesi disetujui tanpa baris sertifikat sama sekali — tidak ada tombol retry dan penyapu tidak menjangkaunya.',
        );
        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->status);
        $this->assertSame($berlaku, $sertifikat->berlaku_sampai?->toDateString(), 'Masa berlaku pilihan admin hilang.');

        // Jalan pulihnya harus benar-benar jalan, bukan cuma kelihatan.
        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertOk();

        app()->call([Queue::pushed(GenerateCertificate::class)->last(), 'handle']);

        $sertifikat->refresh();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertSame($berlaku, $sertifikat->berlaku_sampai?->toDateString());
        $this->assertSame(1, Certificate::where('calibration_session_id', $this->sesi->id)->count());
    }

    /**
     * Gangguan F1-1b: antrean menolak job-nya (insert ke tabel `jobs` gagal),
     * SESUDAH status sesi di-commit `disetujui`.
     *
     * Disimulasikan dengan koneksi antrean yang tabelnya tidak ada — bentuk
     * kegagalan yang sama dengan database antrean yang sedang tidak bisa
     * ditulisi.
     */
    public function test_antrean_menolak_job_sesi_tidak_ditinggal_tanpa_jalan_pulih(): void
    {
        config([
            'queue.connections.rusak' => [
                'driver' => 'database',
                'table' => 'tabel_antrean_yang_tidak_ada',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.default' => 'rusak',
        ]);

        $respons = $this->actingAs($this->admin)->postJson(
            "/api/calibrations/{$this->sesi->id}/approve",
            ['abaikan_peringatan' => true],
        );

        $this->sesi->refresh();
        $sertifikat = Certificate::where('calibration_session_id', $this->sesi->id)->first();

        $bisaDisetujuiUlang = $this->sesi->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL;
        $bisaDiterbitkanUlang = $sertifikat?->status === Certificate::STATUS_GAGAL;

        $this->assertTrue(
            $bisaDisetujuiUlang || $bisaDiterbitkanUlang,
            "Sesi berstatus `{$this->sesi->status}` tanpa sertifikat yang bisa diterbitkan ulang "
            ."(respons {$respons->status()}).",
        );
        $this->assertNotSame(500, $respons->status(), 'Admin dapat galat 500 generik, bukan status yang jelas.');
    }

    /**
     * Temuan sampingan chaos review: approve lewat API tidak meninggalkan
     * jejak di `audit_logs`.
     *
     * Penjaga balapannya UPDATE bersyarat lewat query builder, dan query
     * builder tidak memicu event model — jadi `Diaudit` tidak pernah tahu. Yang
     * tersisa cuma `reviewed_by`/`reviewed_at` di baris sesi, yang tertimpa
     * lagi kalau sesinya dikembalikan lalu disetujui ulang. Persetujuan lewat
     * panel tercatat; lewat HP tidak.
     */
    public function test_approve_lewat_api_tercatat_di_jejak_audit(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->postJson(
            "/api/calibrations/{$this->sesi->id}/approve",
            ['abaikan_peringatan' => true],
        )->assertOk();

        $jejak = AuditLog::where('entity_type', 'calibration_sessions')
            ->where('entity_id', $this->sesi->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->get()
            ->first(fn (AuditLog $a): bool => ($a->new_data['status'] ?? null) === CalibrationSession::STATUS_DISETUJUI);

        $this->assertNotNull($jejak, 'Persetujuan yang menerbitkan sertifikat tidak meninggalkan jejak audit.');
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $jejak->old_data['status'] ?? null);
        $this->assertSame($this->admin->id, $jejak->changed_by);
        // `assertEquals`: nilainya lewat JSON, dan MySQL/SQLite boleh beda tipe.
        $this->assertEquals($this->admin->id, $jejak->new_data['reviewed_by'] ?? null);
    }

    /** Sesi disetujui + sertifikat `gagal` yang masa berlakunya dipilih admin. */
    private function sertifikatGagal(string $berlaku): Certificate
    {
        $this->sesi->forceFill([
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ])->saveQuietly();

        return Certificate::factory()->gagal()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $this->sesi->id,
            'issued_by' => $this->admin->id,
            'berlaku_sampai' => $berlaku,
        ]);
    }

    private function antreanRusak(): void
    {
        config([
            'queue.connections.rusak' => [
                'driver' => 'database',
                'table' => 'tabel_antrean_yang_tidak_ada',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.default' => 'rusak',
        ]);
    }

    /**
     * Gangguan F1-4: "Terbitkan ulang" di PANEL untuk sertifikat yang masa
     * berlakunya dipilih admin.
     *
     * `updateOrCreate` di `handle()` menulis ULANG `berlaku_sampai` tiap job
     * jalan. Retry API mewariskan nilainya dari baris; retry panel dulu
     * tidak, jadi tanggal pilihan admin ditimpa default organisasi di dokumen
     * terakreditasi — tanpa satu pun error.
     */
    public function test_terbitkan_ulang_di_panel_mempertahankan_masa_berlaku_pilihan_admin(): void
    {
        $berlaku = now()->addMonths(7)->toDateString();
        $sertifikat = $this->sertifikatGagal($berlaku);

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->callAction(TestAction::make('retry')->table($sertifikat));

        $sertifikat->refresh();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status, 'Eksperimen tidak sah: penerbitan ulangnya tidak jalan.');
        $this->assertSame($berlaku, $sertifikat->berlaku_sampai?->toDateString(), 'Masa berlaku pilihan admin ditimpa default.');
    }

    /** Gangguan F1-5: antrean menolak job waktu "Terbitkan ulang" ditekan di panel. */
    public function test_terbitkan_ulang_di_panel_saat_antrean_rusak_tombolnya_tidak_hilang(): void
    {
        $sertifikat = $this->sertifikatGagal(now()->addMonths(7)->toDateString());
        $this->antreanRusak();

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->callAction(TestAction::make('retry')->table($sertifikat));

        $this->assertSame(
            Certificate::STATUS_GAGAL,
            $sertifikat->fresh()->status,
            'Baris berhenti di `menunggu_generate` tanpa job, dan tombol "Terbitkan ulang" hilang.',
        );
    }

    /** Gangguan F1-6: antrean menolak job waktu retry lewat API (mobile). */
    public function test_retry_api_saat_antrean_rusak_dijawab_jelas(): void
    {
        $sertifikat = $this->sertifikatGagal(now()->addMonths(7)->toDateString());
        $this->antreanRusak();

        $respons = $this->actingAs($this->admin)->postJson("/api/certificates/{$sertifikat->id}/retry");

        $this->assertNotSame(500, $respons->status(), 'Admin dapat galat 500 generik, bukan status yang jelas.');
        $respons->assertStatus(503);
        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->fresh()->status);
    }

    /**
     * Gangguan F1-7: antrean menolak job di tengah sapuan
     * `sertifikat:sapu-tertunda`.
     *
     * Satu dispatch yang melempar dulu menghentikan seluruh perintah — baris
     * sesudahnya tidak dicoba, dan scheduler cuma melihat exception.
     */
    public function test_penyapu_saat_antrean_rusak_tidak_berhenti_di_tengah(): void
    {
        $tersangkut = Certificate::factory()->menungguGenerate()->count(2)->create([
            'updated_at' => now()->subHour(),
        ]);
        $this->antreanRusak();

        $this->artisan('sertifikat:sapu-tertunda')
            ->expectsOutputToContain('2 sertifikat gagal didorong ulang')
            ->assertFailed();

        foreach ($tersangkut as $sertifikat) {
            $this->assertSame(Certificate::STATUS_MENUNGGU_GENERATE, $sertifikat->fresh()->status);
        }
    }

    /**
     * Gangguan F1-2: dua admin menekan "Setujui" di panel hampir bersamaan.
     *
     * Admin lain disimulasikan menang tepat selagi validator panel masih
     * memeriksa — pola yang sama dengan
     * `ApproveDuaKaliSatuSertifikatTest::test_permintaan_yang_kalah_balapan_dijawab_409`
     * untuk jalur API.
     */
    public function test_dua_admin_setujui_bersamaan_di_panel_jejaknya_menyebut_yang_menang(): void
    {
        Queue::fake();
        $adminLain = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $sesi = $this->sesi;

        $this->app->extend(CalibrationValidator::class, fn (CalibrationValidator $asli) => new class($asli, $sesi, $adminLain) extends CalibrationValidator
        {
            public function __construct(
                private readonly CalibrationValidator $asli,
                private readonly CalibrationSession $sesi,
                private readonly User $pemenang,
            ) {}

            public function periksa(CalibrationSession $sesi): array
            {
                $hasil = $this->asli->periksa($sesi);

                CalibrationSession::whereKey($this->sesi->id)->update([
                    'status' => CalibrationSession::STATUS_DISETUJUI,
                    'reviewed_by' => $this->pemenang->id,
                    'reviewed_at' => now(),
                ]);

                return $hasil;
            }
        });

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($this->sesi), ['abaikan_peringatan' => true]);

        $this->assertSame(
            $adminLain->id,
            $this->sesi->fresh()->reviewed_by,
            'Jejak persetujuan menyebut admin yang kalah balapan.',
        );
        Queue::assertNotPushed(GenerateCertificate::class);
    }
}
