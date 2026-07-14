<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Akun dev buat mobile nyobain login (kredensialnya sesuai contoh di
     * docs/kontrak-api.md). Pakai updateOrCreate biar aman di-seed berkali-kali.
     *
     * Yang terakhir sengaja `pending` — biar mobile bisa nyobain layar "akun
     * belum disetujui" tanpa harus daftar manual dulu.
     *
     * organization_id diisi 1 walaupun tabel organizations belum ada — FK-nya
     * nyusul waktu migration lengkap dibikin (lihat vault: ERD Awal).
     */
    public function run(): void
    {
        $accounts = [
            ['employee_id' => 'ASM-0001', 'name' => 'Admin ASMO', 'email' => 'admin@asmo.test', 'department' => 'Quality Control', 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'ASM-0002', 'name' => 'Teknisi ASMO', 'email' => 'teknisi@asmo.test', 'department' => 'Kalibrasi', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'ASM-0003', 'name' => 'Viewer ASMO', 'email' => 'viewer@asmo.test', 'department' => 'Quality Control', 'role' => User::ROLE_VIEWER, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'ASM-0099', 'name' => 'Eko Pending', 'email' => 'eko@asmo.test', 'department' => 'Kalibrasi', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_PENDING],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [...$account, 'organization_id' => 1, 'password' => 'rahasia123'],
            );
        }
    }
}
