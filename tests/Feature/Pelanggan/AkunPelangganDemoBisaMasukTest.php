<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
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

    /**
     * Seed ulang TIDAK menghidupkan akun yang sengaja dimatikan admin.
     *
     * Aturan `MenyetelSandiAwal` menjaga sandi, dan itu benar tapi tidak cukup:
     * `status` juga keputusan orang. Admin yang memutuskan akun demo tidak
     * boleh dipakai lagi menyetelnya `nonaktif` — lalu satu `SEED_ON_BOOT=true`
     * berikutnya (ritual yang `docker/entrypoint.sh` sebut sebagai satu-satunya
     * cara menambal master data di paket gratis Render) menghidupkannya lagi,
     * diam-diam, berminggu-minggu sesudah keputusannya diambil.
     */
    public function test_seed_ulang_tidak_menghidupkan_akun_yang_dimatikan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', AkunPelangganDemoSeeder::EMAIL)->firstOrFail();
        $user->forceFill(['status' => User::STATUS_NONAKTIF, 'name' => 'JANGAN DIPAKAI'])->save();
        CustomerMember::where('user_id', $user->id)
            ->update(['status' => CustomerMember::STATUS_NONAKTIF]);

        $this->seed(AkunPelangganDemoSeeder::class);

        $user->refresh();

        $this->assertSame(User::STATUS_NONAKTIF, $user->status, 'seed ulang menghidupkan akun yang dimatikan');
        $this->assertSame('JANGAN DIPAKAI', $user->name);
        $this->assertSame(
            0,
            CustomerMember::where('user_id', $user->id)
                ->where('status', CustomerMember::STATUS_AKTIF)
                ->count(),
            'seed ulang menghidupkan keanggotaan yang dimatikan',
        );
    }

    /**
     * Seed ulang TIDAK menumpuk keanggotaan di perusahaan kedua.
     *
     * Seeder memilih perusahaan dengan alat TERBANYAK, dan puncak itu bergeser
     * di lab yang hidup. Dengan `updateOrCreate` berkunci (customer_id,
     * user_id), seed berikutnya membuat baris KEDUA alih-alih memindahkan yang
     * pertama — akun demo jadi PIC Utama di dua perusahaan pelanggan SUNGGUHAN
     * sekaligus, dan lewat `X-Perusahaan-Id` bisa membaca sertifikat keduanya
     * serta menerbitkan undangan di keduanya. Tiap seed berikutnya yang
     * puncaknya bergeser menambah satu lagi.
     */
    public function test_seed_ulang_tidak_menumpuk_keanggotaan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', AkunPelangganDemoSeeder::EMAIL)->firstOrFail();
        $semula = CustomerMember::where('user_id', $user->id)->firstOrFail()->customer_id;

        // Perusahaan LAIN naik jadi yang alatnya terbanyak.
        $lain = Customer::where('id', '!=', $semula)->firstOrFail();
        Equipment::where('customer_id', $semula)->update(['customer_id' => $lain->id]);

        $this->seed(AkunPelangganDemoSeeder::class);

        $this->assertSame(
            1,
            CustomerMember::where('user_id', $user->id)->count(),
            'akun demo dapat keanggotaan di perusahaan pelanggan kedua',
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
