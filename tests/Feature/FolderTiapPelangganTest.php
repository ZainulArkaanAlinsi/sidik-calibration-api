<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Daftar akar Arsip yang dibuka admin memuat SEMUA PT, bukan cuma yang pernah
 * punya sertifikat.
 *
 * ## Kenapa berkas ini ada
 *
 * Folder akar dulu cuma lahir dari dua jalan: sertifikat terbit / lembar kerja
 * dikirim (`FolderOrganizer`), atau admin menekan PT-nya di layar Arsip
 * (`folderPelanggan()`, find-or-create). Jalan kedua buntu: PT yang belum
 * punya folder NGGAK TAMPIL di daftar, jadi nggak ada yang bisa ditekan.
 * Produksi 26 Sep 2026 — belum ada satu sesi pun disetujui — melihat Arsip
 * kosong total, dan pengguna melaporkannya sebagai "folder perusahaan kosong".
 *
 * Yang dijaga sama ketatnya: yang MEMBUAT folder cuma admin. GET dari teknisi,
 * viewer, dan super admin tetap baca-saja, persis `FolderPelangganTest`.
 */
class FolderTiapPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
    }

    private function pelanggan(string $nama, ?Organization $lab = null): Customer
    {
        return Customer::factory()->create([
            'organization_id' => ($lab ?? $this->lab)->id,
            'nama' => $nama,
        ]);
    }

    public function test_admin_buka_daftar_akar_semua_pt_dapat_folder(): void
    {
        $alfa = $this->pelanggan('PT Alfa Contoh');
        $beta = $this->pelanggan('PT Beta Contoh');

        $this->assertSame(0, Folder::count());

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nama', 'PT Alfa Contoh')
            ->assertJsonPath('data.0.pelanggan.id', $alfa->id)
            ->assertJsonPath('data.0.tipe', Folder::TIPE_SISTEM)
            ->assertJsonPath('data.1.pelanggan.id', $beta->id);

        // Bentuknya sama dengan yang lahir dari `FolderOrganizer`, bukan
        // folder "sistem" tiruan: akar, menempel ke PT, organisasi yang sama.
        foreach ([$alfa, $beta] as $pt) {
            $this->assertTrue(Folder::where([
                'organization_id' => $this->lab->id,
                'parent_id' => null,
                'customer_id' => $pt->id,
                'tipe' => Folder::TIPE_SISTEM,
            ])->exists());
        }
    }

    public function test_rute_folder_manager_ikut_lengkap(): void
    {
        // `/folders` (tab Folder Manager) dan `/arsip/perusahaan` itu handler
        // yang sama — dua-duanya pintu masuk, dua-duanya harus lengkap.
        $this->pelanggan('PT Alfa Contoh');

        $this->actingAs($this->admin)
            ->getJson('/api/folders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_dibuka_berulang_nggak_bikin_folder_kembar(): void
    {
        $this->pelanggan('PT Alfa Contoh');

        $this->actingAs($this->admin)->getJson('/api/arsip/perusahaan')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/arsip/perusahaan')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/folders')->assertOk();

        $this->assertSame(1, Folder::count());
    }

    public function test_folder_yang_sudah_ada_dipakai_bukan_digandakan(): void
    {
        $alfa = $this->pelanggan('PT Alfa Contoh');
        $lama = Folder::factory()->create([
            'organization_id' => $this->lab->id,
            'nama' => 'PT Alfa Contoh',
            'parent_id' => null,
            'customer_id' => $alfa->id,
            'tipe' => Folder::TIPE_SISTEM,
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lama->id);

        $this->assertSame(1, Folder::count());
    }

    public function test_pt_yang_baru_didaftarkan_ikut_muncul_di_bukaan_berikutnya(): void
    {
        $this->pelanggan('PT Alfa Contoh');
        $this->actingAs($this->admin)->getJson('/api/arsip/perusahaan')->assertJsonCount(1, 'data');

        $this->pelanggan('PT Beta Contoh');

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_pt_organisasi_lain_nggak_disentuh(): void
    {
        $labLain = Organization::factory()->create();
        $this->pelanggan('PT Milik Lab Lain', $labLain);
        $this->pelanggan('PT Alfa Contoh');

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Alfa Contoh');

        $this->assertSame(0, Folder::where('organization_id', $labLain->id)->count());
    }

    public function test_pt_yang_dihapus_nggak_dibikinkan_folder(): void
    {
        $this->pelanggan('PT Sudah Dihapus')->delete();

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, Folder::count());
    }

    public function test_buka_subfolder_nggak_menyiapkan_folder_akar(): void
    {
        $this->pelanggan('PT Alfa Contoh');
        $induk = Folder::factory()->create(['organization_id' => $this->lab->id, 'parent_id' => null]);

        $this->actingAs($this->admin)
            ->getJson('/api/folders?parent_id='.$induk->id)
            ->assertOk();

        // Cuma folder induk bikinan test ini — PT-nya belum disiapkan, karena
        // yang dibuka bukan daftar akar.
        $this->assertSame(1, Folder::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function peranBacaSaja(): array
    {
        return [
            'teknisi' => [User::ROLE_TEKNISI],
            'viewer' => [User::ROLE_VIEWER],
            'super admin' => [User::ROLE_SUPER_ADMIN],
        ];
    }

    #[DataProvider('peranBacaSaja')]
    public function test_selain_admin_nggak_pernah_membuat_folder(string $peran): void
    {
        $this->pelanggan('PT Alfa Contoh');
        $pengguna = User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => $peran,
        ]);

        $this->actingAs($pengguna)->getJson('/api/arsip/perusahaan')->assertOk();
        $this->actingAs($pengguna)->getJson('/api/folders')->assertOk();

        $this->assertSame(0, Folder::count());
    }

    public function test_search_disaring_sama_seperti_q(): void
    {
        $this->pelanggan('PT Alfa Contoh');
        $this->pelanggan('PT Beta Contoh');

        foreach (['q', 'search'] as $parameter) {
            $this->actingAs($this->admin)
                ->getJson("/api/arsip/perusahaan?{$parameter}=beta")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.nama', 'PT Beta Contoh');
        }
    }

    public function test_q_menang_kalau_dua_duanya_dikirim(): void
    {
        $this->pelanggan('PT Alfa Contoh');
        $this->pelanggan('PT Beta Contoh');

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan?q=alfa&search=beta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Alfa Contoh');
    }
}
