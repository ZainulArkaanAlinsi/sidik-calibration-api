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
            MetodeKalibrasiSeeder::class,
            CalibrationCapabilitySeeder::class,
            PhMeterCapabilitySeeder::class,
            // Turbidimeter (alat ke-2) — WAJIB abis CalibrationCapabilitySeeder
            // dengan alasan sama kayak PhMeterCapabilitySeeder.
            TurbidimeterCapabilitySeeder::class,
            ConductivityCapabilitySeeder::class,
            ChlorineCapabilitySeeder::class,
            RefractometerCapabilitySeeder::class,
            SpectrophotometerCapabilitySeeder::class,
            ViscometerCapabilitySeeder::class,
            DoMeterCapabilitySeeder::class,
            // Master data standar yang berdiri sendiri — nggak butuh kategori
            // atau alat, tapi harus ada sebelum sesi kalibrasi mana pun bisa
            // ngitung koreksi kondisi lingkungan.
            ThermohygroSeeder::class,
        ]);

        $this->seedUsers();

        $this->call([
            DemoDataSeeder::class,
            // Record kalibrasi ASLI (bukan demo) — lihat docblock seeder ini.
            PhMeterSeeder::class,
            // Standar turbidity + alat + sesi demo Turbidimeter (butuh user demo,
            // jadi abis seedUsers()).
            TurbidimeterSeeder::class,
            ChlorineSeeder::class,
            RefractometerSeeder::class,
            ConductivitySeeder::class,
            SpectrophotometerSeeder::class,
            ViscometerSeeder::class,
            DoMeterSeeder::class,
            // PALING BURITAN, dan wajib begitu: dia nambal alat yang UDAH ada
            // (rentang resolusi Turbidimeter) + ngisi pengaturan organisasi.
            // Jalan duluan, alatnya belum kebentuk dan tambalannya nggak kena
            // apa-apa — diam-diam, tanpa error.
            PengaturanSertifikatSeeder::class,
        ]);
    }

    /**
     * Akun dev buat mobile nyobain login (kredensialnya sesuai tabel di
     * docs/kontrak-api.md — kalau salah satu diubah, ubah dua-duanya).
     *
     * Yang terakhir sengaja `pending` — biar mobile bisa nyobain layar "akun
     * belum disetujui" tanpa harus daftar manual dulu.
     *
     * `sebelumnya` = ID pegawai versi lama (waktu project masih bernama ASMO).
     * Ada di sini supaya DB dev yang udah kepakai di-RENAME, bukan ditambahin
     * baris kembar: sesi kalibrasi & sertifikat nempel ke `users.id`, jadi kalau
     * bikin baris baru, riwayat demo pH-nya jadi yatim DAN akun lama tetep bisa
     * login. Boleh dihapus begitu semua DB dev udah kepindah.
     */
    private function seedUsers(): void
    {
        $accounts = [
            ['employee_id' => 'SDK-0001', 'sebelumnya' => 'ASM-0001', 'name' => 'Rina Kartika', 'email' => 'admin@sidik.test', 'department' => 'Quality Control', 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0002', 'sebelumnya' => 'ASM-0002', 'name' => 'Dimas Rahardjo', 'email' => 'teknisi@sidik.test', 'department' => 'Calibration', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0003', 'sebelumnya' => 'ASM-0003', 'name' => 'Sari Wijaya', 'email' => 'viewer@sidik.test', 'department' => 'Quality Control', 'role' => User::ROLE_VIEWER, 'status' => User::STATUS_AKTIF],
            ['employee_id' => 'SDK-0099', 'sebelumnya' => 'ASM-0099', 'name' => 'Eko Pending', 'email' => 'eko@sidik.test', 'department' => 'Calibration', 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_PENDING],
        ];

        foreach ($accounts as $account) {
            $sebelumnya = $account['sebelumnya'];
            unset($account['sebelumnya']);

            $atribut = [...$account, 'organization_id' => 1, 'password' => 'rahasia123'];

            $lama = User::query()
                ->whereIn('employee_id', [$account['employee_id'], $sebelumnya])
                ->first();

            $lama ? $lama->update($atribut) : User::create($atribut);
        }
    }
}
