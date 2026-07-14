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
     * organization_id diisi 1 walaupun tabel organizations belum ada — FK-nya
     * nyusul waktu migration lengkap dibikin (lihat vault: ERD Awal).
     */
    public function run(): void
    {
        $accounts = [
            ['name' => 'Admin ASMO', 'email' => 'admin@asmo.test', 'role' => User::ROLE_ADMIN],
            ['name' => 'Teknisi ASMO', 'email' => 'teknisi@asmo.test', 'role' => User::ROLE_TEKNISI],
            ['name' => 'Viewer ASMO', 'email' => 'viewer@asmo.test', 'role' => User::ROLE_VIEWER],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'organization_id' => 1,
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'is_active' => true,
                    'password' => 'rahasia123',
                ],
            );
        }
    }
}
