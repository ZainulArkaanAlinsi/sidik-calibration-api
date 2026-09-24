<?php

namespace Tests\Feature\Filament;

use App\Models\Organization;
use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiap orang yang bisa masuk panel harus bisa mengganti sandinya sendiri.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Sampai 24 Sep 2026 panel `/admin` tidak punya layar profil sama sekali, jadi
 * mengganti sandi cuma bisa lewat dua jalan yang dua-duanya bocor:
 *
 *   - admin lain menekan `resetPassword` di layar Pengguna — sandi barunya
 *     diketik dan diketahui orang lain;
 *   - `POST /forgot-password`, yang bergantung pada mailer produksi
 *     benar-benar mengirim. 7 Sep 2026 dua percobaan kirim tercatat gagal
 *     dengan alasan `MAIL_MAILER` masih `log`.
 *
 * Buat `super_admin` dua-duanya buntu sekaligus: akunnya sengaja tidak muncul
 * di layar Pengguna, jadi tidak ada yang bisa mereset-kan untuknya. Begitu
 * mailernya diam, dia terkunci selamanya di sandi acak 32 karakter yang cuma
 * dicetak sekali waktu akunnya dibuat.
 *
 * Yang dijaga di sini sempit dan sengaja: mengganti sandi SENDIRI bukan
 * "menulis data lab", jadi penjagaan baca-saja super admin (`HakTulisPanel`)
 * tidak boleh ikut menutupnya.
 */
class GantiSandiSendiriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    /** @return array<string, array{string}> */
    public static function peranPanel(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN],
            'super admin' => [User::ROLE_SUPER_ADMIN],
        ];
    }

    #[DataProvider('peranPanel')]
    public function test_layar_profil_bisa_dibuka(string $peran): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'role' => $peran,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs($user)
            ->get(filament()->getPanel('admin')->getProfileUrl())
            ->assertSuccessful();
    }

    /**
     * Sandi barunya benar-benar tersimpan.
     *
     * Layar yang kebuka tapi simpanannya tidak berlaku menghasilkan kegagalan
     * yang paling membingungkan: orangnya merasa sudah ganti, lalu terkunci di
     * percobaan berikutnya.
     */
    public function test_super_admin_bisa_menyimpan_sandi_baru(): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'password' => 'sandi-lama-yang-panjang',
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                // Sandi lama wajib — Filament menuntutnya, dan itu memang yang
                // diinginkan: sesi yang tertinggal terbuka di komputer bersama
                // nggak bisa dipakai mengambil alih akunnya.
                'currentPassword' => 'sandi-lama-yang-panjang',
                'password' => 'sandi-baru-yang-panjang',
                'passwordConfirmation' => 'sandi-baru-yang-panjang',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(
            Hash::check('sandi-baru-yang-panjang', $user->fresh()->password),
            'Sandi baru tidak tersimpan — orangnya merasa sudah ganti tapi terkunci.',
        );
    }

    /** Teknisi & viewer tetap tidak punya panel sama sekali — ini bukan pintu baru. */
    public function test_teknisi_tetap_tidak_bisa_masuk_panel(): void
    {
        $teknisi = User::factory()->create([
            'organization_id' => 1,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->assertFalse($teknisi->canAccessPanel(filament()->getPanel('admin')));
    }
}
