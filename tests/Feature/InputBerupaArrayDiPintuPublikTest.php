<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * BUG-4 uji E2E-QA 18 Nov 2026: field pintu publik yang dikirim sebagai ARRAY
 * (`{"identifier":["a"]}`) menjawab 500 "Array to string conversion", bukan 422.
 *
 * Pelakunya `(string) $request->input(...)` — di kunci rate limiter `login` dan
 * limiter OTP/undangan pelanggan (jalan SEBELUM validasi), dan di
 * `prepareForValidation()` request pelanggan. Endpoint-nya publik tanpa token,
 * jadi 500 itu bergema di pemantauan dan menyembunyikan 500 sungguhan.
 *
 * Harapannya sama di semua pintu: 422 dengan pesan validasi pada field yang
 * salah tipe, tidak ada 500.
 */
class InputBerupaArrayDiPintuPublikTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
    }

    public function test_login_internal_dengan_identifier_atau_password_array_dijawab_422(): void
    {
        foreach ([
            ['identifier' => ['a'], 'password' => 'x', 'galat' => 'identifier'],
            ['identifier' => ['a' => ['b']], 'password' => 'x', 'galat' => 'identifier'],
            ['identifier' => 'a@b.test', 'password' => ['x'], 'galat' => 'password'],
            ['identifier' => ['a'], 'password' => ['x'], 'galat' => 'identifier'],
        ] as $kasus) {
            $galat = $kasus['galat'];
            unset($kasus['galat']);

            $this->postJson('/api/login', $kasus)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$galat]);
        }
    }

    public function test_login_internal_dengan_tipe_bukan_teks_lain_juga_422(): void
    {
        $this->postJson('/api/login', ['identifier' => 12345, 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);

        $this->postJson('/api/login', ['identifier' => true, 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    public function test_login_internal_teks_biasa_tetap_dihitung_per_akun_dan_tidak_berubah(): void
    {
        // Limiter `login` tetap menghitung per akun + IP untuk input teks:
        // 10 percobaan lewat (401), yang ke-11 429 — perilaku lama tidak bergeser.
        $kode = [];
        foreach (range(1, 11) as $i) {
            $kode[] = $this->postJson('/api/login', [
                'identifier' => ' Tidak.Ada@Contoh.Test ',
                'password' => 'salah',
            ])->getStatusCode();
        }

        $this->assertSame(array_fill(0, 10, 401), array_slice($kode, 0, 10));
        $this->assertSame(429, $kode[10]);
    }

    public function test_masuk_pelanggan_dengan_email_atau_sandi_array_dijawab_422(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => ['a@b.test'], 'sandi' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => 'a@b.test', 'sandi' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sandi']);

        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => ['a' => 1], 'sandi' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'sandi']);
    }

    public function test_lupa_sandi_pelanggan_dengan_email_array_dijawab_422(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => ['a@b.test']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_atur_ulang_sandi_pelanggan_dengan_field_array_dijawab_422(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => ['a@b.test'], 'otp' => ['123456'], 'sandi' => ['x'],
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'otp', 'sandi']);
    }

    public function test_terima_undangan_dengan_email_kode_atau_telepon_array_dijawab_422(): void
    {
        $dasar = [
            'email' => 'a@b.test', 'kode' => 'ABCD2345', 'nama' => 'Budi', 'sandi' => 'Sandi-Panjang-1',
            'telepon' => '0812-3456-7890', 'setuju_syarat' => true,
        ];

        foreach (['email', 'kode', 'telepon'] as $field) {
            $this->postJson('/api/pelanggan/v1/auth/terima-undangan', [...$dasar, $field => ['x']])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_input_array_tidak_menghabiskan_jatah_limiter_akun_teks(): void
    {
        // Kunci limiter untuk input yang bukan teks jatuh ke ember sendiri,
        // bukan ke ember akun bernama "array" atau "" milik orang lain.
        foreach (range(1, 12) as $i) {
            $this->postJson('/api/login', ['identifier' => ['a'], 'password' => 'x']);
        }

        // Sesudah banjir input array pun, input teks sah dari akun lain masih
        // sampai ke validasi/otentikasi (401), bukan terkena 429.
        $this->postJson('/api/login', ['identifier' => 'orang.lain@contoh.test', 'password' => 'salah'])
            ->assertStatus(401);
    }
}
