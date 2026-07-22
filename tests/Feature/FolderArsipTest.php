<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Folder arsip yang disusun bebas user — pohon beneran di atas Arsip lama.
 *
 * Yang paling dijaga di sini bukan "fiturnya jalan", tapi "fiturnya nggak bisa
 * dipakai buat ngerusak": berkas nyasar perusahaan lain, cabang folder lepas
 * dari akar gara-gara siklus, dan sertifikat kehapus gara-gara folder induknya
 * dihapus. Tiga-tiganya nggak keliatan pas dipakai normal.
 */
class FolderArsipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Customer $perusahaan;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $this->perusahaan = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        $this->alat = Equipment::factory()->create([
            'customer_id' => $this->perusahaan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'nama_alat' => 'Jangka Sorong',
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function buatSesi(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    /** Folder anak siap pakai di bawah akar perusahaan. */
    private function buatSubfolder(string $nama, ?int $parentId = null): Folder
    {
        $parentId ??= Folder::akarUntuk($this->perusahaan)->id;

        $response = $this->actingAs($this->admin)
            ->postJson('/api/arsip/folders', ['parent_id' => $parentId, 'nama' => $nama])
            ->assertCreated();

        return Folder::findOrFail($response->json('data.id'));
    }

    // ---------------------------------------------------------- auto-filing

    public function test_sesi_baru_otomatis_masuk_folder_akar_perusahaan_pemilik_alat(): void
    {
        $sesi = $this->buatSesi();

        $akar = Folder::where('customer_id', $this->perusahaan->id)->whereNull('parent_id')->firstOrFail();

        $this->assertSame($akar->id, $sesi->folder_id);
        $this->assertTrue($akar->is_root);
        // Namanya ikut data pelanggan, bukan diketik ulang waktu input —
        // itu yang bikin nggak ada folder kembar gara-gara beda ejaan.
        $this->assertSame('PT Tirta Gracia', $akar->nama);
    }

    public function test_dua_sesi_perusahaan_sama_masuk_ke_satu_folder_yang_sama(): void
    {
        $pertama = $this->buatSesi();
        $kedua = $this->buatSesi();

        $this->assertSame($pertama->folder_id, $kedua->folder_id);
        $this->assertSame(1, Folder::where('customer_id', $this->perusahaan->id)->count());
    }

    public function test_tiap_alat_wajib_punya_pelanggan_jadi_sesi_selalu_kefolderin(): void
    {
        // `equipments.customer_id` NOT NULL di skema — jadi nggak ada alat
        // yatim, dan tiap sesi dijamin dapat folder. Dikunci di sini supaya
        // kalau suatu saat kolomnya dibikin nullable, ketahuan bahwa
        // auto-filing punya jalur baru yang belum kepikiran.
        $this->expectException(QueryException::class);

        $this->assertNotNull($this->buatSesi()->folder_id);

        $this->alat->update(['customer_id' => null]);
    }

    public function test_sesi_perusahaan_beda_masuk_folder_masing_masing(): void
    {
        $sesiA = $this->buatSesi();

        $perusahaanLain = Customer::factory()->create(['nama' => 'PT Sebelah']);
        $alatLain = Equipment::factory()->create([
            'customer_id' => $perusahaanLain->id,
            'equipment_category_id' => $this->alat->equipment_category_id,
            'nama_alat' => 'Jangka Sorong B',
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alatLain->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesiB = CalibrationSession::latest('id')->firstOrFail();

        $this->assertNotSame($sesiA->folder_id, $sesiB->folder_id);
        $this->assertSame($perusahaanLain->id, Folder::findOrFail($sesiB->folder_id)->customer_id);
    }

    // ------------------------------------------------------------- baca isi

    public function test_buka_folder_akar_nampilin_subfolder_berkas_dan_breadcrumb(): void
    {
        $this->buatSesi();
        $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->getJson("/api/arsip/perusahaan/{$this->perusahaan->id}/folder")
            ->assertOk()
            ->assertJsonPath('folder.nama', 'PT Tirta Gracia')
            ->assertJsonPath('folder.is_root', true)
            ->assertJsonCount(1, 'folder.breadcrumb')
            ->assertJsonCount(1, 'subfolder')
            ->assertJsonPath('subfolder.0.tipe', 'folder')
            ->assertJsonPath('subfolder.0.nama', '2026')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipe', 'berkas');
    }

    public function test_breadcrumb_ikut_dalam_saat_folder_bersarang(): void
    {
        $tahun = $this->buatSubfolder('2026');
        $semester = $this->buatSubfolder('Semester 1', $tahun->id);

        $this->actingAs($this->admin)
            ->getJson("/api/arsip/folders/{$semester->id}")
            ->assertOk()
            // Akar → 2026 → Semester 1
            ->assertJsonCount(3, 'folder.breadcrumb')
            ->assertJsonPath('folder.breadcrumb.0.nama', 'PT Tirta Gracia')
            ->assertJsonPath('folder.breadcrumb.2.nama', 'Semester 1');
    }

    public function test_teknisi_cuma_lihat_berkas_miliknya_di_folder(): void
    {
        $this->buatSesi();

        $teknisiLain = User::factory()->create();
        $this->actingAs($teknisiLain)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->getJson("/api/arsip/perusahaan/{$this->perusahaan->id}/folder")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($this->teknisi)
            ->getJson("/api/arsip/perusahaan/{$this->perusahaan->id}/folder")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // ----------------------------------------------------------- nyusun

    public function test_bikin_dan_ganti_nama_subfolder(): void
    {
        $folder = $this->buatSubfolder('2025');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$folder->id}", ['nama' => '2026'])
            ->assertOk()
            ->assertJsonPath('data.nama', '2026');
    }

    public function test_nama_kembar_dalam_satu_induk_ditolak(): void
    {
        $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->postJson('/api/arsip/folders', [
                'parent_id' => Folder::akarUntuk($this->perusahaan)->id,
                'nama' => '2026',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nama');
    }

    public function test_nama_sama_boleh_asal_beda_induk(): void
    {
        $a = $this->buatSubfolder('2026');
        $b = $this->buatSubfolder('2025');

        // "Semester 1" di bawah 2026 dan di bawah 2025 itu dua folder sah.
        $this->buatSubfolder('Semester 1', $a->id);
        $this->buatSubfolder('Semester 1', $b->id);

        $this->assertSame(2, Folder::where('nama', 'Semester 1')->count());
    }

    public function test_pindah_folder_beserta_isinya(): void
    {
        $tahun = $this->buatSubfolder('2026');
        $semester = $this->buatSubfolder('Semester 1');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$semester->id}/pindah", ['parent_id' => $tahun->id])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $tahun->id);
    }

    public function test_pindah_berkas_antar_folder_dalam_perusahaan_yang_sama(): void
    {
        $sesi = $this->buatSesi();
        $tahun = $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/berkas/{$sesi->id}/pindah", ['folder_id' => $tahun->id])
            ->assertOk();

        $this->assertSame($tahun->id, $sesi->refresh()->folder_id);
    }

    // --------------------------------------------------------- penjagaan

    public function test_folder_nggak_bisa_dipindah_ke_dalam_keturunannya_sendiri(): void
    {
        $tahun = $this->buatSubfolder('2026');
        $semester = $this->buatSubfolder('Semester 1', $tahun->id);

        // Kalau ini lolos, cabang "2026" lepas dari akar dan seluruh isinya
        // ilang dari arsip tanpa pernah kehapus.
        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$tahun->id}/pindah", ['parent_id' => $semester->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');

        $this->assertSame(Folder::akarUntuk($this->perusahaan)->id, $tahun->refresh()->parent_id);
    }

    public function test_folder_nggak_bisa_dipindah_ke_dirinya_sendiri(): void
    {
        $folder = $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$folder->id}/pindah", ['parent_id' => $folder->id])
            ->assertStatus(422);
    }

    public function test_folder_nggak_bisa_dipindah_ke_perusahaan_lain(): void
    {
        $folder = $this->buatSubfolder('2026');

        $perusahaanLain = Customer::factory()->create(['nama' => 'PT Sebelah']);
        $akarLain = Folder::akarUntuk($perusahaanLain);

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$folder->id}/pindah", ['parent_id' => $akarLain->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_berkas_nggak_bisa_dipindah_ke_folder_perusahaan_lain(): void
    {
        $sesi = $this->buatSesi();

        $perusahaanLain = Customer::factory()->create(['nama' => 'PT Sebelah']);
        $akarLain = Folder::akarUntuk($perusahaanLain);

        // Ini yang paling berbahaya: sertifikat pelanggan A nangkring di
        // folder pelanggan B = data satu pelanggan kebuka ke pelanggan lain.
        $this->actingAs($this->admin)
            ->putJson("/api/arsip/berkas/{$sesi->id}/pindah", ['folder_id' => $akarLain->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('folder_id');

        $this->assertNotSame($akarLain->id, $sesi->refresh()->folder_id);
    }

    public function test_folder_akar_nggak_bisa_dinamain_ulang_dipindah_atau_dihapus(): void
    {
        $akar = Folder::akarUntuk($this->perusahaan);
        $lain = $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$akar->id}", ['nama' => 'Ganti'])->assertStatus(422);

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$akar->id}/pindah", ['parent_id' => $lain->id])->assertStatus(422);

        $this->actingAs($this->admin)
            ->deleteJson("/api/arsip/folders/{$akar->id}")->assertStatus(422);

        $this->assertDatabaseHas('folders', ['id' => $akar->id, 'nama' => 'PT Tirta Gracia', 'deleted_at' => null]);
    }

    public function test_folder_berisi_nggak_bisa_dihapus(): void
    {
        $tahun = $this->buatSubfolder('2026');
        $this->buatSubfolder('Semester 1', $tahun->id);

        $this->actingAs($this->admin)
            ->deleteJson("/api/arsip/folders/{$tahun->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('folder');

        $this->assertDatabaseHas('folders', ['id' => $tahun->id, 'deleted_at' => null]);
    }

    public function test_folder_berisi_berkas_nggak_bisa_dihapus(): void
    {
        $sesi = $this->buatSesi();
        $tahun = $this->buatSubfolder('2026');

        $this->actingAs($this->admin)
            ->putJson("/api/arsip/berkas/{$sesi->id}/pindah", ['folder_id' => $tahun->id])->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/arsip/folders/{$tahun->id}")->assertStatus(422);

        // Rekaman kalibrasinya wajib utuh — ini arsip lab terakreditasi.
        $this->assertDatabaseHas('calibration_sessions', ['id' => $sesi->id]);
    }

    public function test_folder_kosong_boleh_dihapus(): void
    {
        $folder = $this->buatSubfolder('Salah Bikin');

        $this->actingAs($this->admin)
            ->deleteJson("/api/arsip/folders/{$folder->id}")->assertOk();

        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_kedalaman_folder_dibatesin(): void
    {
        $indukId = Folder::akarUntuk($this->perusahaan)->id;

        // Akar = tingkat 0, jadi masih boleh nambah sampai MAKS_KEDALAMAN.
        for ($i = 1; $i <= Folder::MAKS_KEDALAMAN; $i++) {
            $indukId = $this->buatSubfolder("Tingkat $i", $indukId)->id;
        }

        $this->actingAs($this->admin)
            ->postJson('/api/arsip/folders', ['parent_id' => $indukId, 'nama' => 'Kelewatan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ------------------------------------------------------------- akses

    public function test_folder_organisasi_lain_404(): void
    {
        $folder = $this->buatSubfolder('2026');

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)->getJson("/api/arsip/folders/{$folder->id}")->assertNotFound();
        $this->actingAs($adminLain)->deleteJson("/api/arsip/folders/{$folder->id}")->assertNotFound();
    }

    /**
     * Teknisi boleh NYUSUN arsip tapi nggak boleh NGILANGIN — folder bisa
     * berisi sertifikat yang jadi bukti akreditasi KAN, jadi hapus dikunci ke
     * admin. Sengaja lebih ketat dari route arsip yang lain.
     */
    public function test_teknisi_boleh_nyusun_tapi_nggak_boleh_hapus_folder(): void
    {
        $akar = Folder::akarUntuk($this->perusahaan);

        // Bikin & rename: boleh.
        $dibuat = $this->actingAs($this->teknisi)
            ->postJson('/api/arsip/folders', ['parent_id' => $akar->id, 'nama' => '2026'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->teknisi)
            ->putJson("/api/arsip/folders/{$dibuat}", ['nama' => '2026 Revisi'])
            ->assertOk();

        // Hapus: ditolak, walaupun foldernya kosong & dia sendiri yang bikin.
        $this->actingAs($this->teknisi)
            ->deleteJson("/api/arsip/folders/{$dibuat}")
            ->assertForbidden();

        $this->assertDatabaseHas('folders', ['id' => $dibuat, 'deleted_at' => null]);

        // Admin tetap bisa.
        $this->actingAs($this->admin)->deleteJson("/api/arsip/folders/{$dibuat}")->assertOk();
    }

    public function test_viewer_nggak_boleh_nyusun_folder(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $akar = Folder::akarUntuk($this->perusahaan);

        // Baca boleh...
        $this->actingAs($viewer)->getJson("/api/arsip/folders/{$akar->id}")->assertOk();

        // ...nyusun nggak.
        $this->actingAs($viewer)
            ->postJson('/api/arsip/folders', ['parent_id' => $akar->id, 'nama' => '2026'])
            ->assertForbidden();
    }

    public function test_folder_wajib_login(): void
    {
        $akar = Folder::akarUntuk($this->perusahaan);

        $this->getJson("/api/arsip/folders/{$akar->id}")->assertUnauthorized();
    }
}
