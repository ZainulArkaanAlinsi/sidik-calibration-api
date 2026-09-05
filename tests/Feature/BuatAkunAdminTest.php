<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `akun:admin` — jalan tiap container nyala, jadi yang dijaga di sini bukan
 * "bisa bikin akun" (itu bagian gampangnya) tapi apa yang TIDAK boleh dia
 * lakukan waktu diulang ratusan kali.
 *
 * Tiga sifat yang kalau hilang, hilangnya diam-diam:
 *
 *   1. **Akun yang sudah ada tidak pernah disentuh.** Kalau dia ikut menaikkan
 *      role, orang yang sengaja diturunkan lewat panel naik lagi sendiri di
 *      deploy berikutnya. Buat lab terakreditasi itu temuan audit, dan tidak
 *      ada satu pun test fitur lain yang akan menyadarinya.
 *   2. **Sandi akun lama tidak pernah ditulis ulang.** Sama persis dengan
 *      alasan `SeederSandiAwalTest` ada.
 *   3. **Role admin + status AKTIF, dua-duanya.** Ini justru alasan perintah
 *      ini dibuat: form Filament memberi bawaan `teknisi` + `pending`, dan
 *      akun yang kena dua-duanya kelihatan jadi tapi menolak orangnya masuk.
 */
class BuatAkunAdminTest extends TestCase
{
    use RefreshDatabase;

    /** Organisasi #1 harus ada — perintahnya menolak jalan tanpa itu. */
    private function lab(): Organization
    {
        return Organization::factory()->create(['id' => 1]);
    }

    private function setelEnv(
        ?string $email,
        ?string $nama = null,
        ?string $idPegawai = null,
        ?string $departemen = null,
    ): void {
        config([
            'seeding.akun_admin.email' => $email,
            'seeding.akun_admin.nama' => $nama,
            'seeding.akun_admin.id_pegawai' => $idPegawai,
            'seeding.akun_admin.departemen' => $departemen,
        ]);
    }

    public function test_bikin_akun_admin_yang_langsung_aktif(): void
    {
        $this->lab();
        $this->setelEnv('rohman@sidik.test', 'Pak Rohman', 'SDK-0100', 'Manajemen');

        $this->artisan('akun:admin')->assertSuccessful();

        $akun = User::where('email', 'rohman@sidik.test')->firstOrFail();

        // Dua baris ini yang jadi alasan perintahnya ada. Bawaan form-nya
        // `teknisi` + `pending`, dan `AuthController` menolak akun pending
        // sebelum sandinya dicek.
        $this->assertSame(User::ROLE_ADMIN, $akun->role);
        $this->assertSame(User::STATUS_AKTIF, $akun->status);

        $this->assertSame(1, $akun->organization_id);
        $this->assertSame('Pak Rohman', $akun->name);
        $this->assertSame('SDK-0100', $akun->employee_id);
        $this->assertSame('Manajemen', $akun->department);
    }

    public function test_akun_yang_sudah_ada_tidak_disentuh_sama_sekali(): void
    {
        // Yang paling mahal kalau jebol. Perintah ini jalan tiap boot; kalau
        // dia menaikkan role, orang yang sengaja diturunkan lewat panel naik
        // lagi sendiri tanpa ada yang menyetujuinya.
        $this->lab();
        $lama = User::factory()->create([
            'organization_id' => 1,
            'email' => 'rohman@sidik.test',
            'role' => User::ROLE_VIEWER,
            'status' => User::STATUS_NONAKTIF,
            'password' => 'sandilama123',
        ]);

        $this->setelEnv('rohman@sidik.test', 'Nama Lain');

        $this->artisan('akun:admin')->assertSuccessful();

        $sesudah = $lama->fresh();

        $this->assertSame(User::ROLE_VIEWER, $sesudah->role);
        $this->assertSame(User::STATUS_NONAKTIF, $sesudah->status);
        $this->assertTrue(
            Hash::check('sandilama123', $sesudah->password),
            'Sandi akun yang sudah ada ditulis ulang.',
        );
        $this->assertSame(1, User::where('email', 'rohman@sidik.test')->count());
    }

    public function test_dijalankan_dua_kali_tidak_bikin_akun_kembar(): void
    {
        $this->lab();
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')->assertSuccessful();
        $this->artisan('akun:admin')->assertSuccessful();

        $this->assertSame(1, User::where('email', 'rohman@sidik.test')->count());
    }

    public function test_email_kosong_tidak_bikin_apa_apa(): void
    {
        $this->lab();
        $this->setelEnv(null);

        $this->artisan('akun:admin')->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_email_ngawur_ditolak_dan_tidak_bikin_akun(): void
    {
        // Email salah ketik tidak menghasilkan error di mana pun: akunnya
        // kebentuk, lalu orangnya tidak pernah bisa login.
        $this->lab();
        $this->setelEnv('rohman[at]sidik.test');

        $this->artisan('akun:admin')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_id_pegawai_kembar_ditolak(): void
    {
        // ID pegawai juga dipakai buat login, jadi yang kembar bikin dua orang
        // berebut satu identitas.
        $this->lab();
        User::factory()->create(['organization_id' => 1, 'employee_id' => 'SDK-0100']);

        $this->setelEnv('rohman@sidik.test', 'Pak Rohman', 'SDK-0100');

        $this->artisan('akun:admin')->assertFailed();

        $this->assertNull(User::where('email', 'rohman@sidik.test')->first());
    }

    public function test_tanpa_organisasi_gagal_dengan_alasan_yang_kebaca(): void
    {
        // Tanpa penjagaannya yang muncul cuma galat foreign key di tengah boot.
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')
            ->expectsOutputToContain('SEED_ON_BOOT')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_di_luar_lokal_sandinya_dari_environment(): void
    {
        $this->lab();
        $this->app['env'] = 'production';
        config(['seeding.sandi_awal' => 'sandi-dari-environment']);
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')->assertSuccessful();

        $akun = User::where('email', 'rohman@sidik.test')->firstOrFail();

        $this->assertTrue(Hash::check('sandi-dari-environment', $akun->password));
        $this->assertFalse(
            Hash::check('rahasia123', $akun->password),
            'Sandi bawaan laptop kepakai di luar lingkungan lokal. `rahasia123` '
            .'tercetak di repo yang publik ini.',
        );
    }

    /**
     * Jalur yang paling gampang bocor tanpa ketahuan: satu-satunya yang
     * BENERAN jalan di produksi, dan satu-satunya yang tidak pernah tersentuh
     * kalau testnya cuma jalan di lingkungan `testing` (di situ sandinya
     * selalu `rahasia123` dan berhenti sebelum baris cetaknya).
     *
     * Kalau sandi acaknya tidak tercetak, yang lahir adalah akun yang sandinya
     * TIDAK DIKETAHUI SIAPA PUN — dan perintah ini sengaja tidak pernah
     * menyetel ulang sandi akun yang sudah ada, jadi akunnya tidak bisa dipakai
     * selamanya. Gagalnya sunyi: perintahnya tetap pulang sukses.
     */
    public function test_di_luar_lokal_sandi_acaknya_dicetak_ke_log(): void
    {
        $this->lab();
        $this->app['env'] = 'production';
        config(['seeding.sandi_awal' => null]);
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')
            ->expectsOutputToContain('SALIN SEKARANG')
            ->assertSuccessful();

        $akun = User::where('email', 'rohman@sidik.test')->firstOrFail();

        $this->assertFalse(Hash::check('rahasia123', $akun->password));
    }

    public function test_nama_kosong_jatuh_ke_email_bukan_baris_kosong(): void
    {
        $this->lab();
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')->assertSuccessful();

        $this->assertSame(
            'rohman@sidik.test',
            User::where('email', 'rohman@sidik.test')->firstOrFail()->name,
        );
    }
}
