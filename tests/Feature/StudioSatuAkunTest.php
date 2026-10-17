<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * K-50-12 (15 Okt 2026): Sidik Studio dipakai SATU akun (`STUDIO_AKUN`).
 *
 * Yang dikunci di sini (docs/perintah-frontend-studio-satu-akun.md butir 6):
 * akun cocok -> token `studio`; email lain -> 403; `STUDIO_AKUN` kosong -> 403;
 * token lab ke rute Studio -> 403; token studio ke rute lab -> 403; aplikasi
 * lapangan tidak berubah; token mati begitu `STUDIO_AKUN` diganti.
 */
class StudioSatuAkunTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const SANDI = 'sandi-akun-studio-1';

    private const STUDIO = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private Organization $lab;

    private User $pemilik;

    private WorkbookMaster $wb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->siapkanFolderUji();

        $this->lab = Organization::factory()->create();
        $this->pemilik = $this->akun(User::ROLE_SUPER_ADMIN, 'pemilik@sidik.test');
        config(['studio.akun' => 'pemilik@sidik.test']);

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->buatAkarCsv(), '--organisasi' => (string) $this->lab->id, '--oleh' => $this->olehLab($this->lab)])->assertSuccessful();
        $this->wb = WorkbookMaster::query()->where('nama', 'Master_Olah_Data_Micrometer_0-25mm')->sole();
        // Perintah artisan memasang user di guard `web` (Auth::setUser); Sanctum membacanya
        // sebagai sesi dan mengabaikan Bearer token. Bersihkan sebelum menguji token.
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_akun_studio_login_dari_klien_studio_dapat_token_studio_saja(): void
    {
        $respons = $this->postJson('/api/login', ['identifier' => 'pemilik@sidik.test', 'password' => self::SANDI], self::STUDIO)
            ->assertOk()
            ->assertJsonPath('data.user.email', 'pemilik@sidik.test');

        $token = PersonalAccessToken::findToken((string) $respons->json('data.token'));
        $this->assertSame(['studio'], $token->abilities);
        $this->assertSame('studio', $token->name);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $respons->json('data.token'))
            ->getJson('/api/studio/workbook', self::STUDIO)
            ->assertOk();
    }

    public function test_email_huruf_besar_dan_spasi_di_env_tetap_cocok(): void
    {
        config(['studio.akun' => strtolower(trim('  Pemilik@Sidik.TEST '))]);

        $this->postJson('/api/login', ['identifier' => 'pemilik@sidik.test', 'password' => self::SANDI], self::STUDIO)->assertOk();
    }

    public function test_akun_lain_ditolak_403_dan_tidak_dapat_token(): void
    {
        $lain = $this->akun(User::ROLE_ADMIN, 'admin.lain@sidik.test');

        $this->postJson('/api/login', ['identifier' => 'admin.lain@sidik.test', 'password' => self::SANDI], self::STUDIO)
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_studio')
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, $lain->tokens()->count());

        // Sandi salah tetap 401 (tidak membocorkan siapa akun Studio).
        $this->postJson('/api/login', ['identifier' => 'pemilik@sidik.test', 'password' => 'salah'], self::STUDIO)->assertUnauthorized();
    }

    public function test_studio_akun_kosong_menutup_login_dan_semua_rute(): void
    {
        config(['studio.akun' => '']);

        $this->postJson('/api/login', ['identifier' => 'pemilik@sidik.test', 'password' => self::SANDI], self::STUDIO)
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_studio');

        // Token studio yang sudah beredar pun mati.
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/studio/workbook')
            ->assertForbidden()
            ->assertJsonPath('kode', 'studio_tertutup');
    }

    public function test_token_studio_mati_begitu_studio_akun_diganti(): void
    {
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;

        config(['studio.akun' => 'orang.baru@sidik.test']);

        $this->withToken($token)->getJson('/api/studio/workbook')
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_studio');
    }

    public function test_aplikasi_lapangan_tidak_berubah_walau_akunnya_akun_studio(): void
    {
        // Tanpa X-Klien: token `internal` seperti biasa, dan hanya itu.
        $respons = $this->postJson('/api/login', ['identifier' => 'pemilik@sidik.test', 'password' => self::SANDI])->assertOk();
        $token = PersonalAccessToken::findToken((string) $respons->json('data.token'));
        $this->assertSame(['internal'], $token->abilities);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $respons->json('data.token'))->getJson('/api/me')->assertOk();

        // ...dan token lab itu TIDAK membuka Studio, walau akunnya akun Studio.
        $this->app['auth']->forgetGuards();
        $this->withToken((string) $respons->json('data.token'))->getJson('/api/studio/workbook')
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_token_studio');
    }

    public function test_token_lab_dan_sesi_apa_pun_ditolak_di_rute_studio(): void
    {
        $uri = '/api/studio/workbook';
        $lain = [User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN];

        foreach ($lain as $role) {
            $user = $this->akun($role, $role.'@lab.test');

            // Token lab baru (`internal`) dan lama (wildcard).
            foreach ([['internal'], ['*']] as $abilities) {
                Sanctum::actingAs($user, $abilities);
                $this->getJson($uri)->assertForbidden();
                $this->postJson($uri.'/impor', [])->assertForbidden();
                $this->postJson($uri."/{$this->wb->id}/hitung", ['edit' => []])->assertForbidden();
                $this->app['auth']->forgetGuards();
            }

            // Sesi web/panel (TransientToken) juga tidak.
            $this->actingAs($user)->getJson($uri)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }

        // Akun Studio sendiri dengan token/sesi LAB tetap tertolak di Studio.
        Sanctum::actingAs($this->pemilik, ['*']);
        $this->getJson($uri)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->pemilik)->getJson($uri)->assertForbidden();
        $this->app['auth']->forgetGuards();

        // Token studio milik akun yang BUKAN akun Studio (mis. dicetak tangan).
        Sanctum::actingAs($this->akun(User::ROLE_SUPER_ADMIN, 'super@lab.test'), ['studio']);
        $this->getJson($uri)->assertForbidden()->assertJsonPath('kode', 'bukan_akun_studio');
        $this->app['auth']->forgetGuards();

        $this->getJson($uri)->assertUnauthorized();
    }

    public function test_token_studio_ditolak_di_rute_lab(): void
    {
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;

        foreach (['/api/me', '/api/me/permissions', '/api/equipments', '/api/calibrations', '/api/certificates', '/api/data-acuan/paket'] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_aplikasi_ini');
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/logout')->assertForbidden();
    }

    public function test_akun_studio_nonaktif_401_dan_semua_tokennya_dicabut(): void
    {
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;
        $this->pemilik->forceFill(['status' => User::STATUS_NONAKTIF])->save();

        $this->withToken($token)->getJson('/api/studio/workbook')
            ->assertUnauthorized()
            ->assertJsonPath('kode', 'akun_nonaktif');

        $this->assertSame(0, $this->pemilik->tokens()->count());
    }

    public function test_studio_me_memuat_izin_penuh_dan_keluar_mencabut_token(): void
    {
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;

        $me = $this->withToken($token)->getJson('/api/studio/me')->assertOk();
        $me->assertJsonPath('data.user.email', 'pemilik@sidik.test')->assertJsonPath('data.izin.role', 'studio');
        $this->assertEqualsCanonicalizing(
            ['workbook.lihat', 'workbook.hitung', 'workbook.impor', 'workbook.sel-terkunci.sunting'],
            $me->json('data.izin.boleh'),
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/studio/logout')->assertOk();
        $this->assertSame(0, $this->pemilik->tokens()->count());
    }

    public function test_izin_workbook_tidak_bocor_ke_matriks_izin_lab(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $boleh = $this->actingAs($this->akun($role, "izin.{$role}@lab.test"))->getJson('/api/me/permissions')->assertOk()->json('data.boleh');
            $this->assertSame([], array_values(array_filter($boleh, static fn (string $i): bool => str_starts_with($i, 'workbook.'))), $role);
            $this->app['auth']->forgetGuards();
        }
    }

    private function akun(string $role, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            'email' => $email,
            'password' => Hash::make(self::SANDI),
        ]);
    }
}
