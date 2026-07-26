<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/arsip/perusahaan/{customer}/folder` — tap PT → buka folder akarnya.
 *
 * Inti Folder Manager (docs/permintaan-backend-2026-07-24.md §2b baris 1).
 * `GET /arsip/perusahaan` doang nggak cukup: dia cuma ngelist folder yang UDAH
 * ada, sementara PT yang belum pernah punya sertifikat belum punya folder.
 *
 * Yang bikin endpoint ini nggak sesederhana "find-or-create": pembikinannya
 * ketemu dua aturan yang udah ada.
 *
 * 1. Folder Manager nyembunyiin folder yang kosong BUAT TEKNISI ITU (lihat
 *    ScopesFolderAccess) — biar nggak jadi jalan ngintip kerjaan orang lain.
 *    Jadi kalau teknisi mancing pembikinan, hasilnya baris baru yang langsung
 *    disembunyiin lagi: nyampah, tanpa manfaat.
 * 2. `POST`/`PUT`/`DELETE /folders` semuanya admin-only. Kalau GET ini bikin
 *    folder buat role apa pun, dia jadi celah nulis buat role yang di tempat
 *    lain ditolak — dan "viewer nggak pernah nulis" berhenti benar.
 *
 * Kesimpulannya: **cuma admin yang bikin**; teknisi & viewer cuma buka yang
 * udah ada. Test di bawah ngunci itu.
 */
class FolderPelangganTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $viewer;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->pelanggan = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
    }

    private function url(?int $customerId = null): string
    {
        return '/api/arsip/perusahaan/'.($customerId ?? $this->pelanggan->id).'/folder';
    }

    private function folderAkar(): Folder
    {
        return Folder::factory()->create([
            'nama' => 'PT Tirta Gracia',
            'parent_id' => null,
            'customer_id' => $this->pelanggan->id,
            'tipe' => Folder::TIPE_SISTEM,
        ]);
    }

    /** Berkas yang keliatan buat teknisi: yang dia unggah sendiri. */
    private function berkasMilikTeknisi(Folder $folder): FolderFile
    {
        return FolderFile::create([
            'organization_id' => $this->teknisi->organization_id,
            'folder_id' => $folder->id,
            'uploaded_by' => $this->teknisi->id,
            'nama' => 'catatan-lapangan.pdf',
            'sumber' => FolderFile::SUMBER_UNGGAHAN,
        ]);
    }

    // ---------------------------------------------------------------- admin

    public function test_admin_bikin_folder_akar_kalau_belum_ada(): void
    {
        $this->assertSame(0, Folder::count());

        $this->actingAs($this->admin)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT Tirta Gracia')
            ->assertJsonPath('data.parent_id', null)
            // `sistem` = kebentuk otomatis; mobile pakai ini buat nyembunyiin
            // tombol rename/hapus.
            ->assertJsonPath('data.tipe', Folder::TIPE_SISTEM)
            ->assertJsonPath('data.pelanggan.id', $this->pelanggan->id);

        $this->assertSame(1, Folder::count());
    }

    /** Dobel-tap tetap satu folder, bukan dua. */
    public function test_dipanggil_berulang_nggak_bikin_folder_kembar(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->admin)->getJson($this->url())->assertOk();
        }

        $this->assertSame(1, Folder::count());
    }

    public function test_folder_yang_udah_ada_dipakai_ulang(): void
    {
        $adaDuluan = $this->folderAkar();

        $this->actingAs($this->admin)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.id', $adaDuluan->id);

        $this->assertSame(1, Folder::count());
    }

    /**
     * Bentuknya wajib sama sama `GET /arsip/folders/{id}` — handler `show()` yang
     * sama dipanggil langsung, bukan disalin, jadi mobile cukup satu parser.
     */
    public function test_bentuknya_sama_kayak_show_folder(): void
    {
        $lewatPelanggan = $this->actingAs($this->admin)->getJson($this->url())->assertOk();

        $folderId = $lewatPelanggan->json('data.id');

        $lewatFolder = $this->actingAs($this->admin)
            ->getJson("/api/arsip/folders/{$folderId}")
            ->assertOk();

        $this->assertSame($lewatFolder->json('data'), $lewatPelanggan->json('data'));
    }

    public function test_ikut_bawa_subfolder_dan_berkas(): void
    {
        $akar = $this->folderAkar();

        Folder::factory()->create([
            'nama' => '2026',
            'parent_id' => $akar->id,
            'customer_id' => $this->pelanggan->id,
        ]);

        $this->actingAs($this->admin)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data.sub_folder')
            ->assertJsonPath('data.sub_folder.0.nama', '2026')
            ->assertJsonPath('data.file', []);
    }

    // -------------------------------------------------------------- teknisi

    /** Teknisi yang PUNYA berkas di PT itu boleh buka. */
    public function test_teknisi_yang_punya_berkas_boleh_buka(): void
    {
        $akar = $this->folderAkar();
        $this->berkasMilikTeknisi($akar);

        $this->actingAs($this->teknisi)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.id', $akar->id)
            ->assertJsonCount(1, 'data.file');
    }

    /**
     * Teknisi tanpa kerjaan di PT itu dapat 404 — bukan folder kosong.
     *
     * Ini aturan privasi yang udah ada, bukan efek samping: folder kosong yang
     * ditampilin ke teknisi ngasih tahu "PT ini ada arsipnya, cuma bukan
     * urusanmu" — dan Folder Manager ada di navbar teknisi, jadi itu jalan
     * gampang buat nyisir daftar pelanggan lab.
     */
    public function test_teknisi_tanpa_kerjaan_dapat_404(): void
    {
        $this->folderAkar();

        $this->actingAs($this->teknisi)
            ->getJson($this->url())
            ->assertNotFound();
    }

    /** Dan yang lebih penting: teknisi nggak bisa mancing pembikinan folder. */
    public function test_teknisi_nggak_bikin_folder(): void
    {
        $this->assertSame(0, Folder::count());

        $this->actingAs($this->teknisi)->getJson($this->url())->assertNotFound();

        $this->assertSame(0, Folder::count());
    }

    // --------------------------------------------------------------- viewer

    public function test_viewer_boleh_buka_folder_yang_udah_ada(): void
    {
        $akar = $this->folderAkar();

        $this->actingAs($this->viewer)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.id', $akar->id);
    }

    /**
     * Viewer itu role baca-saja. Bikin baris sebagai efek samping GET bikin
     * klaim "viewer nggak pernah nulis" berhenti benar — dan klaim itu lebih
     * berharga daripada kemudahan satu tap.
     */
    public function test_viewer_nggak_bikin_folder(): void
    {
        $this->actingAs($this->viewer)->getJson($this->url())->assertNotFound();

        $this->assertSame(0, Folder::count());
    }

    // -------------------------------------------------------------- isolasi

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    /**
     * PT milik organisasi lain → 404, bukan 403.
     *
     * 403 ngasih tahu "id ini ada, cuma bukan punyamu". Karena responsnya bawa
     * nama PT, beda 403 vs 404 doang udah cukup buat nyisir daftar pelanggan
     * lab lain — jadi dua-duanya dijawab sama.
     */
    public function test_pelanggan_organisasi_lain_dapat_404(): void
    {
        $lain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create([
            'organization_id' => $lain->id,
            'nama' => 'PT Sebelah',
        ]);

        $this->actingAs($this->admin)
            ->getJson($this->url($pelangganLain->id))
            ->assertNotFound();

        // Dan nggak ninggalin folder buat PT orang lain.
        $this->assertSame(0, Folder::count());
    }

    public function test_pelanggan_nggak_ada_dapat_404(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->url(999999))
            ->assertNotFound();

        $this->assertSame(0, Folder::count());
    }
}
