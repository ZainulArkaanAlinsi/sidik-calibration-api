<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Gerbang `aplikasi:` — token dari aplikasi mana yang boleh lewat.
 *
 * Dua aplikasi Android memakai satu backend. `role:` menahan sebagian, tapi
 * role bukan jawaban buat "token ini dikeluarkan buat siapa": admin yang
 * entah bagaimana memegang token pelanggan tetap admin.
 *
 * Requirement: 02-SRS REQ-AUTH-07 (login dipisah per aplikasi) dan 03-SDD §3.1.
 *
 * ## `vendor/bin/pint` SENGAJA tidak dijalankan pada berkas ini
 *
 * Preset bawaannya memuat `php_unit_method_casing`, yang mengubah
 * `test_REQ_AUTH_07_...` jadi `test_re_q_aut_h_07_...` — ID requirement-nya
 * hancur jadi tidak terbaca. 02-SRS meminta ID itu muncul di nama test justru
 * supaya requirement dan testnya bisa ditelusuri dua arah, jadi yang dimenangkan
 * konvensi dokumennya, bukan preset formatter. Repo ini juga tidak menegakkan
 * pint menyeluruh: tujuh berkas test lain sudah "gagal" `pint --test` sejak
 * sebelum berkas ini ada.
 */
class GerbangAplikasiTokenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
    }

    private function orang(string $role, string $status = User::STATUS_AKTIF): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => $role,
            'status' => $status,
            'password' => Hash::make('sandi-uji-yang-panjang'),
        ]);
    }

    /** @param  list<string>  $ability */
    private function bearer(User $user, array $ability): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('uji', $ability)->plainTextToken];
    }

    // ------------------------------------------------------------ REQ-AUTH-07

    /** Akun pelanggan ditolak di login internal, dengan pengarahan yang jelas. */
    public function test_REQ_AUTH_07_akun_pelanggan_ditolak_di_login_internal(): void
    {
        $pelanggan = $this->orang(User::ROLE_PELANGGAN);

        $res = $this->postJson('/api/login', [
            'identifier' => $pelanggan->email,
            'password' => 'sandi-uji-yang-panjang',
        ])->assertForbidden();

        $this->assertStringContainsStringIgnoringCase(
            'SIDIK Pelanggan',
            (string) $res->json('message'),
            'Pesannya nggak mengarahkan ke aplikasi yang benar, jadi orangnya mentok tanpa tahu harus ke mana.'
        );
    }

    /** Sandi yang SALAH pun tetap ditolak sebagai akun pelanggan, bukan bocor jadi 401. */
    public function test_REQ_AUTH_07_penolakan_tidak_bergantung_sandi_benar(): void
    {
        $pelanggan = $this->orang(User::ROLE_PELANGGAN);

        $this->postJson('/api/login', [
            'identifier' => $pelanggan->email,
            'password' => 'jelas-salah-sekali',
        ])->assertStatus(401);
    }

    // -------------------------------------------------------------- ability

    /** Login internal sekarang mencetak token ber-ability `internal`, bukan wildcard. */
    public function test_login_internal_memberi_ability_internal(): void
    {
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->postJson('/api/login', [
            'identifier' => $teknisi->email,
            'password' => 'sandi-uji-yang-panjang',
        ])->assertOk();

        $this->assertSame(['internal'], $teknisi->tokens()->sole()->abilities);
    }

    /** Token ber-ability pelanggan ditolak di rute internal. */
    public function test_token_pelanggan_ditolak_di_rute_internal(): void
    {
        $pelanggan = $this->orang(User::ROLE_PELANGGAN);

        $this->withHeaders($this->bearer($pelanggan, ['pelanggan']))
            ->getJson('/api/equipments')
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_aplikasi_ini');
    }

    /** Token internal tetap diterima — ini jalur teknisi yang sedang dipakai. */
    public function test_token_internal_diterima_di_rute_internal(): void
    {
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->withHeaders($this->bearer($teknisi, ['internal']))
            ->getJson('/api/equipments')
            ->assertOk();
    }

    // ------------------------------------------------------- token lama `['*']`

    /**
     * Token teknisi yang SUDAH BEREDAR tetap jalan.
     *
     * Abilitynya `['*']` (default `createToken`), dan `PersonalAccessToken::can()`
     * meloloskan wildcard. Kalau test ini merah, artinya deploy Fase 4 bakal
     * me-logout setiap teknisi yang sedang login di lapangan.
     */
    public function test_token_wildcard_lama_masih_diterima_sebelum_cutoff(): void
    {
        config(['pelanggan.cutoff_token_lama' => null]);
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->withHeaders($this->bearer($teknisi, ['*']))
            ->getJson('/api/equipments')
            ->assertOk();
    }

    /** Sesudah cutoff, token wildcard ditolak — lingkupnya kelewat lebar. */
    public function test_token_wildcard_ditolak_sesudah_cutoff(): void
    {
        config(['pelanggan.cutoff_token_lama' => now()->subDay()->toDateString()]);
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->withHeaders($this->bearer($teknisi, ['*']))
            ->getJson('/api/equipments')
            ->assertStatus(401)
            ->assertJsonPath('kode', 'token_lama');
    }

    /** Cutoff yang belum lewat nggak menolak apa pun. */
    public function test_token_wildcard_masih_diterima_sebelum_tanggal_cutoff(): void
    {
        config(['pelanggan.cutoff_token_lama' => now()->addMonth()->toDateString()]);
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->withHeaders($this->bearer($teknisi, ['*']))
            ->getJson('/api/equipments')
            ->assertOk();
    }

    // ------------------------------------------------------------- regresi

    /**
     * Permintaan TANPA access token tetap lolos.
     *
     * Bukan kelonggaran yang kebetulan: 1019 pemanggilan `actingAs($user)` di
     * suite ini datang lewat guard `web` (Sanctum membungkusnya `TransientToken`),
     * dan 41 pemanggilan `actingAs($user, 'sanctum')` bikin
     * `currentAccessToken()` null. Di produksi keduanya cuma bisa berarti sesi
     * Filament — yang `canAccessPanel()`-nya sudah menuntut admin aktif — dan
     * pelanggan nggak punya jalan mendapat sesi web sama sekali.
     */
    public function test_permintaan_tanpa_access_token_tetap_lolos(): void
    {
        $teknisi = $this->orang(User::ROLE_TEKNISI);

        $this->actingAs($teknisi, 'sanctum')->getJson('/api/equipments')->assertOk();
        $this->actingAs($this->orang(User::ROLE_ADMIN))->getJson('/api/equipments')->assertOk();
    }

    /** Register internal tetap cuma mencetak teknisi, apa pun yang dikirim klien. */
    public function test_register_internal_tetap_cuma_bikin_teknisi(): void
    {
        $this->postJson('/api/register', [
            'nama' => 'Calon Teknisi',
            'employee_id' => 'SDK-9911',
            'department' => 'Kalibrasi',
            'email' => 'calon-teknisi@contoh.test',
            'password' => 'sandi-uji-yang-panjang',
            'password_confirmation' => 'sandi-uji-yang-panjang',
            'role' => User::ROLE_ADMIN,
        ])->assertCreated();

        $baru = User::where('email', 'calon-teknisi@contoh.test')->sole();

        $this->assertSame(User::ROLE_TEKNISI, $baru->role);
        $this->assertSame(User::STATUS_PENDING, $baru->status);
    }

    // -------------------------------------------------------------- NFR-02

    /** Tiga limiter bernama sesuai NFR-02 memang terdaftar. */
    public function test_NFR_02_throttle_pelanggan_terdaftar(): void
    {
        foreach (['pelanggan-daftar', 'pelanggan-masuk', 'pelanggan-otp'] as $nama) {
            $this->assertNotNull(
                RateLimiter::limiter($nama),
                "Limiter `{$nama}` nggak terdaftar di AppServiceProvider::rateLimiters()."
            );
        }
    }
}
