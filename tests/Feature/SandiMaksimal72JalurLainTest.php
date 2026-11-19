<?php

namespace Tests\Feature;

use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * R2-11 lanjutan (QA ronde 2-B): batas 72 BYTE (bcrypt memotong sisanya, jadi dua
 * sandi panjang yang berbeda dianggap sama) di empat jalur penetapan sandi yang
 * dulu belum dijaga:
 *
 *  1. `POST /api/reset-password`        (`ResetPasswordRequest`)
 *  2. `POST /api/technicians`           (`TechnicianRequest`)
 *  3. `POST /api/me/ganti-sandi`        (`GantiSandiSendiriRequest`)
 *  4. `/api/pelanggan/v1/...`: ganti-sandi, atur-ulang-sandi, terima-undangan
 *     (`Pelanggan\AturanPelanggan::aturanSandi()`)
 *
 * Yang dijaga: sandi BARU lebih dari 72 byte -> 422 pada field sandinya dengan
 * pesan jelas; tepat 72 byte (dan multibyte yang masih muat) diterima; sandi yang
 * SUDAH tersimpan tidak diperiksa (login tidak berubah). Jalur `POST /users` dan
 * reset oleh admin dijaga `SandiMaksimal72Test`.
 */
class SandiMaksimal72JalurLainTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    /** 72 byte, lolos aturan panjang minimum dan komposisi. */
    private function sandi72(): string
    {
        return str_repeat('Ab1#', 18);
    }

    /** 73 byte. */
    private function sandi73(): string
    {
        return $this->sandi72().'x';
    }

    private function pesanMenyebut72(?string $pesan): void
    {
        $this->assertStringContainsString('72', (string) $pesan);
    }

    // -------------------------------------------- 1. POST /api/reset-password

    public function test_reset_password_lewat_email_menolak_lebih_dari_72_byte(): void
    {
        $this->siapkanJalurPelanggan();
        $user = $this->adminLab(User::ROLE_TEKNISI);

        $kirim = fn (string $sandi) => $this->postJson('/api/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => $sandi,
            'password_confirmation' => $sandi,
        ]);

        $r = $kirim($this->sandi73())->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->pesanMenyebut72($r->json('errors.password.0'));

        // 37 huruf beraksen = 74 byte: lolos hitungan karakter, tetap terpotong bcrypt.
        $kirim(str_repeat('é', 37))->assertUnprocessable()->assertJsonValidationErrors(['password']);

        // Sandi lama tidak berubah oleh percobaan yang ditolak.
        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->fresh()->password));

        $kirim($this->sandi72())->assertOk();
        $this->assertTrue(Hash::check($this->sandi72(), (string) $user->fresh()->password));
    }

    // ------------------------------------------------ 2. POST /api/technicians

    public function test_buat_teknisi_menolak_sandi_awal_lebih_dari_72_byte(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => Organization::factory()->create()->id]);

        $badan = fn (string $sandi, string $n) => [
            'nama' => "Teknisi Baru {$n}",
            'employee_id' => "TEK-BARU-{$n}",
            'email' => "teknisi-baru-{$n}@contoh.test",
            'password' => $sandi,
        ];

        $r = $this->actingAs($admin)->postJson('/api/technicians', $badan($this->sandi73(), '1'))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->pesanMenyebut72($r->json('errors.password.0'));

        $this->actingAs($admin)->postJson('/api/technicians', $badan(str_repeat('é', 37), '2'))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseMissing('users', ['email' => 'teknisi-baru-1@contoh.test']);

        $this->actingAs($admin)->postJson('/api/technicians', $badan($this->sandi72(), '3'))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'teknisi-baru-3@contoh.test']);
    }

    // ----------------------------------------------- 3. POST /api/me/ganti-sandi

    public function test_ganti_sandi_sendiri_akun_lab_menolak_lebih_dari_72_byte(): void
    {
        $this->siapkanJalurPelanggan();
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $header = $this->bearerInternal($user);

        $badan = fn (string $sandi) => [
            'sandi_lama' => $this->sandiBenar,
            'sandi' => $sandi,
            'sandi_confirmation' => $sandi,
        ];

        $r = $this->postJson('/api/me/ganti-sandi', $badan($this->sandi73()), $header)
            ->assertUnprocessable()->assertJsonValidationErrors(['sandi']);
        $this->pesanMenyebut72($r->json('errors.sandi.0'));
        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->fresh()->password), 'sandi tidak boleh berubah');

        $this->permintaanBaru();
        $this->postJson('/api/me/ganti-sandi', $badan($this->sandi72()), $this->bearerInternal($user))->assertOk();
        $this->assertTrue(Hash::check($this->sandi72(), (string) $user->fresh()->password));
    }

    // ------------------------------------------------------ 4. dunia pelanggan

    public function test_pelanggan_ganti_sandi_menolak_lebih_dari_72_byte(): void
    {
        $this->siapkanJalurPelanggan();
        $pelanggan = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);

        $r = $this->withHeaders($this->bearer($pelanggan))
            ->postJson('/api/pelanggan/v1/saya/ganti-sandi', ['sandi_lama' => $this->sandiBenar, 'sandi' => $this->sandi73()])
            ->assertUnprocessable()->assertJsonValidationErrors(['sandi']);
        $this->pesanMenyebut72($r->json('errors.sandi.0'));
        $this->assertTrue(Hash::check($this->sandiBenar, (string) $pelanggan->fresh()->password));

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pelanggan))
            ->postJson('/api/pelanggan/v1/saya/ganti-sandi', ['sandi_lama' => $this->sandiBenar, 'sandi' => $this->sandi72()])
            ->assertOk();
        $this->assertTrue(Hash::check($this->sandi72(), (string) $pelanggan->fresh()->password));
    }

    public function test_pelanggan_atur_ulang_sandi_dan_terima_undangan_menolak_lebih_dari_72_byte(): void
    {
        $this->siapkanJalurPelanggan();

        $atur = fn (string $sandi) => $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => 'siapa-saja@contoh.test', 'otp' => '123456', 'sandi' => $sandi,
        ]);
        $terima = fn (string $sandi) => $this->postJson('/api/pelanggan/v1/auth/terima-undangan', [
            'email' => 'baru@contoh.test', 'kode' => 'ABCDEF123', 'nama' => 'Orang Baru', 'sandi' => $sandi,
            'telepon' => '+6281234567890', 'setuju_syarat' => true,
        ]);

        foreach (['atur-ulang-sandi' => $atur, 'terima-undangan' => $terima] as $nama => $kirim) {
            $r = $kirim($this->sandi73())->assertUnprocessable()->assertJsonValidationErrors(['sandi']);
            $this->pesanMenyebut72($r->json('errors.sandi.0'));

            // Tepat 72 byte lolos validasi sandi (galat lain, mis. kode/OTP salah, boleh muncul).
            $kirim($this->sandi72())->assertJsonMissingValidationErrors(['sandi']);
        }
    }

    /** Masuk dengan sandi LAMA yang panjang tidak diperiksa batas ini: akun lama tidak patah. */
    public function test_sandi_lama_lebih_dari_72_byte_tetap_bisa_dipakai_masuk(): void
    {
        $this->siapkanJalurPelanggan();
        $user = $this->adminLab(User::ROLE_TEKNISI);
        // Disimpan apa adanya (bcrypt memotong ke 72 byte saat hash), seperti akun yang dibuat sebelum batas ada.
        $user->forceFill(['password' => Hash::make(str_repeat('L', 90))])->save();

        $this->postJson('/api/login', ['identifier' => $user->email, 'password' => str_repeat('L', 90)])->assertOk();
    }
}
