<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\User;
use Database\Seeders\Concerns\MenyetelSandiAwal;
use Illuminate\Database\Seeder;

/**
 * Akun **pelanggan** contoh — supaya modul pelanggan bisa dicoba sama sekali.
 *
 * ## Kenapa seeder ini perlu ada
 *
 * Seeder memuat perusahaan pelanggan, alatnya, sesi kalibrasinya, sampai
 * sertifikatnya — tapi NOL akun pelanggan. Jadi tiga puluhan endpoint di
 * `routes/api_pelanggan.php` tidak pernah sekali pun dijalankan dari sisi yang
 * memakainya, di mesin siapa pun, sejak modulnya ditulis.
 *
 * Itu bukan ayam-telur: admin lab tetap bisa menerbitkan undangan pertama lewat
 * `POST /api/customers/{customer}/undangan` (dan, sejak sesi ini, lewat tombol
 * "Undang anggota" di panel). Yang hilang cuma akun buat MENCOBA — dan tanpa
 * akun, satu-satunya cara memeriksa modulnya adalah menempuh rantai undangan
 * lengkap lebih dulu, tiap kali database di-reset.
 *
 * ## Yang di-seed
 *
 *  - Satu `users` ber-role `pelanggan`, status aktif, sandi ikut aturan
 *    `MenyetelSandiAwal` (`rahasia123` di laptop & test; di server ikut
 *    `SEED_ADMIN_PASSWORD`).
 *  - Satu `customer_members` ber-peran **PIC Utama** yang menautkannya ke
 *    perusahaan pelanggan yang sudah ada — peran itu yang boleh mengundang
 *    anggota lain, jadi rantai undangannya punya titik awal tanpa admin lab.
 */
class AkunPelangganDemoSeeder extends Seeder
{
    // Sandi awal lewat trait bersama, BUKAN `env()` langsung: `env()` pulang
    // null begitu config di-cache, dan akun yang lahir dari situ sandinya acak
    // tanpa ada yang tahu.
    use MenyetelSandiAwal;

    /** Email & ID pegawai akun demo — dipakai juga oleh test. */
    public const EMAIL = 'pelanggan@sidik.test';

    public const EMPLOYEE_ID = 'PLG-0001';

    public function run(): void
    {
        // Perusahaan pelanggan yang PALING banyak alatnya, biar begitu login
        // layarnya tidak kosong. Kalau belum ada satu pun pelanggan, seeder ini
        // diam — bukan bikin perusahaan karangan.
        $perusahaan = Customer::query()
            ->where('organization_id', 1)
            ->withCount('equipments')
            ->orderByDesc('equipments_count')
            ->orderBy('id')
            ->first();

        if ($perusahaan === null) {
            $this->command?->warn('Belum ada pelanggan sama sekali — akun pelanggan demo dilewat.');

            return;
        }

        $atribut = [
            'organization_id' => 1,
            'employee_id' => self::EMPLOYEE_ID,
            'name' => 'Budi Pelanggan',
            'email' => self::EMAIL,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
        ];

        $user = User::query()->where('email', self::EMAIL)->first();

        // Akun yang SUDAH ADA tidak disentuh sama sekali — bukan cuma sandinya.
        //
        // Aturan `MenyetelSandiAwal` menjaga sandi, dan itu benar tapi tidak
        // cukup di sini. `status` juga keputusan orang: admin yang memutuskan
        // akun demo tidak boleh dipakai lagi menyetelnya `nonaktif`. Kalau
        // seeder ini menulisnya lewat `update()`, satu `SEED_ON_BOOT=true`
        // berikutnya — ritual yang docker/entrypoint.sh sebut sebagai
        // satu-satunya cara menambal master data di paket gratis Render —
        // MENGHIDUPKANNYA LAGI. Diam-diam, tanpa error, berminggu-minggu
        // sesudah keputusannya diambil.
        //
        // Jadi yang dikerjakan seed ulang cuma satu: memastikan akunnya PUNYA
        // keanggotaan, kalau memang belum punya sama sekali.
        if ($user !== null) {
            $this->pastikanPunyaKeanggotaan($user, $perusahaan);

            $this->command?->info(sprintf(
                'Akun pelanggan demo: %s sudah ada — tidak disentuh (sandi, status, maupun keanggotaannya).',
                self::EMAIL,
            ));

            return;
        }

        // Sandi cuma ikut waktu barisnya BARU — aturan `MenyetelSandiAwal`.
        $user = User::create([...$atribut, 'password' => $this->sandiAwal()]);
        $user->forceFill(['email_verified_at' => now()])->save();

        CustomerMember::create([
            'organization_id' => 1,
            'customer_id' => $perusahaan->getKey(),
            'user_id' => $user->getKey(),
            // PIC Utama, bukan staf: peran inilah yang boleh menerbitkan
            // undangan, jadi rantai undangan punya titik awal.
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->command?->info(sprintf(
            'Akun pelanggan demo: %s — PIC Utama %s.',
            self::EMAIL,
            $perusahaan->nama,
        ));
    }

    /**
     * Keanggotaan cuma DITAMBAH kalau akunnya belum punya satu pun yang aktif.
     *
     * Seeder ini memilih perusahaan dengan alat TERBANYAK, dan puncak itu
     * bergeser di lab yang hidup — begitu perusahaan lain melampauinya, seed
     * ulang yang `updateOrCreate`-nya berkunci (customer_id, user_id) membuat
     * baris KEDUA alih-alih memindahkan yang pertama. Akun demo lalu jadi PIC
     * Utama di dua perusahaan pelanggan SUNGGUHAN sekaligus, dan lewat header
     * `X-Perusahaan-Id` bisa membaca sertifikat keduanya serta menerbitkan
     * undangan di keduanya. Tiap seed berikutnya yang puncaknya bergeser
     * menambah satu lagi.
     *
     * Yang mana perusahaannya tidak penting — yang penting akunnya punya SATU
     * pintu masuk. Jadi begitu sudah punya, seeder ini berhenti.
     */
    private function pastikanPunyaKeanggotaan(User $user, Customer $perusahaan): void
    {
        // `exists()` tanpa menyaring status — SENGAJA. Keanggotaan yang
        // dinonaktifkan juga keputusan orang, sama persis dengan
        // `users.status`: admin yang mencabut akses akun demo menyetelnya
        // `nonaktif`, dan seeder yang cuma melihat "tidak ada yang AKTIF" bakal
        // menghidupkannya lagi di seed berikutnya — persis lubang yang blok di
        // atas ditutup buat akunnya.
        $sudahPunya = CustomerMember::query()
            ->where('user_id', $user->getKey())
            ->exists();

        if ($sudahPunya) {
            return;
        }

        CustomerMember::create([
            'organization_id' => 1,
            'customer_id' => $perusahaan->getKey(),
            'user_id' => $user->getKey(),
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);
    }
}
