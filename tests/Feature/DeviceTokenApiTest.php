<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pendaftaran perangkat buat push notification.
 *
 * Push cuma menutup satu celah yang websocket Reverb nggak bisa: HP dengan
 * aplikasi ketutup total. Yang diuji di sini bukan pengirimannya (itu butuh
 * FCM), tapi KEPEMILIKAN token — dan salah di situ bocornya ke layar kunci
 * orang lain, bukan sekadar notifikasi nyasar.
 */
class DeviceTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $teknisiLain;

    protected function setUp(): void
    {
        parent::setUp();

        $organisasi = Organization::factory()->create();
        $this->teknisi = User::factory()->create([
            'role' => 'teknisi',
            'organization_id' => $organisasi->id,
        ]);
        $this->teknisiLain = User::factory()->create([
            'role' => 'teknisi',
            'organization_id' => $organisasi->id,
        ]);
    }

    public function test_token_terdaftar_ke_pengguna_yang_login(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/device-tokens', [
                'token' => 'fcm-abc',
                'platform' => 'android',
                'versi_app' => '1.4.0',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'fcm-abc',
            'user_id' => $this->teknisi->id,
            'platform' => 'android',
            'versi_app' => '1.4.0',
        ]);
    }

    public function test_daftar_ulang_tidak_bikin_baris_dobel(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->teknisi)
                ->postJson('/api/device-tokens', [
                    'token' => 'fcm-abc',
                    'platform' => 'android',
                ])
                ->assertCreated();
        }

        $this->assertSame(1, DeviceToken::query()->where('token', 'fcm-abc')->count());
    }

    /**
     * HP yang dipakai gantian dapat token FCM yang SAMA. Kalau barisnya nggak
     * pindah tangan, notifikasi teknisi sebelumnya terus mendarat di HP yang
     * sekarang dipegang orang lain — lengkap dengan nomor sesi dan nama alat
     * pelanggan di layar kunci. Di tim ini HP tes memang dipakai gantian.
     */
    public function test_hp_yang_dipakai_gantian_pindah_kepemilikan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/device-tokens', ['token' => 'fcm-hp-bersama', 'platform' => 'android'])
            ->assertCreated();

        $this->actingAs($this->teknisiLain)
            ->postJson('/api/device-tokens', ['token' => 'fcm-hp-bersama', 'platform' => 'android'])
            ->assertCreated();

        $this->assertSame(1, DeviceToken::query()->where('token', 'fcm-hp-bersama')->count());
        $this->assertDatabaseHas('device_tokens', [
            'token' => 'fcm-hp-bersama',
            'user_id' => $this->teknisiLain->id,
        ]);
        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'fcm-hp-bersama',
            'user_id' => $this->teknisi->id,
        ]);
    }

    public function test_logout_mencabut_token(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/device-tokens', ['token' => 'fcm-abc', 'platform' => 'android'])
            ->assertCreated();

        $this->actingAs($this->teknisi)
            ->deleteJson('/api/device-tokens', ['token' => 'fcm-abc'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-abc']);
    }

    /** Logout nggak boleh gagal cuma gara-gara tokennya sudah kecabut duluan. */
    public function test_mencabut_token_yang_sudah_hilang_tetap_ok(): void
    {
        $this->actingAs($this->teknisi)
            ->deleteJson('/api/device-tokens', ['token' => 'nggak-pernah-ada'])
            ->assertOk();
    }

    public function test_tidak_bisa_mencabut_token_orang_lain(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/device-tokens', ['token' => 'fcm-punya-teknisi', 'platform' => 'android'])
            ->assertCreated();

        $this->actingAs($this->teknisiLain)
            ->deleteJson('/api/device-tokens', ['token' => 'fcm-punya-teknisi'])
            ->assertOk();

        // Tetap ada — yang dihapus dicocokkan ke pemiliknya, bukan cuma ke
        // tokennya. Menebak isi token orang lain nggak boleh bisa mematikan
        // notifikasinya.
        $this->assertDatabaseHas('device_tokens', [
            'token' => 'fcm-punya-teknisi',
            'user_id' => $this->teknisi->id,
        ]);
    }

    public function test_platform_asing_ditolak(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/device-tokens', ['token' => 'fcm-abc', 'platform' => 'blackberry'])
            ->assertUnprocessable();
    }

    public function test_tamu_tidak_bisa_mendaftar(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'fcm-abc', 'platform' => 'android'])
            ->assertUnauthorized();
    }
}
