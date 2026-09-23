<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder tidak boleh memundurkan sesi yang sudah disetujui.
 *
 * ## Kejadian nyatanya, bukan kekhawatiran teoretis
 *
 * Di produksi, 18 Sep 2026 pukul 12:55–12:56, enam sesi contoh mundur sendiri
 * dari `disetujui` ke `menunggu_approval`. Yang melakukannya `db:seed`: 26
 * seeder menanam sesinya lewat
 * `CalibrationSession::updateOrCreate(['organization_id','nomor_sesi'], […
 * 'status' => STATUS_MENUNGGU_APPROVAL …])`, dan pola itu menimpa baris yang
 * sudah maju persis seperti baris yang belum disentuh.
 *
 * Akibatnya berlapis dan nol error:
 *
 * - `reviewed_by`/`reviewed_at` tidak ikut ditulis seeder, jadi barisnya
 *   tertinggal dalam keadaan mustahil: pernah ditinjau, statusnya menunggu
 *   ditinjau.
 * - `SapuSertifikatTertunda` hanya menyapu sesi `disetujui`, jadi keenam
 *   sertifikatnya berhenti di `menunggu_generate` selamanya — sambil memegang
 *   nomor resmi `CAL/2026/09/0011`–`0017` yang tidak pernah terbit.
 *
 * Deret nomor sertifikat itu yang diperiksa asesor. Karena itu penjagaannya di
 * model, bukan di 26 seeder yang bisa lupa satu per satu.
 */
class SesiDisetujuiTidakBisaDimundurkanTest extends TestCase
{
    use RefreshDatabase;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);

        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'equipment_category_id' => $kategori->id,
        ]);

        $teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create(['organization_id' => $org->id])->id,
            'teknisi_id' => $teknisi->id,
            'nomor_sesi' => '0513-CAL-1124',
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $teknisi->id,
            'reviewed_at' => now()->subDay(),
        ]);
    }

    /** Sertifikat yang sudah memegang nomor resmi tapi PDF-nya belum jadi. */
    private function sertifikatTertunda(string $nomor = 'CAL/2026/09/0011'): Certificate
    {
        return Certificate::factory()->menungguGenerate()->create([
            'organization_id' => $this->sesi->organization_id,
            'calibration_session_id' => $this->sesi->id,
            'issued_by' => $this->sesi->reviewed_by,
            'nomor' => $nomor,
        ]);
    }

    /** Persis bentuk penulisan yang dipakai 26 seeder. */
    public function test_seeder_tidak_bisa_memundurkan_status(): void
    {
        $this->sertifikatTertunda();

        CalibrationSession::updateOrCreate(
            ['organization_id' => $this->sesi->organization_id, 'nomor_sesi' => '0513-CAL-1124'],
            [
                'equipment_id' => $this->sesi->equipment_id,
                'standard_id' => $this->sesi->standard_id,
                'teknisi_id' => $this->sesi->teknisi_id,
                'tanggal_kalibrasi' => now()->subWeek()->toDateString(),
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ],
        );

        $this->assertSame(
            CalibrationSession::STATUS_DISETUJUI,
            $this->sesi->fresh()->status,
            'Sesi yang sudah disetujui ikut mundur waktu seeder dijalankan ulang.',
        );
    }

    /**
     * Atribut LAIN di penulisan yang sama tetap tersimpan.
     *
     * Kalau penjagaannya membatalkan seluruh penulisan, `db:seed` berhenti
     * separuh jadi — mengganti satu masalah dengan yang lebih berantakan.
     */
    public function test_kolom_lain_tetap_ikut_tersimpan(): void
    {
        $this->sertifikatTertunda();

        $this->sesi->update([
            'status' => CalibrationSession::STATUS_DRAFT,
            'nomor_order' => '2411.50.I',
        ]);

        $segar = $this->sesi->fresh();

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $segar->status);
        $this->assertSame('2411.50.I', $segar->nomor_order);
    }

    /**
     * Sertifikatnya tetap bisa dipulihkan penyapu.
     *
     * Ini inti kerugiannya dulu: begitu sesinya mundur, `SapuSertifikatTertunda`
     * berhenti mengenalinya dan sertifikatnya tidak punya siapa pun lagi yang
     * akan menyelesaikannya.
     */
    public function test_sertifikat_tertunda_tetap_kelihatan_penyapu(): void
    {
        $this->sertifikatTertunda();

        CalibrationSession::updateOrCreate(
            ['organization_id' => $this->sesi->organization_id, 'nomor_sesi' => '0513-CAL-1124'],
            ['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL],
        );

        $terlihat = Certificate::query()
            ->where('status', Certificate::STATUS_MENUNGGU_GENERATE)
            ->whereNull('pdf_path')
            ->whereExists(fn ($q) => $q->select('id')
                ->from('calibration_sessions')
                ->whereColumn('calibration_sessions.id', 'certificates.calibration_session_id')
                ->where('calibration_sessions.status', CalibrationSession::STATUS_DISETUJUI))
            ->exists();

        $this->assertTrue($terlihat, 'Sertifikat tertunda hilang dari jangkauan penyapu.');
    }

    /**
     * Sesi disetujui yang BELUM punya sertifikat tetap boleh dikoreksi seeder.
     *
     * Ini batas yang disengaja, dan pernah dilanggar sekali: penjagaan versi
     * pertama menahan SEMUA penurunan status, dan itu memecahkan hal yang sah —
     * di dalam satu kali `db:seed`, seeder menulis ulang barisnya sendiri, ada
     * yang lahir `disetujui` lalu dikoreksi jadi `draft` oleh penulisan
     * berikutnya. `BekalAlatBaruTest` jadi tidak menemukan satu pun sesi draft
     * lalu 422 — nol hubungannya dengan sertifikat.
     *
     * Kerugian yang dijaga memang butuh sertifikatnya ada: tanpa sertifikat,
     * penurunan status tidak menelantarkan nomor resmi siapa pun.
     */
    public function test_sesi_tanpa_sertifikat_masih_boleh_dikoreksi_seeder(): void
    {
        CalibrationSession::updateOrCreate(
            ['organization_id' => $this->sesi->organization_id, 'nomor_sesi' => '0513-CAL-1124'],
            ['status' => CalibrationSession::STATUS_DRAFT],
        );

        $this->assertSame(
            CalibrationSession::STATUS_DRAFT,
            $this->sesi->fresh()->status,
            'Penjagaannya kelewat lebar — seeder tidak bisa membetulkan barisnya sendiri.',
        );
    }

    /** Status yang belum disetujui tetap bebas bergerak — penjagaannya sempit. */
    public function test_status_lain_tidak_ikut_terkunci(): void
    {
        $this->sesi->forceFill(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL])->saveQuietly();
        $this->sesi->refresh();

        $this->sesi->update(['status' => CalibrationSession::STATUS_PERLU_REVISI]);

        $this->assertSame(CalibrationSession::STATUS_PERLU_REVISI, $this->sesi->fresh()->status);
    }
}
