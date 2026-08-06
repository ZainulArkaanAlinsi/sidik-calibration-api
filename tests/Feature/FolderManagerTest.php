<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Folder Manager (spesifikasi poin 3 & 7): folder kebentuk otomatis per PT,
 * CRUD folder & file, dan penyaringan isi per-role.
 */
class FolderManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Customer $pelanggan;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->pelanggan = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        $this->standar = Standard::factory()->create();
    }

    private function terbitkanSertifikatUntuk(User $teknisi, ?Customer $pelanggan = null): CalibrationSession
    {
        Storage::fake('local');

        $alat = Equipment::factory()->create([
            'customer_id' => ($pelanggan ?? $this->pelanggan)->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi;
    }

    public function test_sertifikat_terbit_otomatis_masuk_folder_pt_per_tahun(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);

        $akar = Folder::whereNull('parent_id')->firstOrFail();
        $this->assertSame('PT Tirta Gracia', $akar->nama);
        $this->assertSame(Folder::TIPE_SISTEM, $akar->tipe);

        $tahun = $akar->children()->firstOrFail();
        $this->assertSame(now()->format('Y'), $tahun->nama);

        // Disaring per `sumber`, bukan `firstOrFail()`: folder tahun sekarang
        // juga nampung lembar kerja, dan lembar kerjanya dibikin LEBIH DULU
        // (waktu dikirim) daripada sertifikatnya (waktu disetujui). Jadi
        // "berkas pertama" bukan lagi sertifikatnya.
        $file = $tahun->files()->where('sumber', FolderFile::SUMBER_SERTIFIKAT)->firstOrFail();
        $this->assertStringStartsWith('Sertifikat-CAL-', $file->nama);

        // Lembar kerjanya ikut diarsip di folder yang sama — sertifikat itu
        // kesimpulan, lembar kerja itu pembacaan mentahnya. Audit nanya
        // dua-duanya.
        $lembar = $tahun->files()->where('sumber', FolderFile::SUMBER_LEMBAR_KERJA)->firstOrFail();
        $this->assertNotNull($lembar->calibration_session_id);
        $this->assertNull($lembar->path);
    }

    public function test_dua_sertifikat_pt_yang_sama_numpuk_di_satu_folder(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);
        $this->terbitkanSertifikatUntuk($this->teknisi);

        $this->assertSame(1, Folder::whereNull('parent_id')->count());

        // Yang diuji di sini penumpukan SERTIFIKAT, jadi hitungannya disaring.
        // Total berkasnya 4 (2 sertifikat + 2 lembar kerja) — dihitung mentah,
        // testnya jadi ngunci "berapa jenis berkas yang diarsip", bukan
        // "sertifikat PT yang sama numpuk di satu folder".
        $this->assertSame(2, FolderFile::where('sumber', FolderFile::SUMBER_SERTIFIKAT)->count());
        $this->assertSame(2, FolderFile::where('sumber', FolderFile::SUMBER_LEMBAR_KERJA)->count());
    }

    public function test_daftar_folder_akar_nampilin_jumlah_isinya(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);

        $this->actingAs($this->admin)
            ->getJson('/api/folders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Tirta Gracia')
            ->assertJsonPath('data.0.tipe', 'sistem')
            ->assertJsonPath('data.0.jumlah_folder', 1);
    }

    public function test_buka_folder_pt_ikut_nampilin_alat_milik_pt_itu(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);
        $akar = Folder::whereNull('parent_id')->firstOrFail();

        $data = $this->actingAs($this->admin)
            ->getJson("/api/folders/{$akar->id}")
            ->assertOk()
            ->json('data');

        // Folder PT jadi satu pintu masuk: buka PT-nya, kelihatan alat apa aja
        // yang dia punya — nggak perlu pindah ke layar Alat lalu nyaring PT-nya
        // lagi.
        $this->assertNotEmpty($data['alat'] ?? []);
        $this->assertSame('PT Tirta Gracia', $data['pelanggan']['nama']);
        $this->assertArrayHasKey('tanggal_jatuh_tempo', $data['alat'][0]);
        $this->assertSame(1, $data['alat'][0]['jumlah_kalibrasi']);
    }

    public function test_folder_tahun_ngga_k_nyeret_daftar_alat(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);
        $tahun = Folder::whereNotNull('parent_id')->firstOrFail();

        $data = $this->actingAs($this->admin)
            ->getJson("/api/folders/{$tahun->id}")
            ->assertOk()
            ->json('data');

        // Folder tahun isinya berkas. Nyeret daftar alat ke tiap subfolder cuma
        // gedein payload tanpa nambah yang bisa dipakai.
        $this->assertArrayNotHasKey('alat', $data);
    }

    public function test_buka_folder_nampilin_sub_folder_dan_file_di_dalamnya(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);
        $tahun = Folder::whereNotNull('parent_id')->firstOrFail();

        // Dua berkas sekarang: lembar kerja (dibikin waktu dikirim) lalu
        // sertifikat (waktu disetujui). Urutannya dari id, jadi lembar kerja
        // duluan.
        $data = $this->actingAs($this->admin)
            ->getJson("/api/folders/{$tahun->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.file')
            ->json('data.file');

        $lembar = collect($data)->firstWhere('sumber', 'lembar_kerja');
        $sertifikat = collect($data)->firstWhere('sumber', 'sertifikat');

        $this->assertNotNull($lembar);
        $this->assertNotNull($sertifikat);

        // Lembar kerja NGGAK nawarin unduhan — `path`-nya null, jadi tombol
        // Unduh yang muncul di layar pasti gagal. Yang ditawarin tautan ke
        // sesinya.
        $this->assertNull($lembar['download_url']);
        $this->assertNotNull($lembar['lembar_kerja']['calibration_session_id']);
        $this->assertNotNull($sertifikat['download_url']);
    }

    public function test_unduh_lembar_kerja_dijawab_jelas_bukan_404(): void
    {
        $sesi = $this->terbitkanSertifikatUntuk($this->teknisi);

        $lembar = FolderFile::where('calibration_session_id', $sesi->id)->firstOrFail();

        // 404 di sini nyesatin: barisnya ADA dan sah, yang nggak ada cuma
        // berkas buat diunduh. "Nggak ketemu" bikin orang ngira datanya ilang.
        $this->actingAs($this->admin)
            ->getJson("/api/folder-files/{$lembar->id}/download")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'lembar kerja'));
    }

    public function test_admin_bisa_bikin_ganti_nama_dan_hapus_folder_manual(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/folders', ['nama' => 'Arsip Surat Jalan'])
            ->assertCreated()
            ->assertJsonPath('data.tipe', 'manual')
            ->json('data.id');

        $this->actingAs($this->admin)
            ->putJson("/api/folders/{$id}", ['nama' => 'Arsip Surat Jalan 2026'])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Arsip Surat Jalan 2026');

        $this->actingAs($this->admin)->deleteJson("/api/folders/{$id}")->assertNoContent();
        $this->assertSoftDeleted('folders', ['id' => $id]);
    }

    public function test_nama_folder_nggak_boleh_kembar_di_lokasi_yang_sama(): void
    {
        $this->actingAs($this->admin)->postJson('/api/folders', ['nama' => 'Arsip'])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/folders', ['nama' => 'Arsip'])
            ->assertStatus(422);
    }

    public function test_folder_otomatis_nggak_bisa_direname_atau_dihapus_selagi_ada_isinya(): void
    {
        $this->terbitkanSertifikatUntuk($this->teknisi);
        $akar = Folder::whereNull('parent_id')->firstOrFail();

        $this->actingAs($this->admin)
            ->putJson("/api/folders/{$akar->id}", ['nama' => 'Nama Karangan'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->deleteJson("/api/folders/{$akar->id}")
            ->assertStatus(422);
    }

    public function test_teknisi_nggak_bisa_bikin_atau_hapus_folder(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/folders', ['nama' => 'Punya Saya'])
            ->assertForbidden();
    }

    public function test_teknisi_cuma_lihat_folder_yang_ada_sertifikat_miliknya(): void
    {
        $teknisiLain = User::factory()->create();
        $this->terbitkanSertifikatUntuk($teknisiLain, Customer::factory()->create(['nama' => 'PT Lain']));

        // Folder PT Lain ada, tapi isinya bukan kerjaan teknisi ini.
        $this->actingAs($this->teknisi)
            ->getJson('/api/folders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->terbitkanSertifikatUntuk($this->teknisi);

        $this->actingAs($this->teknisi)
            ->getJson('/api/folders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Tirta Gracia');
    }

    public function test_teknisi_nggak_bisa_unduh_file_punya_orang_lain(): void
    {
        $teknisiLain = User::factory()->create();
        $this->terbitkanSertifikatUntuk($teknisiLain);

        // Sertifikatnya yang diambil, bukan `firstOrFail()`: yang diuji di sini
        // IZIN unduhan, jadi berkasnya mesti yang beneran bisa diunduh. Berkas
        // pertama sekarang lembar kerja, yang `path`-nya null — pakai itu,
        // testnya jadi ngukur "ada berkasnya apa nggak", bukan "boleh apa
        // nggak".
        $file = FolderFile::where('sumber', FolderFile::SUMBER_SERTIFIKAT)->firstOrFail();

        $this->actingAs($this->teknisi)
            ->get("/api/folder-files/{$file->id}/download")
            ->assertNotFound();

        $this->actingAs($teknisiLain)
            ->get("/api/folder-files/{$file->id}/download")
            ->assertOk();
    }

    public function test_admin_bisa_unggah_dokumen_pendukung_ke_folder(): void
    {
        Storage::fake('local');
        $folder = Folder::factory()->create();

        $id = $this->actingAs($this->admin)
            ->postJson('/api/folder-files', [
                'folder_id' => $folder->id,
                'file' => UploadedFile::fake()->create('surat-jalan.pdf', 12, 'application/pdf'),
                'keterangan' => 'Surat jalan pengiriman alat',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sumber', 'unggahan')
            ->assertJsonPath('data.nama', 'surat-jalan.pdf')
            ->json('data.id');

        $this->actingAs($this->admin)->get("/api/folder-files/{$id}/download")->assertOk();
    }

    public function test_hapus_entri_sertifikat_dari_folder_nggak_ngehapus_sertifikatnya(): void
    {
        $sesi = $this->terbitkanSertifikatUntuk($this->teknisi);
        $file = FolderFile::firstOrFail();

        $this->actingAs($this->admin)->deleteJson("/api/folder-files/{$file->id}")->assertNoContent();

        $this->assertSoftDeleted('folder_files', ['id' => $file->id]);
        $this->assertNotNull($sesi->fresh()->certificate);
    }
}
