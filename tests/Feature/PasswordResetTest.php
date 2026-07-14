<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    public function test_email_terdaftar_dapat_link_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'teknisi@asmo.test']);

        $this->postJson('/api/forgot-password', ['email' => 'teknisi@asmo.test'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * Email yang NGGAK terdaftar dapat balasan yang sama persis.
     *
     * Disengaja: kalau dibedain, endpoint ini jadi alat buat nebak siapa aja yang
     * punya akun — tinggal coba ratusan email, yang balasannya beda berarti ada.
     */
    public function test_email_nggak_terdaftar_balasannya_sama_persis(): void
    {
        Notification::fake();

        $terdaftar = $this->postJson('/api/forgot-password', ['email' => 'ada@asmo.test']);
        User::factory()->create(['email' => 'ada@asmo.test']);
        $asing = $this->postJson('/api/forgot-password', ['email' => 'nggak-ada@asmo.test']);

        $asing->assertOk();
        $this->assertSame($terdaftar->json('message'), $asing->json('message'));

        Notification::assertNothingSentTo([User::firstWhere('email', 'ada@asmo.test')]);
    }

    public function test_reset_pakai_token_valid_ganti_password_dan_cabut_semua_token(): void
    {
        $user = User::factory()->create([
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordlama',
        ]);

        // Sesi lama yang masih hidup di HP lain.
        $user->createToken('hp-lama');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Password berhasil diubah. Silakan login lagi.');

        $this->assertTrue(Hash::check('passwordbaru123', $user->fresh()->password));

        // Justru karena orang me-reset password, sesi lama harus mati.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/login', [
            'identifier' => 'teknisi@asmo.test',
            'password' => 'passwordbaru123',
        ])->assertOk();
    }

    public function test_token_ngawur_ditolak_422(): void
    {
        User::factory()->create(['email' => 'teknisi@asmo.test']);

        $this->postJson('/api/reset-password', [
            'token' => 'token-karangan',
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Token reset nggak valid atau udah kadaluarsa. Minta link baru ya.');
    }

    public function test_konfirmasi_password_kalau_dikirim_dan_beda_ditolak(): void
    {
        User::factory()->create(['email' => 'teknisi@asmo.test']);

        $this->postJson('/api/reset-password', [
            'token' => 'apa-aja',
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'beda-sendiri',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_confirmation');
    }

    /**
     * Kontrak mobile cuma ngirim token + password (tanpa field konfirmasi) —
     * konfirmasinya dicek di UI. Backend harus nerima bentuk itu.
     */
    public function test_reset_tanpa_field_konfirmasi_tetap_jalan(): void
    {
        $user = User::factory()->create([
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordlama',
        ]);

        $this->postJson('/api/reset-password', [
            'token' => \Illuminate\Support\Facades\Password::createToken($user),
            'email' => 'teknisi@asmo.test',
            'password' => 'passwordbaru123',
        ])->assertOk();

        $this->assertTrue(Hash::check('passwordbaru123', $user->fresh()->password));
    }
}
