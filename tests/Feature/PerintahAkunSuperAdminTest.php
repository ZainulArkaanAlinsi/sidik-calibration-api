<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `akun:super-admin` — satu-satunya pintu yang melahirkan `super_admin`.
 *
 * Panel sengaja tidak menyediakannya: dropdown role di `/admin` dan
 * `Rule::in()` di `UserController` sama-sama dibangun dari `User::roles()`, yang
 * cuma memuat admin/teknisi/viewer. Kalau `super_admin` dimasukkan ke sana,
 * admin biasa bisa mempromosikan dirinya sendiri — yang diawasi mencetak yang
 * mengawasi.
 *
 * Karena pintunya cuma satu, penjagaannya harus benar di pintu itu: email
 * kembar, ID pegawai kembar, dan organisasi yang tidak ada semuanya ditolak
 * DENGAN exit code yang jujur. Perintah yang memulangkan sukses untuk akun yang
 * tidak jadi cuma bikin orangnya mengira sudah beres, lalu mencari kesalahan di
 * tempat yang salah.
 */
class PerintahAkunSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Organisasi tempat akunnya duduk — id-nya DIPEGANG, tidak diasumsikan 1.
     *
     * Perintahnya berdefault `--organisasi=1`, dan itu benar untuk lab ini.
     * Yang tidak benar: menganggap baris buatan factory pasti dapat id 1.
     * `AUTO_INCREMENT` MySQL tidak ikut di-rollback antar test, jadi test
     * kedua dan seterusnya mendapat organisasi ber-id 2, 3, 4 … dan
     * perintahnya menolak dengan benar ("Organisasi #1 tidak ada") — yang
     * merah penjaganya, bukan kodenya. Di SQLite tidak pernah kelihatan:
     * database-nya dibangun ulang tiap test, jadi id-nya selalu kembali ke 1.
     */
    private Organization $organisasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisasi = Organization::factory()->create();
    }

    public function test_bikin_akun_super_admin(): void
    {
        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--nama' => 'Pengawas Lab',
            '--paksa' => true,
        ])->assertSuccessful();

        $akun = User::where('email', 'pengawas@contoh.test')->firstOrFail();

        $this->assertSame(User::ROLE_SUPER_ADMIN, $akun->role);
        $this->assertSame(User::STATUS_AKTIF, $akun->status);
        $this->assertSame('Pengawas Lab', $akun->name);
    }

    /** Akunnya langsung terpakai: bisa masuk panel, dan nol wewenang menulis. */
    public function test_akunnya_langsung_bisa_masuk_panel(): void
    {
        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--paksa' => true,
        ])->assertSuccessful();

        $akun = User::where('email', 'pengawas@contoh.test')->firstOrFail();

        $this->assertTrue($akun->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($akun->isSuperAdmin());
        $this->assertFalse($akun->isAdmin(), 'Super admin ikut lolos gerbang tulis milik admin.');
    }

    /** Pembuatannya meninggalkan jejak — akun paling berwenang, paling wajib tercatat. */
    public function test_pembuatannya_tercatat_di_audit(): void
    {
        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--paksa' => true,
        ])->assertSuccessful();

        $akun = User::where('email', 'pengawas@contoh.test')->firstOrFail();

        $this->assertTrue(
            AuditLog::where('entity_type', 'users')->where('entity_id', $akun->id)->exists(),
            'Akun super admin lahir tanpa jejak audit.',
        );
    }

    /**
     * Sandinya TIDAK pernah `rahasia123`, termasuk di environment local/testing.
     *
     * Ini menjaga jebakan yang nyaris kejadian 24 Sep 2026: mesin kerja pemilik
     * proyek ber-`APP_ENV=local` sementara `.env`-nya menunjuk database
     * PRODUKSI. Aturan bersama `MenyetelSandiAwal::sandiAwal()` memulangkan
     * `rahasia123` persis di kondisi itu — sandi yang tertulis terbuka di
     * `AGENTS.md`, di repo publik — dan akun ini yang paling berwenang di
     * seluruh sistem.
     *
     * Test jalan di environment `testing`, jadi kalau suatu saat perintahnya
     * dikembalikan ke aturan bersama demi "satu definisi", test ini yang merah
     * duluan — bukan orang yang menemukan akun pengawas bisa dimasuki siapa pun
     * yang meng-clone repo.
     */
    public function test_sandinya_tidak_pernah_sandi_fixture(): void
    {
        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--paksa' => true,
        ])->assertSuccessful();

        $akun = User::where('email', 'pengawas@contoh.test')->firstOrFail();

        $this->assertFalse(
            Hash::check('rahasia123', $akun->password),
            'Akun super admin lahir dengan sandi fixture yang tertulis di dokumen publik.',
        );
    }

    public function test_email_yang_sudah_dipakai_ditolak(): void
    {
        $lama = User::factory()->admin()->create(['email' => 'pengawas@contoh.test']);

        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--paksa' => true,
        ])->assertFailed();

        // Dan yang lama tetap admin — perintah ini nggak menaikkan peran akun
        // yang sudah ada, karena perubahan wewenang tanpa layar yang
        // menunjukkannya ke orang lain itu yang mau dihindari.
        $this->assertSame(User::ROLE_ADMIN, $lama->fresh()->role);
    }

    public function test_id_pegawai_kembar_ditolak(): void
    {
        User::factory()->admin()->create(['employee_id' => 'SDK-0001']);

        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'pengawas@contoh.test',
            '--id-pegawai' => 'SDK-0001',
            '--paksa' => true,
        ])->assertFailed();

        $this->assertFalse(User::where('email', 'pengawas@contoh.test')->exists());
    }

    public function test_organisasi_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('akun:super-admin', [
            'email' => 'pengawas@contoh.test',
            '--organisasi' => 999,
            '--paksa' => true,
        ])->assertFailed();

        $this->assertFalse(User::where('email', 'pengawas@contoh.test')->exists());
    }

    public function test_email_ngawur_ditolak(): void
    {
        $this->artisan('akun:super-admin', [
            '--organisasi' => $this->organisasi->id,
            'email' => 'bukan-email',
            '--paksa' => true,
        ])->assertFailed();

        $this->assertSame(0, User::where('role', User::ROLE_SUPER_ADMIN)->count());
    }

    /** Tanpa `--paksa`, jawaban "tidak" berarti nol akun dibuat. */
    public function test_konfirmasi_bisa_membatalkan(): void
    {
        $this->artisan('akun:super-admin', [
            'email' => 'pengawas@contoh.test',
            '--organisasi' => $this->organisasi->id,
        ])
            ->expectsConfirmation(
                'Bikin akun super admin pengawas@contoh.test? Dia bisa MEMBACA data seluruh lab, termasuk lab lain.',
                'no',
            )
            ->assertFailed();

        $this->assertFalse(User::where('email', 'pengawas@contoh.test')->exists());
    }
}
