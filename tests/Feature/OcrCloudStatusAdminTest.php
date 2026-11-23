<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MembuatKunciUji;
use Tests\TestCase;

/**
 * `GET /api/admin/ocr-cloud`: status konfigurasi OCR awan dan hitungan kegagalan 24 jam, hanya
 * untuk admin terautentikasi. `GET /api/health` (publik) TIDAK memuat blok ini sama sekali.
 *
 * Yang dikunci: siapa boleh melihat (gerbang token dan peran yang sama dengan endpoint admin lain),
 * isi tidak pernah memuat kredensial, hitungan hanya milik organisasi pemanggil dan hanya jalur OCR
 * awan, dan status tetap terlihat walau saklar `ocr_cloud` dimatikan.
 */
class OcrCloudStatusAdminTest extends TestCase
{
    use MembuatKunciUji;
    use RefreshDatabase;

    private const URL = '/api/admin/ocr-cloud';

    private const KREDENSIAL_TERBACA = '{"client_email":"ocr-uji@proyek-uji.iam.gserviceaccount.com","private_key":"BUKAN-PEM-TAPI-TERISI"}';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
    }

    private function pengguna(string $role, ?Organization $org = null): User
    {
        return User::factory()->create(['role' => $role, 'organization_id' => ($org ?? $this->org)->id]);
    }

    /** @param  list<string>  $ability */
    private function denganToken(User $user, array $ability = ['internal']): static
    {
        return $this->withToken($user->createToken('uji', $ability)->plainTextToken);
    }

    // ------------------------------------------------------------------ akses

    /** Endpoint publik hanya mengembalikan yang sudah ada sejak dulu: tidak ada jejak blok ini. */
    public function test_health_publik_tidak_memuat_blok_ocr_cloud_walau_fitur_nyala_dan_salah_setel(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => '']);

        $r = $this->getJson('/api/health')->assertJsonStructure(['status', 'app', 'time', 'kesehatan']);

        $this->assertArrayNotHasKey('ocr_cloud', $r->json());
        $this->assertStringNotContainsString('ocr_cloud', (string) $r->getContent());
        $this->assertStringNotContainsString('salah_setel', (string) $r->getContent());
    }

    public function test_health_publik_tetap_tanpa_blok_walau_dipanggil_dengan_token_admin(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => '']);
        $admin = $this->pengguna(User::ROLE_ADMIN);

        $r = $this->denganToken($admin)->getJson('/api/health')->assertJsonStructure(['status', 'app', 'time', 'kesehatan']);

        $this->assertArrayNotHasKey('ocr_cloud', $r->json());
    }

    public function test_tamu_ditolak_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    /** @return array<string, array{string}> */
    public static function peranDitolak(): array
    {
        return ['teknisi' => [User::ROLE_TEKNISI], 'viewer' => [User::ROLE_VIEWER]];
    }

    #[DataProvider('peranDitolak')]
    public function test_peran_selain_admin_ditolak_403(string $role): void
    {
        $this->denganToken($this->pengguna($role))->getJson(self::URL)->assertStatus(403);
    }

    /** Token yang bukan untuk aplikasi internal (mis. pelanggan) ditolak walau penggunanya admin. */
    public function test_token_bukan_internal_ditolak_403(): void
    {
        $admin = $this->pengguna(User::ROLE_ADMIN);

        $this->denganToken($admin, ['pelanggan'])->getJson(self::URL)->assertStatus(403);
    }

    /** @return array<string, array{string}> */
    public static function peranDiizinkan(): array
    {
        return ['admin' => [User::ROLE_ADMIN], 'super_admin' => [User::ROLE_SUPER_ADMIN]];
    }

    #[DataProvider('peranDiizinkan')]
    public function test_admin_dan_super_admin_boleh_melihat(string $role): void
    {
        $this->denganToken($this->pengguna($role))->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['aktif', 'kredensial_disetel', 'kredensial_terbaca', 'salah_setel']);
    }

    /** Admin harus tetap bisa melihat status justru saat fitur dimatikan lewat saklar Super Admin. */
    public function test_tetap_terlihat_saat_saklar_ocr_cloud_dimatikan(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['ocr_cloud']]]);

        $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)->assertOk();
    }

    // -------------------------------------------------------------------- isi

    public function test_bawaan_mati_tidak_menyentuh_hitungan_dan_tidak_dianggap_salah_setel(): void
    {
        config(['services.google_vision.aktif' => false, 'services.google_vision.kredensial' => null]);

        $r = $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)
            ->assertJsonPath('aktif', false)
            ->assertJsonPath('kredensial_disetel', false)
            ->assertJsonPath('salah_setel', false);

        $this->assertArrayNotHasKey('hitungan', $r->json());
    }

    public function test_nyala_tanpa_kredensial_ditandai_salah_setel(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => '']);

        $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)
            ->assertJsonPath('aktif', true)
            ->assertJsonPath('kredensial_disetel', false)
            ->assertJsonPath('kredensial_terbaca', false)
            ->assertJsonPath('salah_setel', true);
    }

    public function test_kredensial_bukan_json_ditandai_salah_setel_tanpa_membocorkan_isinya(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => 'RAHASIA-BUKAN-JSON']);

        $r = $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)
            ->assertJsonPath('kredensial_disetel', true)
            ->assertJsonPath('kredensial_terbaca', false)
            ->assertJsonPath('salah_setel', true);

        $this->assertStringNotContainsString('RAHASIA-BUKAN-JSON', (string) $r->getContent());
    }

    /** Bentuk JSON benar tetapi kunci privat tidak terbaca OpenSSL (mis. `
` salah escape): tetap salah setel. */
    public function test_bentuk_json_benar_tetapi_kunci_privat_rusak_ditandai_salah_setel(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => self::KREDENSIAL_TERBACA]);

        $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)
            ->assertJsonPath('kredensial_disetel', true)
            ->assertJsonPath('kredensial_terbaca', false)
            ->assertJsonPath('salah_setel', true);
    }

    public function test_kunci_privat_valid_ditandai_terbaca_tanpa_membocorkan_isinya(): void
    {
        [, $pem] = $this->bangkitkanKunciUji();
        config([
            'services.google_vision.aktif' => true,
            'services.google_vision.kredensial' => json_encode(['client_email' => 'ocr-uji@proyek-uji.iam.gserviceaccount.com', 'private_key' => $pem]),
        ]);

        $r = $this->denganToken($this->pengguna(User::ROLE_ADMIN))->getJson(self::URL)
            ->assertJsonPath('kredensial_disetel', true)
            ->assertJsonPath('kredensial_terbaca', true)
            ->assertJsonPath('salah_setel', false);

        $this->assertStringNotContainsString('PRIVATE KEY', (string) $r->getContent());
        $this->assertStringNotContainsString('ocr-uji@proyek-uji', (string) $r->getContent());
    }

    public function test_hitungan_24_jam_hanya_milik_organisasi_pemanggil_dan_hanya_jalur_ocr_awan(): void
    {
        config(['services.google_vision.aktif' => true, 'services.google_vision.kredensial' => self::KREDENSIAL_TERBACA]);
        $admin = $this->pengguna(User::ROLE_ADMIN);
        $teknisi = $this->pengguna(User::ROLE_TEKNISI);

        foreach (['sukses', 'sukses', 'gagal', 'belum_disetel'] as $status) {
            WorksheetExtractionLog::create(['user_id' => $teknisi->id, 'model' => GoogleVisionOcr::MODEL, 'status' => $status]);
        }
        // Tidak boleh terhitung: di luar jendela 24 jam, jalur LLM, dan milik organisasi lain.
        $lama = WorksheetExtractionLog::create(['user_id' => $teknisi->id, 'model' => GoogleVisionOcr::MODEL, 'status' => 'gagal']);
        WorksheetExtractionLog::query()->whereKey($lama->id)->update(['created_at' => now()->subDays(3)]);
        WorksheetExtractionLog::create(['user_id' => $teknisi->id, 'model' => 'claude-uji', 'status' => 'gagal']);
        $orgLain = Organization::factory()->create();
        $teknisiLain = $this->pengguna(User::ROLE_TEKNISI, $orgLain);
        WorksheetExtractionLog::create(['user_id' => $teknisiLain->id, 'model' => GoogleVisionOcr::MODEL, 'status' => 'gagal']);

        $r = $this->denganToken($admin)->getJson(self::URL)
            ->assertJsonPath('jendela_jam', 24);

        $this->assertSame(
            ['sukses' => 2, 'gagal' => 1, 'ditolak' => 0, 'dibatasi' => 0, 'belum_disetel' => 1],
            $r->json('hitungan'),
        );
        $this->assertStringNotContainsString('ocr-uji@proyek-uji', (string) $r->getContent());
        $this->assertStringNotContainsString('BUKAN-PEM-TAPI-TERISI', (string) $r->getContent());
    }
}
