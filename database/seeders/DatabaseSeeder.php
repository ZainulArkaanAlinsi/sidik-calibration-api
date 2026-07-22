<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Urutannya penting: organisasi dulu (semua nempel ke situ), baru kategori
     * (alat demo butuh kategori), baru data demo. `PhMeterCapabilitySeeder`
     * WAJIB abis `CalibrationCapabilitySeeder` — yang belakangan ngehapus
     * semua kemampuan kalibrasi di kategori `instrumen-analitik` sebelum
     * nulis ulang dari JSON, jadi kalau kebalik baris pH presisi penuhnya
     * ikut kehapus.
     */
    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            CalibrationCapabilitySeeder::class,
            PhMeterCapabilitySeeder::class,
        ]);

        $this->seedUsers();

        $this->call([
            DemoDataSeeder::class,
            // Record kalibrasi ASLI (bukan demo) — lihat docblock seeder ini.
            TirtaGraciaPhMeterSeeder::class,
        ]);
    }

    /**
     * Akun dev buat mobile nyobain login (kredensialnya sesuai contoh di
     * docs/kontrak-api.md). updateOrCreate biar aman di-seed berkali-kali.
     *
     * Yang terakhir sengaja `pending` — biar mobile bisa nyobain layar "akun
     * belum disetujui" tanpa harus daftar manual dulu.
     */
    private function seedUsers(): void
    {
        $accounts = [
            ['employee_id' => 'SDK-0001', 'name' => 'Admin Sidik', 'email' => 'admin@sidik.test', 'department' => 'Quality Control', 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0002', 'name' => 'Teknisi Sidik', 'email' => 'teknisi@sidik.test', 'department' => 'Kalibrasi', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0003', 'name' => 'Viewer Sidik', 'email' => 'viewer@sidik.test', 'department' => 'Quality Control', 'role' => User::ROLE_VIEWER, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0099', 'name' => 'Eko Pending', 'email' => 'eko@sidik.test', 'department' => 'Kalibrasi', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_PENDING],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [...$account, 'organization_id' => 1, 'password' => 'rahasia123'],
            );
        }
    }
}
