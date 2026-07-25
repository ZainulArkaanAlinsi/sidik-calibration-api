<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo lab di Pengaturan Organisasi (docs/permintaan-endpoint-fase-2.md §3a).
 *
 * Sisi PDF-nya sebenernya UDAH jalan sebelum ini: `GenerateCertificate::logoDataUri()`
 * baca `organization.logo_path` dari disk publik dan nempelin ke kop sertifikat.
 * Yang belum: cara ngunggahnya, dan `logo_url` biar mobile bisa nampilin.
 */
class LogoOrganisasiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        // Disk publik di-fake: unggahan test jangan nyampah ke storage asli.
        Storage::fake('public');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function logo(string $nama = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 400, 400);
    }

    public function test_admin_bisa_unggah_logo(): void
    {
        $res = $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo()])
            ->assertOk();

        $path = $this->org->fresh()->logo_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // `logo_url` absolut & siap dipasang di Image.network — mobile nggak
        // nyusun path sendiri.
        $this->assertSame(Storage::disk('public')->url($path), $res->json('data.logo_url'));
    }

    public function test_logo_url_null_sebelum_ada_logo(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/organization')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }

    /** Logo disimpen kepisah per organisasi, biar dua PT nggak nabrak. */
    public function test_logo_disimpen_dalam_folder_per_organisasi(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo()])
            ->assertOk();

        $this->assertStringStartsWith("logo-organisasi/{$this->org->id}/", $this->org->fresh()->logo_path);
    }

    /**
     * Nama filenya diacak, bukan nama asli dari admin.
     *
     * URL-nya publik: nama yang bisa ditebak bikin logo satu lab kesenggol yang
     * lain, dan nama asli dari klien itu jalur klasik path traversal.
     */
    public function test_nama_file_diacak_bukan_nama_asli(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo('logo-pt-sidik.png')])
            ->assertOk();

        $this->assertStringNotContainsString('logo-pt-sidik', $this->org->fresh()->logo_path);
    }

    /**
     * Ganti logo: yang lama kehapus, yang baru kepakai.
     *
     * Urutannya penting — yang lama dihapus SESUDAH yang baru kesimpen. Kalau
     * kebalik lalu unggahnya gagal, org-nya kehilangan logo tanpa gantinya.
     */
    public function test_ganti_logo_hapus_yang_lama(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo('pertama.png')])
            ->assertOk();
        $lama = $this->org->fresh()->logo_path;

        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo('kedua.png')])
            ->assertOk();
        $baru = $this->org->fresh()->logo_path;

        $this->assertNotSame($lama, $baru);
        Storage::disk('public')->assertMissing($lama);
        Storage::disk('public')->assertExists($baru);
    }

    public function test_admin_bisa_hapus_logo(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo()])
            ->assertOk();
        $path = $this->org->fresh()->logo_path;

        $this->actingAs($this->admin)
            ->deleteJson('/api/organization/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->org->fresh()->logo_path);
    }

    /** Hapus waktu belum ada logo bukan error — idempoten. */
    public function test_hapus_logo_yang_belum_ada_tetap_ok(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson('/api/organization/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }

    /**
     * Ekstensi asli dipertahankan waktu disimpen.
     *
     * Ini mata rantai yang gampang putus: `GenerateCertificate::logoDataUri()`
     * nebak mime dari EKSTENSI file (`.png` → image/png, selain itu → image/jpeg).
     * Kalau penyimpanan suatu saat diubah jadi nggak bawa ekstensi (mis. pakai
     * hash polos), semua logo PNG bakal dilabeli JPEG dan kop sertifikatnya rusak
     * tanpa error — jadi asumsinya dikunci di sini.
     */
    public function test_ekstensi_dipertahankan_biar_mime_di_pdf_bener(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', ['logo' => $this->logo('kop.png')])
            ->assertOk();
        $this->assertStringEndsWith('.png', $this->org->fresh()->logo_path);

        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', [
                'logo' => UploadedFile::fake()->image('kop.jpg', 400, 400),
            ])
            ->assertOk();
        $this->assertStringEndsWith('.jpg', $this->org->fresh()->logo_path);
    }

    // ---------------------------------------------------------- validasi

    /**
     * WEBP ditolak walau itu gambar yang sah.
     *
     * Logonya berakhir di PDF lewat dompdf, yang nggak bisa render WEBP — dan
     * `GenerateCertificate::logoDataUri()` nebak mime dari ekstensi, jadi WEBP
     * bakal dilabeli `image/jpeg`. Hasilnya kop sertifikat rusak TANPA error apa
     * pun; ketahuannya baru waktu ada yang buka PDF-nya. Lebih baik ditolak di
     * pintu masuk dengan pesan yang jelas.
     */
    public function test_webp_ditolak_karena_nggak_bisa_dicetak_di_pdf(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', [
                'logo' => UploadedFile::fake()->image('logo.webp', 400, 400),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_bukan_gambar_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', [
                'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_lebih_dari_2mb_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', [
                'logo' => UploadedFile::fake()->image('besar.png')->size(3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_tanpa_file_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/organization/logo', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    // ------------------------------------------------------------ akses

    public function test_teknisi_ditolak(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/organization/logo', ['logo' => $this->logo()])
            ->assertForbidden();

        $this->actingAs($this->teknisi)
            ->deleteJson('/api/organization/logo')
            ->assertForbidden();
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/organization/logo', ['logo' => $this->logo()])
            ->assertUnauthorized();
    }
}
