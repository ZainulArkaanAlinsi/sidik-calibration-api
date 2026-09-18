<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Akun pelanggan tidak bisa berubah jadi akun lab lewat layar Pengguna.
 *
 * ## Kegagalan yang dijaga
 *
 * `users` menampung DUA populasi yang sangat berbeda: orang lab (admin,
 * teknisi, viewer) dan orang pelanggan. Keduanya duduk di `organization_id`
 * yang sama — pelanggan PT Sidik memang milik organisasi PT Sidik — jadi
 * penyaringan per-organisasi di panel tidak memisahkan mereka sama sekali.
 *
 * Akibatnya bukan sekadar daftar yang berisik. `role` di `UserForm`
 * `->required()` dan cuma punya tiga pilihan: Admin, Teknisi, Viewer. Admin
 * yang membuka baris pelanggan cuma buat membetulkan nomor telepon TERPAKSA
 * memilih salah satu dari tiga itu supaya formnya bisa disimpan — dan akun
 * pelanggan itu jadi akun lab, dengan akses ke seluruh data seluruh pelanggan.
 * Tidak ada peringatan di titik mana pun; formnya cuma "tidak mau tersimpan"
 * sampai admin menurutinya.
 *
 * Itu risiko R-D02 yang ditulis di docblock `User`, dan jalannya cuma dua klik.
 * Sisi API sudah lama dijaga `Rule::in(User::roles())`; panelnya belum.
 */
class AkunPelangganTidakBisaDipromosiDariPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->pelanggan = User::factory()->create([
            'email' => 'orang@pabrik.test',
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_akun_pelanggan_tidak_muncul_di_daftar_pengguna(): void
    {
        Livewire::test(ListUsers::class)
            ->assertCanNotSeeTableRecords([$this->pelanggan]);
    }

    /**
     * Dan barisnya tidak bisa dicapai lewat URL langsung.
     *
     * Hilang dari daftar saja tidak cukup: halaman Edit punya URL yang bisa
     * ditebak dari id, dan yang dijaga di sini bukan kerapian tampilan
     * melainkan satu-satunya pintu yang bisa mengubah rolenya.
     */
    public function test_halaman_edit_akun_pelanggan_tidak_bisa_dibuka(): void
    {
        $this->get(EditUser::getUrl(['record' => $this->pelanggan->getKey()]))
            ->assertNotFound();
    }

    /** Akun lab sendiri tetap kelihatan dan tetap bisa diubah. */
    public function test_akun_lab_tetap_bisa_dibuka(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$teknisi]);

        $this->get(EditUser::getUrl(['record' => $teknisi->getKey()]))->assertSuccessful();
    }

    /**
     * `super_admin` ikut disembunyikan.
     *
     * Nilainya sudah ada di ENUM sejak 16 Sep 2026 tapi perilakunya sengaja
     * belum dibangun (menunggu keputusan K4). Membiarkannya bisa diedit dari
     * panel berarti wewenang yang belum diputuskan itu bisa terpasang duluan
     * lewat dropdown — dan turun jadi `admin` biasa dalam sekali simpan.
     */
    public function test_akun_super_admin_juga_tidak_bisa_dibuka(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$super]);

        $this->get(EditUser::getUrl(['record' => $super->getKey()]))->assertNotFound();
    }

    /**
     * Pilihan role di form TIDAK boleh melebihi `User::roles()`.
     *
     * Penyaringan kueri di atas menutup jalur masuknya; ini menutup arah
     * sebaliknya — role di luar daftar internal tidak bisa DIPASANG ke akun lab
     * mana pun lewat form.
     */
    public function test_pilihan_role_di_form_cuma_role_internal(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        Livewire::test(EditUser::class, ['record' => $teknisi->getKey()])
            ->fillForm(['role' => User::ROLE_PELANGGAN])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertSame(User::ROLE_TEKNISI, $teknisi->fresh()->role);
    }
}
