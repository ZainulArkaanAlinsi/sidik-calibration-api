<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * `studio:impor-workbook --csv` (Studio S1b): deterministik, idempoten per
 * sha256, peta kode_profil, `--kering` tanpa tulis, dan isi DATABASE /
 * SERTIFIKAT TERSIMPAN apa adanya dengan tanda tautan (K-49-12).
 */
class StudioImporWorkbookCsvTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private string $akar;

    private Organization $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanFolderUji();
        $this->akar = $this->buatAkarCsv();
        $this->lab = Organization::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_impor_csv_menulis_workbook_lembar_dan_peta_profil(): void
    {
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--oleh' => $this->olehLab()])->assertSuccessful();

        $this->assertSame(2, WorkbookMaster::query()->count());

        $micro = WorkbookMaster::query()->where('nama', 'Master_Olah_Data_Micrometer_0-25mm')->sole();
        $this->assertSame($this->lab->id, $micro->organization_id);
        $this->assertSame('csv', $micro->sumber);
        $this->assertSame('micrometer', $micro->kode_profil);
        $this->assertNull($micro->keluarga);
        $this->assertSame(5, $micro->jumlah_lembar);
        $this->assertFalse($micro->berkasAsliTersimpan());
        $this->assertStringContainsString('Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm', (string) $micro->catatan);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $micro->sha256);

        $vol = WorkbookMaster::query()->where('nama', 'Fixed_Volumetric_Glassware_2026')->sole();
        $this->assertNull($vol->kode_profil);
        $this->assertSame('volumetric_fixed', $vol->keluarga);

        $lembar = $vol->lembar()->orderBy('urutan')->get();
        $this->assertSame(['INPUT_DATA', 'DATABASE', 'SERTIFIKAT (2)', 'SERTIFIKAT KALIBRATOR'], $lembar->pluck('nama')->all());
        $this->assertSame([false, false, false, false], $lembar->pluck('dikecualikan')->all());
        $this->assertSame([null, 'customers', 'certificates', null], $lembar->pluck('tautan_tabel')->all());

        $perhitungan = $micro->lembar()->where('nama', 'PERHITUNGAN')->sole();
        $this->assertSame(2, $perhitungan->jumlah_rumus);
        $this->assertSame('=B2*2', collect($perhitungan->daftarSel())->firstWhere('a', 'C2')['r']);

        // Diaudit: pembuatan workbook tercatat di lab pemiliknya.
        $this->assertTrue(AuditLog::query()
            ->where('organization_id', $this->lab->id)
            ->where('entity_type', 'workbook_master')
            ->where('entity_id', $micro->id)
            ->exists());
    }

    public function test_idempoten_dan_sha256_stabil(): void
    {
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--oleh' => $this->olehLab()])->assertSuccessful();
        $sha = WorkbookMaster::query()->orderBy('nama')->pluck('sha256')->all();
        $jumlahLembar = WorkbookMasterLembar::query()->count();
        $sel = WorkbookMasterLembar::query()->orderBy('id')->pluck('sel')->all();

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--oleh' => $this->olehLab()])
            ->expectsOutputToContain('sudah_ada 2')
            ->assertSuccessful();

        $this->assertSame($sha, WorkbookMaster::query()->orderBy('nama')->pluck('sha256')->all());
        $this->assertSame($jumlahLembar, WorkbookMasterLembar::query()->count());
        $this->assertSame($sel, WorkbookMasterLembar::query()->orderBy('id')->pluck('sel')->all());

        // Hapus lalu impor ulang dari berkas yang sama → sha256 identik (deterministik).
        WorkbookMaster::query()->delete();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--oleh' => $this->olehLab()])->assertSuccessful();
        $this->assertSame($sha, WorkbookMaster::query()->orderBy('nama')->pluck('sha256')->all());
    }

    /**
     * K-49-12 (membalik kanari K-49-08): isi DATABASE/SERTIFIKAT TERSIMPAN dan
     * terbaca lewat model, lembarnya tidak dikecualikan, hanya bertautan.
     * Keluaran perintah tetap ringkasan (tidak mencetak isi sel).
     */
    public function test_isi_database_dan_sertifikat_tersimpan_dan_terbaca(): void
    {
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--oleh' => $this->olehLab()])
            ->doesntExpectOutputToContain(self::KANARI_DATABASE)
            ->assertSuccessful();

        $this->assertSame(0, WorkbookMasterLembar::query()->where('dikecualikan', true)->count());

        $bertautan = WorkbookMasterLembar::query()->whereNotNull('tautan_tabel')->orderBy('id')->get();
        $this->assertSame(4, $bertautan->count());

        foreach ($bertautan as $l) {
            $kanari = $l->tautan_tabel === 'customers' ? self::KANARI_DATABASE : self::KANARI_SERTIFIKAT;
            $this->assertContains($kanari, array_column($l->daftarSel(), 's'), $l->nama);
            $this->assertSame(count($l->daftarSel()), $l->jumlah_sel, $l->nama);
            $this->assertNull($l->alasan_dikecualikan, $l->nama);
        }
    }

    public function test_kering_tidak_menulis_apa_pun(): void
    {
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--kering' => true])
            ->expectsOutputToContain('KERING')
            ->expectsOutputToContain('kering 2')
            ->assertSuccessful();

        $this->assertSame(0, WorkbookMaster::query()->count());
        $this->assertSame(0, WorkbookMasterLembar::query()->count());
    }

    public function test_organisasi_wajib_bila_lebih_dari_satu(): void
    {
        $lab2 = Organization::factory()->create();

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar])->assertFailed();
        $this->assertSame(0, WorkbookMaster::query()->count());

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $lab2->id, '--oleh' => $this->olehLab($lab2)])
            ->assertSuccessful();
        $this->assertSame([$lab2->id], WorkbookMaster::query()->distinct()->pluck('organization_id')->all());

        // Isi yang sama di lab lain = baris sendiri (unik per organisasi).
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id, '--oleh' => $this->olehLab($this->lab)])
            ->assertSuccessful();
        $this->assertSame(4, WorkbookMaster::query()->count());
    }

    /**
     * `--ganti` (sesudah FORMAT kanonik naik): hanya baris csv lama di lab yang
     * sama dengan nama sama yang digantikan — TIDAK dihapus, jadi riwayat
     * penggantinya (ADR-49-07, 22 Okt 2026); xlsm bernama sama dan lab
     * lain tidak tersentuh. `--kering --ganti` hanya melaporkan.
     */
    public function test_ganti_hanya_mengganti_csv_lama_bernama_sama_di_lab_sendiri(): void
    {
        $lab2 = Organization::factory()->create();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id, '--oleh' => $this->olehLab($this->lab)])->assertSuccessful();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $lab2->id, '--oleh' => $this->olehLab($lab2)])->assertSuccessful();

        $nama = 'Master_Olah_Data_Micrometer_0-25mm';
        $lama = WorkbookMaster::query()->where('organization_id', $this->lab->id)->where('nama', $nama)->sole();
        $shaBaru = $lama->sha256;
        $lembarLama = $lama->lembar()->pluck('id')->all();

        // Tiru baris format lama: sha berbeda untuk CSV yang sama.
        DB::table('workbook_master')->where('id', $lama->id)->update(['sha256' => str_repeat('a', 64)]);
        $xlsm = WorkbookMaster::query()->create([
            'organization_id' => $this->lab->id, 'nama' => $nama, 'sumber' => WorkbookMaster::SUMBER_XLSM,
            'sha256' => str_repeat('b', 64), 'ukuran' => 1, 'jumlah_lembar' => 0,
        ]);
        $milikLab2 = WorkbookMaster::query()->where('organization_id', $lab2->id)->where('nama', $nama)->sole();

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id, '--ganti' => true, '--kering' => true])
            ->expectsOutputToContain('baris lama diganti: 1')
            ->assertSuccessful();
        $this->assertTrue(WorkbookMaster::query()->whereKey($lama->id)->exists(), 'kering tidak boleh menghapus');

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id, '--ganti' => true, '--oleh' => $this->olehLab($this->lab)])
            ->expectsOutputToContain('(diganti 1, sudah_ada 1)')
            ->assertSuccessful();

        $pengganti = WorkbookMaster::query()->where('organization_id', $this->lab->id)->where('nama', $nama)->where('sumber', 'csv')->whereNotNull('sha256_hidup')->sole();
        $riwayat = WorkbookMaster::query()->findOrFail($lama->id);
        $this->assertSame([null, $pengganti->id, null], [$riwayat->sha256_hidup, $riwayat->digantikan_oleh_id, $riwayat->getRawOriginal('aktif')]);
        $this->assertSame(count($lembarLama), WorkbookMasterLembar::query()->whereIn('id', $lembarLama)->count(), 'lembar riwayat tidak dihapus');
        $this->assertSame($shaBaru, $pengganti->sha256);
        $this->assertSame(5, $pengganti->lembar()->count());
        $this->assertStringContainsString("Menggantikan impor csv lama #{$lama->id}", (string) $pengganti->catatan);

        $this->assertTrue(WorkbookMaster::query()->whereKey($xlsm->id)->exists(), 'xlsm bernama sama tidak boleh tersentuh');
        $this->assertTrue(WorkbookMaster::query()->whereKey($milikLab2->id)->exists(), 'lab lain tidak boleh tersentuh');

        $this->assertTrue(AuditLog::query()
            ->where('organization_id', $this->lab->id)
            ->where('entity_type', 'workbook_master')
            ->where('entity_id', $lama->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->exists());
        $this->assertFalse(AuditLog::query()->where('entity_type', 'workbook_master')->where('action', AuditLog::ACTION_DIHAPUS)->exists(), '--ganti tidak menghapus apa pun');
    }

    /** Tinjau S1b #6: `--oleh` (id/email) mengisi `diimpor_oleh` dan pelaku audit; lab lain & pelanggan ditolak. */
    public function test_oleh_mengisi_diimpor_oleh_dan_pelaku_audit(): void
    {
        $lab2 = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);
        $orangLain = User::factory()->create(['organization_id' => $lab2->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);
        $pelanggan = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_PELANGGAN, 'status' => User::STATUS_AKTIF]);
        $tujuan = ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id];

        foreach ([$orangLain->email, (string) $pelanggan->id, 'tidak-ada@contoh.test'] as $oleh) {
            $this->artisan('studio:impor-workbook', $tujuan + ['--oleh' => $oleh])->assertFailed();
        }

        // Tinjau C-3 #2: tanpa --oleh impor yang menulis DITOLAK (dulu: lolos dengan pelaku kosong).
        $this->artisan('studio:impor-workbook', $tujuan)->expectsOutputToContain('--oleh wajib')->assertFailed();
        $this->artisan('studio:impor-workbook', $tujuan + ['--kering' => true])->assertSuccessful();

        $this->assertSame(0, WorkbookMaster::query()->count());

        $this->artisan('studio:impor-workbook', $tujuan + ['--oleh' => $admin->email])->assertSuccessful();

        $this->assertSame([$admin->id], WorkbookMaster::query()->distinct()->pluck('diimpor_oleh')->all());
        $this->assertSame([$admin->id], AuditLog::query()
            ->where('entity_type', 'workbook_master')
            ->where('action', AuditLog::ACTION_DIBIKIN)
            ->distinct()->pluck('changed_by')->all());
    }

    public function test_pilihan_csv_dan_xlsm_saling_eksklusif(): void
    {
        $this->artisan('studio:impor-workbook')->assertFailed();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--xlsm' => ['x.xlsm'], '--akar' => $this->akar])->assertFailed();
    }
}
