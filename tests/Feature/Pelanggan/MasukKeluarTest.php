<?php

namespace Tests\Feature\Pelanggan;

use App\Models\User;
use App\Services\Pelanggan\TokenPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-AUTH-03/07/08/09/10 — pintu masuk & keluar aplikasi pelanggan. */
class MasukKeluarTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
    }

    private function masuk(string $email, ?string $sandi = null): TestResponse
    {
        return $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => $email,
            'sandi' => $sandi ?? $this->sandiBenar,
            'nama_perangkat' => 'Pixel 8a Budi',
        ]);
    }

    public function test_anggota_aktif_dapat_token_ber_ability_pelanggan(): void
    {
        $user = $this->anggota();

        $balasan = $this->masuk((string) $user->email)
            ->assertOk()
            ->assertJsonPath('data.kemampuan', TokenPelanggan::ABILITY_PENUH)
            ->assertJsonPath('data.user.butuh_verifikasi', false)
            ->assertJsonStructure(['data' => ['token', 'kedaluwarsa_pada', 'kemampuan', 'user' => ['keanggotaan']]]);

        $this->assertCount(1, $balasan->json('data.user.keanggotaan'));

        $token = PersonalAccessToken::findToken(explode('|', (string) $balasan->json('data.token'), 2)[1]);

        $this->assertNotNull($token);
        $this->assertSame(['pelanggan'], $token->abilities);
        $this->assertSame('Pixel 8a Budi', $token->name);
    }

    /** REQ-AUTH-08 — umur token 90 hari, dicatat di baris tokennya. */
    public function test_REQ_AUTH_08_token_kedaluwarsa_sembilan_puluh_hari(): void
    {
        $user = $this->anggota();

        $balasan = $this->masuk((string) $user->email)->assertOk();

        $token = PersonalAccessToken::query()->latest('id')->firstOrFail();

        $this->assertNotNull($token->expires_at, 'Token pelanggan tanpa `expires_at` hidup selamanya.');
        $this->assertSame(
            now()->addDays(TokenPelanggan::BERLAKU_HARI)->toDateString(),
            $token->expires_at->toDateString(),
        );
        $this->assertSame($token->expires_at->toIso8601String(), $balasan->json('data.kedaluwarsa_pada'));
    }

    /** REQ-AUTH-03 — akun yang menunggu verifikasi boleh masuk, tapi tokennya sempit. */
    public function test_REQ_AUTH_03_akun_menunggu_verifikasi_dapat_token_menunggu(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);
        $this->pengajuan($user);

        $this->masuk((string) $user->email)
            ->assertOk()
            ->assertJsonPath('data.kemampuan', TokenPelanggan::ABILITY_MENUNGGU)
            ->assertJsonPath('data.user.butuh_verifikasi', true);
    }

    public function test_akun_yang_emailnya_belum_diverifikasi_ditolak_dengan_kode_sendiri(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_EMAIL);

        $this->masuk((string) $user->email)
            ->assertStatus(403)
            ->assertJsonPath('kode', 'email_belum_diverifikasi');
    }

    public function test_akun_nonaktif_ditolak(): void
    {
        $user = $this->pelanggan(User::STATUS_NONAKTIF);

        $this->masuk((string) $user->email)
            ->assertStatus(403)
            ->assertJsonPath('kode', 'akun_nonaktif');
    }

    /** REQ-AUTH-11 — akun yang sudah dianonimkan tidak bisa dipakai lagi. */
    public function test_akun_yang_sudah_dianonimkan_ditolak(): void
    {
        $user = $this->pelanggan(tambahan: ['dianonimkan_pada' => now()]);

        $this->masuk((string) $user->email)
            ->assertStatus(403)
            ->assertJsonPath('kode', 'akun_nonaktif');
    }

    /** REQ-AUTH-07 arah kedua: akun lab ditolak di pintu pelanggan. */
    public function test_REQ_AUTH_07_akun_internal_ditolak_di_pintu_pelanggan(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $user = User::factory()->create([
                'organization_id' => $this->organisasi()->id,
                'role' => $role,
                'status' => User::STATUS_AKTIF,
                'password' => Hash::make($this->sandiBenar),
            ]);

            $this->masuk((string) $user->email)
                ->assertStatus(403)
                ->assertJsonPath('kode', 'bukan_akun_pelanggan');
        }
    }

    /**
     * REQ-AUTH-10 — 5 kegagalan mengunci email itu 15 menit.
     *
     * Yang dibuktikan juga: sandi BENAR pun ditolak selama terkunci. Kalau
     * tidak, penguncian cuma memperlambat penebakan sampai tebakan yang benar
     * kebetulan datang.
     */
    public function test_REQ_AUTH_10_gagal_lima_kali_mengunci_email_itu(): void
    {
        $user = $this->anggota();

        for ($i = 0; $i < 5; $i++) {
            $this->masuk((string) $user->email, 'sandi-salah-banget')
                ->assertStatus(401)
                ->assertJsonPath('kode', 'kredensial_salah');
        }

        $this->masuk((string) $user->email)
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering');

        $this->travel(16)->minutes();

        $this->masuk((string) $user->email)->assertOk();
    }

    /** Penguncian menempel ke EMAIL, jadi akun lain tidak ikut terkunci. */
    public function test_kunci_gagal_masuk_tidak_menular_ke_akun_lain(): void
    {
        $korban = $this->anggota();
        $lain = $this->anggota();

        for ($i = 0; $i < 5; $i++) {
            $this->masuk((string) $korban->email, 'salah');
        }

        $this->masuk((string) $korban->email)->assertStatus(429);
        $this->masuk((string) $lain->email)->assertOk();
    }

    /** Masuk yang berhasil mengosongkan hitungan kegagalan. */
    public function test_masuk_berhasil_mengosongkan_hitungan_gagal(): void
    {
        $user = $this->anggota();

        for ($i = 0; $i < 4; $i++) {
            $this->masuk((string) $user->email, 'salah');
        }

        $this->masuk((string) $user->email)->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->masuk((string) $user->email, 'salah')->assertStatus(401);
        }
    }

    /** REQ-AUTH-10 — pesannya tidak membedakan "tidak terdaftar" dari "sandi salah". */
    public function test_pesan_gagal_sama_buat_email_asing_dan_sandi_salah(): void
    {
        $user = $this->anggota();

        $sandiSalah = $this->masuk((string) $user->email, 'salah')->assertStatus(401);
        $emailAsing = $this->masuk('asing@contoh.test', 'salah')->assertStatus(401);

        $this->assertSame($sandiSalah->json('message'), $emailAsing->json('message'));
        $this->assertSame($sandiSalah->json('kode'), $emailAsing->json('kode'));
    }

    public function test_keluar_mencabut_token_yang_dipakai_saja(): void
    {
        $user = $this->anggota();
        $lain = $this->bearer($user);
        $ini = $this->bearer($user);

        $this->withHeaders($ini)->postJson('/api/pelanggan/v1/auth/keluar')->assertOk();
        $this->lupakanSesiGuard();

        $this->withHeaders($ini)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();
        $this->lupakanSesiGuard();

        $this->withHeaders($lain)->getJson('/api/pelanggan/v1/saya')->assertOk();
    }

    /** REQ-AUTH-09 — keluar-semua mencabut semuanya, termasuk yang sedang dipakai. */
    public function test_keluar_semua_mencabut_seluruh_sesi(): void
    {
        $user = $this->anggota();
        $lain = $this->bearer($user);
        $ini = $this->bearer($user);

        $this->withHeaders($ini)->postJson('/api/pelanggan/v1/auth/keluar-semua')
            ->assertOk()
            ->assertJsonPath('data.sesi_dicabut', 2);

        $this->lupakanSesiGuard();
        $this->withHeaders($ini)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();

        $this->lupakanSesiGuard();
        $this->withHeaders($lain)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();
    }

    /** REQ-AUTH-08 — token yang lewat `expires_at` ditolak 401, bukan diterima diam-diam. */
    public function test_token_kedaluwarsa_ditolak(): void
    {
        $user = $this->anggota();
        $header = $this->bearer($user);

        // `bearer()` memakai `createToken()` tanpa `expires_at`, jadi umurnya
        // dipaksa lewat baris tokennya — yang diuji di sini pemeriksaan
        // Sanctum-nya, bukan penerbitannya (itu sudah diadu di atas).
        PersonalAccessToken::query()->latest('id')->firstOrFail()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeaders($header)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();
    }

    /** Flag modul mati → seluruh pintu pelanggan 503, termasuk `masuk`. */
    public function test_flag_mati_menutup_pintu_masuk(): void
    {
        $user = $this->anggota();
        config(['pelanggan.fitur' => false]);

        $this->masuk((string) $user->email)
            ->assertStatus(503)
            ->assertJsonPath('kode', 'belum_tersedia');
    }
}
