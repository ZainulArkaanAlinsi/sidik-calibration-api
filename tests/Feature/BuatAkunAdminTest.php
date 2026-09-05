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

        // Pulang SUKSES, bukan gagal: setelan yang salah tidak boleh mematikan
        // API. Yang jadi sinyalnya alasan di log, dan akun yang tidak jadi.
        $this->artisan('akun:admin')
            ->expectsOutputToContain('bukan email yang sah')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_id_pegawai_kembar_ditolak(): void
    {
        // ID pegawai juga dipakai buat login, jadi yang kembar bikin dua orang
        // berebut satu identitas.
        $this->lab();
        User::factory()->create(['organization_id' => 1, 'employee_id' => 'SDK-0100']);

        $this->setelEnv('rohman@sidik.test', 'Pak Rohman', 'SDK-0100');

        $this->artisan('akun:admin')
            ->expectsOutputToContain('sudah dipakai akun lain')
            ->assertSuccessful();

        $this->assertNull(User::where('email', 'rohman@sidik.test')->first());
    }

    public function test_tanpa_organisasi_gagal_dengan_alasan_yang_kebaca(): void
    {
        // Tanpa penjagaannya yang muncul cuma galat foreign key di tengah boot.
        $this->setelEnv('rohman@sidik.test');

        $this->artisan('akun:admin')
            ->expectsOutputToContain('SEED_ON_BOOT')
            ->assertSuccessful();

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

    /**
     * `akun:admin` di entrypoint TIDAK boleh dibungkus `|| true`.
     *
     * Diadu ke berkasnya karena yang dijaga bukan perilaku PHP-nya melainkan
     * satu keputusan yang gampang "dirapikan" balik oleh orang berikutnya —
     * dan kalau itu terjadi, tidak ada satu pun test lain yang merah.
     *
     * Yang dipertaruhkan: `User` memakai trait `Diaudit`, dan aturannya sudah
     * tertulis di sana — kalau mencatat audit gagal, perubahannya ikut gagal,
     * karena perubahan yang tidak tercatat lebih berbahaya daripada perubahan
     * yang gagal. `User::create()` menulis barisnya DULU, baru event `created`
     * menulis `audit_logs`. Dengan `|| true`, akun admin yang terlanjur ada
     * tanpa jejak audit lolos begitu saja dan boot-nya lanjut seolah beres.
     *
     * Setelan yang salah tetap tidak mematikan API — itu diurus di dalam
     * perintahnya (pulang sukses dengan alasan di log), bukan dengan menelan
     * semua galat di sini.
     */
    public function test_entrypoint_tidak_menelan_galat_akun_admin(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString(
            'php artisan akun:admin',
            $entrypoint,
            'Panggilan `akun:admin` hilang dari entrypoint — akunnya tidak akan pernah dibuat.',
        );

        $this->assertStringNotContainsString(
            'php artisan akun:admin || true',
            $entrypoint,
            'Galat `akun:admin` ditelan lagi. Yang ikut tertelan bukan cuma setelan '
            .'yang salah (itu sudah pulang sukses sendiri), tapi kegagalan menulis '
            .'audit — dan akun admin tanpa jejak audit itu temuan buat lab '
            .'terakreditasi. Lihat trait Diaudit.',
        );
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
