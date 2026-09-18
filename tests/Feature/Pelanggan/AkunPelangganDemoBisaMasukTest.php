<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CustomerMember;
use App\Models\User;
use Database\Seeders\AkunPelangganDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pintu pelanggan bisa dimasuki — lewat HTTP sungguhan, bukan lewat barisnya
 * yang ada di database.
 *
 * ## Kenapa berkas ini ada
 *
 * Waktu pendaftaran mandiri dicabut (akun lahir dari undangan), yang diuji cuma
 * bahwa pintu daftar mandiri sudah TERTUTUP —
 * `PintuDaftarMandiriTertutupTest`. Tidak ada satu pun test yang pernah MASUK
 * lewat pintu pelanggan sesudah itu.
 *
 * Jalur lahirnya akun pertama sebenarnya ADA:
 * `POST /api/customers/{customer}/undangan` (admin lab, ability `internal`) —
 * jadi ini bukan ayam-telur. Yang tidak ada cuma akun buat MENCOBANYA: seeder
 * memuat pelanggan, alat, dan sesi, tapi nol akun pelanggan. Jadi seluruh modul
 * ini — 30-an endpoint — tidak pernah sekali pun dijalankan dari sisi yang
 * memakainya, di mesin siapa pun.
 *
 * Yang dijaga berkas ini: sesudah `db:seed`, ada satu akun yang benar-benar bisa
 * masuk, dan dia PIC utama sehingga rantai undangan punya titik awal.
 */
class AkunPelangganDemoBisaMasukTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modul pelanggan dinyalakan buat test ini.
     *
     * Di `.env` dia `FITUR_PELANGGAN=false`, dan waktu mati SELURUH pintu
     * pelanggan pulang **503** — termasuk `/auth/masuk`. Itu sakelar produksi
     * yang sah (modulnya belum dibuka buat pelanggan), tapi kalau test ini ikut
     * mati, yang dijaga berkas ini jadi nol: 503 lolos sebagai "ya memang belum
     * dibuka" dan lubang akun-pertama yang sebenarnya tetap tidak kelihatan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['pelanggan.fitur' => true]);
    }

    public function test_akun_pelanggan_demo_bisa_masuk_lewat_pintu_pelanggan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $balasan = $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => AkunPelangganDemoSeeder::EMAIL,
            'sandi' => 'rahasia123',
            'nama_perangkat' => 'uji',
        ]);

        $balasan->assertSuccessful();
        $this->assertNotEmpty(
            $balasan->json('data.token'),
            'masuk berhasil tapi tidak memulangkan token — aplikasi pelanggan tidak bisa lanjut',
        );
    }

    /**
     * Dan akunnya PIC Utama, bukan staf.
     *
     * Bedanya menentukan: cuma PIC Utama yang boleh menerbitkan undangan
     * (`peran:pic_utama` di `routes/api_pelanggan.php`). Kalau akun pertama ini
     * cuma staf, rantai undangannya tetap tidak punya titik awal dan lubang yang
     * berkas ini tutup terbuka lagi — dengan gejala yang sama: nol error.
     */
    public function test_akun_pertama_pic_utama_jadi_bisa_mengundang_anggota_lain(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', AkunPelangganDemoSeeder::EMAIL)->firstOrFail();

        $this->assertSame(User::ROLE_PELANGGAN, $user->role);
        $this->assertSame(User::STATUS_AKTIF, $user->status);

        $anggota = CustomerMember::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CustomerMember::PERAN_PIC_UTAMA, $anggota->peran);
        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggota->status);
    }

    /**
     * Seed ulang TIDAK mengembalikan sandi yang sudah diganti orang.
     *
     * `SEED_ON_BOOT` dimaksudkan dinyalakan sekali waktu deploy pertama, tapi
     * "sekali" itu janji manusia. Sekali dia tertekan lagi — buat menambal
     * master data, misalnya — akun yang sandinya `updateOrCreate` akan balik ke
     * bawaan yang tertulis terbuka di repo publik ini, diam-diam dan tanpa
     * error. Aturannya sudah ditulis di `MenyetelSandiAwal`; test ini yang
     * membuktikan seeder pelanggan ikut memakainya.
     */
    public function test_seed_ulang_tidak_mereset_sandi_yang_sudah_diganti(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', AkunPelangganDemoSeeder::EMAIL)->firstOrFail();
        $user->forceFill(['password' => Hash::make('sandi-baru-pilihan-saya')])->save();

        $this->seed(AkunPelangganDemoSeeder::class);

        $this->assertTrue(
            Hash::check('sandi-baru-pilihan-saya', $user->fresh()->password),
            'seed ulang mengembalikan sandi akun pelanggan ke bawaan',
        );
    }

    /** Akun lab tetap DITOLAK di pintu pelanggan — pintunya tidak jadi longgar. */
    public function test_akun_lab_tetap_ditolak_di_pintu_pelanggan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => 'admin@sidik.test',
            'sandi' => 'rahasia123',
            'nama_perangkat' => 'uji',
        ])->assertStatus(403);
    }
}
