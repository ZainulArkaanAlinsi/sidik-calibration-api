<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua operasi arsip terakhir (`docs/permintaan-backend-2026-07-24.md` §2b):
 * pindah folder & pindah berkas.
 *
 * Yang paling dijaga: **struktur pohon nggak boleh bisa dirusak dari API.**
 * Kerusakannya sunyi — folder yang dipindah ke dalam keturunannya sendiri lepas
 * dari pohon dan ilang dari semua layar, padahal barisnya masih ada di database
 * dan nggak ada error apa pun.
 *
 * Yang kedua: folder SISTEM nggak boleh dipindah. `FolderOrganizer` nemuin folder
 * akar PT dari `parent_id = null` dan folder tahun dari `parent_id = akar->id`;
 * begitu dipindah, kriterianya nggak nyocok, sertifikat berikutnya bikin folder
 * BARU, dan arsip satu PT kepecah dua.
 */
class ArsipPindahTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->pelanggan = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
    }

    private function folder(string $nama, ?int $parentId = null, string $tipe = Folder::TIPE_MANUAL): Folder
    {
        return Folder::factory()->create([
            'nama' => $nama,
            'parent_id' => $parentId,
            'tipe' => $tipe,
        ]);
    }

    private function urlFolder(Folder $f): string
    {
        return "/api/arsip/folders/{$f->id}/pindah";
    }

    // -------------------------------------------------------- pindah folder

    public function test_admin_bisa_pindah_folder_manual(): void
    {
        $tujuan = $this->folder('Arsip 2026');
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => $tujuan->id])
            ->assertOk()
            ->assertJsonPath('data.id', $folder->id);

        $this->assertSame($tujuan->id, $folder->fresh()->parent_id);
    }

    /** `parent_id: null` = jadiin folder akar. Sama kayak yang `POST /folders` udah izinin. */
    public function test_bisa_dipindah_jadi_folder_akar(): void
    {
        $induk = $this->folder('Arsip 2026');
        $folder = $this->folder('Surat Jalan', $induk->id);

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => null])
            ->assertOk();

        $this->assertNull($folder->fresh()->parent_id);
    }

    public function test_parent_id_wajib_dikirim(): void
    {
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_folder_tujuan_nggak_ada_ditolak(): void
    {
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_nggak_bisa_dipindah_ke_dirinya_sendiri(): void
    {
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => $folder->id])
            ->assertStatus(422);

        $this->assertNull($folder->fresh()->parent_id, 'Folder-nya nggak boleh keubah.');
    }

    /**
     * INTI: pindah ke KETURUNAN sendiri bikin siklus.
     *
     * Kalau lolos, folder-nya lepas dari pohon dan ilang dari semua layar tanpa
     * error apa pun — barisnya masih ada di DB, tapi nggak bisa dijangkau lagi.
     */
    public function test_nggak_bisa_dipindah_ke_dalam_keturunannya_sendiri(): void
    {
        $kakek = $this->folder('Arsip');
        $bapak = $this->folder('2026', $kakek->id);
        $anak = $this->folder('Juli', $bapak->id);

        // Kakek dipindah ke dalam cucunya.
        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($kakek), ['parent_id' => $anak->id])
            ->assertStatus(422);

        $this->assertNull($kakek->fresh()->parent_id, 'Struktur pohonnya nggak boleh keubah.');
    }

    /**
     * Folder SISTEM nggak bisa dipindah — alasannya sama kayak larangan rename.
     *
     * `FolderOrganizer` nemuin folder akar PT dari `parent_id = null`; begitu
     * dipindah, sertifikat berikutnya bikin folder baru dan arsipnya kepecah dua.
     */
    public function test_folder_sistem_nggak_bisa_dipindah(): void
    {
        $tujuan = $this->folder('Arsip Manual');
        $sistem = $this->folder('PT Tirta Gracia', null, Folder::TIPE_SISTEM);

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($sistem), ['parent_id' => $tujuan->id])
            ->assertStatus(422);

        $this->assertNull($sistem->fresh()->parent_id);
    }

    /** Nama folder harus unik dalam satu induk — aturan yang sama kayak rename. */
    public function test_nama_bentrok_di_folder_tujuan_ditolak(): void
    {
        $tujuan = $this->folder('Arsip 2026');
        $this->folder('Surat Jalan', $tujuan->id);
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => $tujuan->id])
            ->assertStatus(422);

        $this->assertNull($folder->fresh()->parent_id);
    }

    /** Drag ke tempat yang sama = 200, bukan error. */
    public function test_pindah_ke_induk_yang_sama_tetap_ok(): void
    {
        $induk = $this->folder('Arsip 2026');
        $folder = $this->folder('Surat Jalan', $induk->id);

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folder), ['parent_id' => $induk->id])
            ->assertOk();

        $this->assertSame($induk->id, $folder->fresh()->parent_id);
    }

    public function test_teknisi_nggak_bisa_pindah_folder(): void
    {
        $tujuan = $this->folder('Arsip 2026');
        $folder = $this->folder('Surat Jalan');

        $this->actingAs($this->teknisi)
            ->putJson($this->urlFolder($folder), ['parent_id' => $tujuan->id])
            ->assertForbidden();
    }

    public function test_folder_organisasi_lain_dapat_404(): void
    {
        $orgLain = Organization::factory()->create();
        $folderLain = Folder::factory()->create([
            'organization_id' => $orgLain->id,
            'nama' => 'Arsip Lab Lain',
            'tipe' => Folder::TIPE_MANUAL,
        ]);
        $tujuan = $this->folder('Arsip 2026');

        $this->actingAs($this->admin)
            ->putJson($this->urlFolder($folderLain), ['parent_id' => $tujuan->id])
            ->assertNotFound();
    }

    // -------------------------------------------------------- pindah berkas

    private function sesiBersertifikat(?Folder $folder = null): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm', 'toleransi' => 0.05,
        ]);

        $sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
        ]);

        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
        ]);

        if ($folder) {
            FolderFile::create([
                'organization_id' => $sesi->organization_id,
                'folder_id' => $folder->id,
                'certificate_id' => $sertifikat->id,
                'nama' => 'Sertifikat.pdf',
                'sumber' => FolderFile::SUMBER_SERTIFIKAT,
            ]);
        }

        return $sesi->fresh();
    }

    private function urlBerkas(CalibrationSession $s): string
    {
        return "/api/arsip/berkas/{$s->id}/pindah";
    }

    /**
     * Dikunci pakai id SESI, bukan id `folder_files` — itu inti permintaannya.
     * Yang dipegang mobile di layar arsip itu id sesi.
     */
    public function test_admin_bisa_pindah_berkas_pakai_id_sesi(): void
    {
        $asal = $this->folder('2025');
        $tujuan = $this->folder('2026');
        $sesi = $this->sesiBersertifikat($asal);

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $tujuan->id])
            ->assertOk();

        $this->assertSame(
            $tujuan->id,
            FolderFile::where('certificate_id', $sesi->certificate->id)->value('folder_id'),
        );
    }

    public function test_folder_id_wajib(): void
    {
        $sesi = $this->sesiBersertifikat($this->folder('2025'));

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('folder_id');
    }

    public function test_sesi_tanpa_sertifikat_dapat_404(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm', 'toleransi' => 0.05,
        ]);
        $sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $this->folder('2026')->id])
            ->assertNotFound();
    }

    public function test_sertifikat_yang_belum_tertaut_dapat_404(): void
    {
        // Sertifikatnya ada, tapi belum pernah ditautkan ke folder mana pun.
        $sesi = $this->sesiBersertifikat(null);

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $this->folder('2026')->id])
            ->assertNotFound();
    }

    /**
     * Kalau sertifikatnya tertaut di LEBIH DARI SATU folder, backend nanya —
     * bukan nebak.
     *
     * Unique index-nya `(folder_id, certificate_id)`, jadi ini mungkin kejadian.
     * Nebak yang mana lebih jahat daripada nanya: yang salah kepindah nggak
     * keliatan sebagai error, cuma sebagai berkas yang ilang dari folder yang
     * orangnya lihat.
     */
    public function test_kalau_tertaut_di_banyak_folder_backend_nanya_bukan_nebak(): void
    {
        $a = $this->folder('2025');
        $b = $this->folder('Arsip Cadangan');
        $sesi = $this->sesiBersertifikat($a);

        FolderFile::create([
            'organization_id' => $sesi->organization_id,
            'folder_id' => $b->id,
            'certificate_id' => $sesi->certificate->id,
            'nama' => 'Sertifikat.pdf',
            'sumber' => FolderFile::SUMBER_SERTIFIKAT,
        ]);

        $res = $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $this->folder('2026')->id])
            ->assertStatus(422);

        // Daftar kandidatnya ikut dikirim, biar admin bisa milih tanpa nyari lagi.
        $this->assertCount(2, $res->json('data'));
        $this->assertStringContainsString('folder-files', (string) $res->json('message'));
    }

    public function test_berkas_udah_ada_di_folder_tujuan_ditolak(): void
    {
        $asal = $this->folder('2025');
        $tujuan = $this->folder('2026');
        $sesi = $this->sesiBersertifikat($asal);

        // Sertifikat yang sama udah nangkring di folder tujuan.
        FolderFile::create([
            'organization_id' => $sesi->organization_id,
            'folder_id' => $tujuan->id,
            'certificate_id' => $sesi->certificate->id,
            'nama' => 'Sertifikat.pdf',
            'sumber' => FolderFile::SUMBER_SERTIFIKAT,
        ]);

        // Dua berkas → backend nanya duluan (lihat test di atas). Yang diuji di
        // sini pesannya bukan 500 dari unique index.
        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $tujuan->id])
            ->assertStatus(422);
    }

    public function test_pindah_ke_folder_yang_sama_tetap_ok(): void
    {
        $folder = $this->folder('2025');
        $sesi = $this->sesiBersertifikat($folder);

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $folder->id])
            ->assertOk();
    }

    public function test_teknisi_nggak_bisa_pindah_berkas(): void
    {
        $sesi = $this->sesiBersertifikat($this->folder('2025'));

        $this->actingAs($this->teknisi)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $this->folder('2026')->id])
            ->assertForbidden();
    }

    /** Sesi lab lain dapat 404, bukan 403 — biar nggak jadi cara mastiin sesi itu ada. */
    public function test_sesi_organisasi_lain_dapat_404(): void
    {
        $orgLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $orgLain->id]);
        $alatLain = Equipment::factory()->create([
            'organization_id' => $orgLain->id,
            'customer_id' => $pelangganLain->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $orgLain->id])->id,
            'satuan' => 'mm', 'toleransi' => 0.05,
        ]);
        $sesiLain = CalibrationSession::factory()->create([
            'organization_id' => $orgLain->id,
            'teknisi_id' => User::factory()->create(['organization_id' => $orgLain->id])->id,
            'equipment_id' => $alatLain->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesiLain), ['folder_id' => $this->folder('2026')->id])
            ->assertNotFound();
    }

    /** Folder tujuan milik lab lain ditolak — jangan sampai berkas nyeberang PT. */
    public function test_folder_tujuan_milik_lab_lain_ditolak(): void
    {
        $orgLain = Organization::factory()->create();
        $folderLain = Folder::factory()->create([
            'organization_id' => $orgLain->id,
            'nama' => 'Arsip Lab Lain',
            'tipe' => Folder::TIPE_MANUAL,
        ]);
        $sesi = $this->sesiBersertifikat($this->folder('2025'));

        $this->actingAs($this->admin)
            ->putJson($this->urlBerkas($sesi), ['folder_id' => $folderLain->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('folder_id');
    }
}
