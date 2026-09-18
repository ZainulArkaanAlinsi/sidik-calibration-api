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

        if ($user === null) {
            // Sandi cuma ikut waktu barisnya BARU — aturan `MenyetelSandiAwal`.
            // Kalau dia ikut di `update()` juga, tiap seed ulang mengembalikan
            // sandi yang sudah diganti orang ke bawaan yang tertulis terbuka di
            // repo publik ini. Diam-diam, tanpa error.
            $user = User::create([...$atribut, 'password' => $this->sandiAwal()]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $baru = true;
        } else {
            $user->update($atribut);
            $baru = false;
        }

        CustomerMember::updateOrCreate(
            ['customer_id' => $perusahaan->getKey(), 'user_id' => $user->getKey()],
            [
                'organization_id' => 1,
                // PIC Utama, bukan staf: peran inilah yang boleh menerbitkan
                // undangan, jadi rantai undangan punya titik awal.
                'peran' => CustomerMember::PERAN_PIC_UTAMA,
                'status' => CustomerMember::STATUS_AKTIF,
            ],
        );

        $this->command?->info(sprintf(
            'Akun pelanggan demo: %s — PIC Utama %s.%s',
            self::EMAIL,
            $perusahaan->nama,
            $baru ? '' : ' (akunnya sudah ada, sandinya tidak disentuh)',
        ));
    }
}
