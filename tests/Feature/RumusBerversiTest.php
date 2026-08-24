<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\ProfilGenerik;
use App\Services\RumusKalibrasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rumus kalibrasi berversi (arsitektur-desktop-database.md Keputusan 5).
 *
 * Yang paling dijaga di sini BUKAN "rumusnya bisa diubah", tapi:
 * **hasil hitung nyatet versi rumus yang dipakainya, dan pertanyaan "sesi tanggal
 * X dihitung pakai aturan mana" selalu punya SATU jawaban.**
 *
 * Tanpa itu, "rumus bisa diubah" justru bikin seluruh riwayat kalibrasi nggak bisa
 * dipertanggungjawabkan — nggak ada cara tahu sertifikat mana dihitung pakai aturan
 * yang mana.
 */
class RumusBerversiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function rumus(): RumusKalibrasi
    {
        return app(RumusKalibrasi::class);
    }

    private function kirimSesi(string $tanggal = '2026-07-20'): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => $tanggal,
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------- versi 1

    /**
     * Versi 1 dicatat `sumber = kode`, bukan `database`.
     *
     * Rumus yang jalan sekarang MEMANG ada di kode (`GumCalculator`). Nyatetnya
     * sebagai "dari database" bikin riwayatnya bohong sejak baris pertama.
     */
    public function test_versi_satu_dibikin_otomatis_dan_sumbernya_kode(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $versi = $formula->versiAktif();

        $this->assertSame(Formula::KODE_GUM_PH, $formula->kode);
        $this->assertSame(1, $versi->nomor_versi);
        $this->assertSame(FormulaVersion::SUMBER_KODE, $versi->sumber);
        $this->assertSame(FormulaVersion::STATUS_AKTIF, $versi->status);
        $this->assertNull($versi->ekspresi, 'Versi `kode` nggak punya ekspresi.');
        $this->assertSame(2, $versi->parameter['faktor_cakupan_k']);
    }

    /**
     * `effective_from` versi 1 dipatok jauh ke belakang.
     *
     * Kalau dipatok hari ini, sesi LAMA yang di-revisi nggak nemu versi yang
     * berlaku di tanggalnya dan bakal null — padahal aturan yang dipakai buat sesi
     * itu ya aturan yang sama.
     */
    public function test_versi_satu_berlaku_mundur_biar_sesi_lama_kestempel(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);

        $this->assertNotNull(
            $formula->versiPadaTanggal('2024-05-26'),
            'Sesi tahun 2024 harus tetap nemu versi yang berlaku.',
        );
    }

    public function test_dipanggil_dua_kali_nggak_bikin_dobel(): void
    {
        $a = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $b = $this->rumus()->formulaGumPh($this->admin->organization_id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Formula::count());
        $this->assertSame(1, FormulaVersion::count());
    }

    // -------------------------------------------------- stempel di hasil hitung

    /** INTI Keputusan 5: hasil hitung nyatet versi rumus yang dipakainya. */
    public function test_hasil_hitung_distempel_versi_rumus(): void
    {
        $sesi = $this->kirimSesi();

        $hitungan = $sesi->uncertaintyCalculations;

        $this->assertGreaterThan(0, $hitungan->count());
        foreach ($hitungan as $h) {
            $this->assertNotNull($h->formula_version_id, 'Tiap hasil hitung harus punya versi rumus.');
            $this->assertSame(1, $h->formulaVersion->nomor_versi);
        }
    }

    /**
     * Distempel versi yang berlaku di TANGGAL KALIBRASI, bukan "yang aktif
     * sekarang".
     *
     * Ini bedanya bikin atau nggak: sesi tanggal 26 Mei yang di-revisi hari ini
     * harus tetap kestempel versi yang berlaku 26 Mei. Kalau pakai "yang aktif
     * sekarang", angkanya dihitung aturan lama tapi kecatat aturan baru — itu lebih
     * menyesatkan daripada nggak distempel sama sekali.
     */
    public function test_distempel_versi_yang_berlaku_di_tanggal_kalibrasi(): void
    {
        // Alat di test ini jangka sorong/mikrometer di kategori `panjang`, dan
        // `nama_alat_kemampuan`-nya null — alat GENERIK, bukan pH.
        //
        // Dulu sesinya distempel `gum-ph` cuma karena pH kebetulan profil
        // default registry, jadi jejak auditnya ngaku sesi jangka sorong
        // dihitung pakai aturan pH. Sejak ProfilGenerik ada, stempelnya
        // `gum-generik` — dan yang diadu di sini harus rumus yang BENERAN
        // dipakai, bukan yang dulu keliru terpakai.
        $formula = $this->rumus()->formulaUntukProfil(
            $this->admin->organization_id,
            new ProfilGenerik,
        );
        $v1 = $formula->versiAktif();

        // Versi 2 mulai berlaku 1 Juli 2026; versi 1 ditutup 30 Juni.
        $v1->update(['effective_until' => '2026-06-30']);
        $v2 = FormulaVersion::factory()->create([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'effective_from' => '2026-07-01',
        ]);

        // Sesi tanggal 20 JUNI → harus dapat versi 1, walaupun versi 2 yang aktif
        // sekarang.
        $sesiJuni = $this->kirimSesi('2026-06-20');
        $this->assertSame(
            $v1->id,
            $sesiJuni->uncertaintyCalculations->first()->formula_version_id,
            'Sesi Juni harus kestempel versi yang berlaku Juni.',
        );

        $sesiJuli = $this->kirimSesi('2026-07-20');
        $this->assertSame($v2->id, $sesiJuli->uncertaintyCalculations->first()->formula_version_id);
    }

    // --------------------------------------------------- rentang nggak tumpang tindih

    /**
     * Rentang berlaku dua versi aktif NGGAK BOLEH tumpang tindih.
     *
     * Kalau tumpang tindih, buat satu tanggal ada dua versi yang sah-sah aja — dan
     * pertanyaan "sesi ini dihitung pakai versi mana" jadi nggak punya jawaban.
     */
    public function test_rentang_tumpang_tindih_ketangkep(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        // Versi 1: 2000-01-01 s/d selamanya.

        $bentrok = new FormulaVersion([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'sumber' => FormulaVersion::SUMBER_KODE,
            'status' => FormulaVersion::STATUS_AKTIF,
            'effective_from' => '2026-07-01',
            'effective_until' => null,
        ]);

        $this->assertNotNull($bentrok->pesanBentrok());
        $this->assertStringContainsString('nabrak versi 1', $bentrok->pesanBentrok());
    }

    /** Rentang yang nggak nabrak lolos. */
    public function test_rentang_yang_nggak_nabrak_lolos(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $formula->versiAktif()->update(['effective_until' => '2026-06-30']);

        $aman = new FormulaVersion([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'sumber' => FormulaVersion::SUMBER_KODE,
            'status' => FormulaVersion::STATUS_AKTIF,
            'effective_from' => '2026-07-01',
            'effective_until' => null,
        ]);

        $this->assertNull($aman->pesanBentrok());
    }

    /** `draft` nggak dihitung nabrak — admin boleh nyiapin beberapa calon. */
    public function test_draft_nggak_dihitung_nabrak(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);

        FormulaVersion::factory()->create([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'status' => FormulaVersion::STATUS_DRAFT,
            'effective_from' => '2000-01-01',
        ]);

        $draftLain = new FormulaVersion([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 3,
            'sumber' => FormulaVersion::SUMBER_KODE,
            'status' => FormulaVersion::STATUS_DRAFT,
            'effective_from' => '2000-01-01',
        ]);

        // Draft baru cuma nabrak kalau dia sendiri aktif — di sini dia draft, dan
        // yang dibandingin cuma versi AKTIF (yaitu versi 1).
        $draftLain->status = FormulaVersion::STATUS_AKTIF;
        $this->assertNotNull($draftLain->pesanBentrok(), 'Versi 1 aktif dari 2000 — harus nabrak.');
    }

    // -------------------------------------------------------------- endpoint

    public function test_daftar_rumus_bawa_versi_berlaku(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/formulas')->assertOk();

        $this->assertSame(Formula::KODE_GUM_PH, $res->json('data.0.kode'));
        $this->assertSame(1, $res->json('data.0.versi_berlaku.nomor_versi'));
        $this->assertSame('kode', $res->json('data.0.versi_berlaku.sumber'));
        // Tanggal POLOS, konsisten sama field `date` lain.
        $this->assertSame('2000-01-01', $res->json('data.0.versi_berlaku.berlaku_dari'));
        $this->assertNull($res->json('data.0.versi_berlaku.berlaku_sampai'));
    }

    public function test_teknisi_dan_viewer_ditolak(): void
    {
        $this->actingAs($this->teknisi)->getJson('/api/formulas')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]))
            ->getJson('/api/formulas')->assertForbidden();
    }

    public function test_versi_pada_tanggal(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $formula->versiAktif()->update(['effective_until' => '2026-06-30']);
        FormulaVersion::factory()->create([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'effective_from' => '2026-07-01',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/formulas/{$formula->id}/versi-berlaku?tanggal=2026-06-15")
            ->assertOk()
            ->assertJsonPath('data.nomor_versi', 1);

        $this->actingAs($this->admin)
            ->getJson("/api/formulas/{$formula->id}/versi-berlaku?tanggal=2026-07-15")
            ->assertOk()
            ->assertJsonPath('data.nomor_versi', 2);
    }

    /**
     * Terbitin versi baru = bikin versinya DAN tutup rentang versi sebelumnya,
     * dalam satu operasi.
     */
    public function test_terbitin_versi_baru_nutup_rentang_versi_sebelumnya(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $v1 = $formula->versiAktif();

        $this->actingAs($this->admin)
            ->postJson("/api/formulas/{$formula->id}/versions", [
                'sumber' => 'kode',
                'berlaku_dari' => '2026-08-01',
                'parameter' => ['faktor_cakupan_k' => 2, 'catatan_revisi' => 'ikut IK Rev.7'],
                'langsung_aktif' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.nomor_versi', 2)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.berlaku_dari', '2026-08-01');

        // Versi 1 ditutup SEHARI SEBELUM versi 2 mulai — kalau di hari yang sama,
        // tanggal itu punya dua versi aktif.
        $this->assertSame('2026-07-31', $v1->fresh()->effective_until->toDateString());
    }

    public function test_versi_baru_default_jadi_draft(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $v1 = $formula->versiAktif();

        $this->actingAs($this->admin)
            ->postJson("/api/formulas/{$formula->id}/versions", [
                'sumber' => 'kode',
                'berlaku_dari' => '2026-08-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        // Draft nggak nutup rentang versi sebelumnya.
        $this->assertNull($v1->fresh()->effective_until);
    }

    /**
     * `sumber: database` ditolak selama evaluator ekspresinya belum ada.
     *
     * Diterima lalu diam-diam nggak ngaruh itu lebih berbahaya: riwayatnya bakal
     * nulis "dihitung dari database" padahal angkanya tetap dari kode.
     */
    public function test_sumber_database_ditolak_selama_evaluator_belum_ada(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);

        $this->actingAs($this->admin)
            ->postJson("/api/formulas/{$formula->id}/versions", [
                'sumber' => 'database',
                'berlaku_dari' => '2026-08-01',
                'ekspresi' => ['u95' => 'k * uc'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    }

    public function test_sumber_ngawur_ditolak(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);

        $this->actingAs($this->admin)
            ->postJson("/api/formulas/{$formula->id}/versions", [
                'sumber' => 'sihir',
                'berlaku_dari' => '2026-08-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    }

    /** Versi yang UDAH KEPAKAI nggak bisa diarsipin. */
    public function test_versi_yang_udah_kepakai_nggak_bisa_diarsipin(): void
    {
        $sesi = $this->kirimSesi();
        $versiId = $sesi->uncertaintyCalculations->first()->formula_version_id;

        $this->actingAs($this->admin)
            ->patchJson("/api/formula-versions/{$versiId}", ['status' => 'arsip'])
            ->assertStatus(422);

        $this->assertSame(
            FormulaVersion::STATUS_AKTIF,
            FormulaVersion::find($versiId)->status,
        );
    }

    /** Draft yang belum kepakai boleh diarsipin. */
    public function test_draft_yang_belum_kepakai_boleh_diarsipin(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $draft = FormulaVersion::factory()->create([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'status' => FormulaVersion::STATUS_DRAFT,
            'effective_from' => '2026-08-01',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/formula-versions/{$draft->id}", ['status' => 'arsip'])
            ->assertOk()
            ->assertJsonPath('data.status', 'arsip');
    }

    /** Aktifin draft yang rentangnya nabrak → ditolak. */
    public function test_aktifin_draft_yang_nabrak_ditolak(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        $draft = FormulaVersion::factory()->create([
            'formula_id' => $formula->id,
            'organization_id' => $formula->organization_id,
            'nomor_versi' => 2,
            'status' => FormulaVersion::STATUS_DRAFT,
            // Nabrak versi 1 yang aktif dari 2000-01-01 tanpa batas.
            'effective_from' => '2026-08-01',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/formula-versions/{$draft->id}", ['status' => 'aktif'])
            ->assertStatus(422);

        $this->assertSame(FormulaVersion::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_rumus_organisasi_lain_dapat_404(): void
    {
        $orgLain = Organization::factory()->create();
        $formulaLain = Formula::factory()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($this->admin)
            ->getJson("/api/formulas/{$formulaLain->id}/versions")
            ->assertNotFound();
    }

    // ------------------------------------------------------------- audit

    /**
     * Perubahan versi rumus WAJIB kecatat di `audit_logs` — Keputusan 5 minta itu
     * eksplisit.
     *
     * Ini yang bikin dua fitur itu nyambung: rumus boleh diubah, tapi perubahannya
     * ninggalin jejak siapa & kapan.
     */
    public function test_perubahan_versi_rumus_kecatat_di_audit_log(): void
    {
        $formula = $this->rumus()->formulaGumPh($this->admin->organization_id);
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->postJson("/api/formulas/{$formula->id}/versions", [
                'sumber' => 'kode',
                'berlaku_dari' => '2026-08-01',
                'langsung_aktif' => true,
            ])
            ->assertCreated();

        $log = AuditLog::where('entity_type', 'formula_versions')->get();

        $this->assertGreaterThan(0, $log->count(), 'Bikin versi rumus harus kecatat.');
        $this->assertContains(
            $this->admin->id,
            $log->pluck('changed_by')->all(),
            'Pelakunya harus kecatat.',
        );
    }
}
