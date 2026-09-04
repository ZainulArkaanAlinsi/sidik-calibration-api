<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\MenyetelSandiAwal;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use MenyetelSandiAwal;
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
            GasDetectorCapabilitySeeder::class,
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
            GasDetectorSeeder::class,
            // Autoklaf nyimpen olah datanya di `hasil_autoclave`, bukan
            // `uncertainty_calculations` — jadi dia nggak butuh seeder
            // kemampuan sendiri, cukup baris CMC dari lampiran akreditasi.
            AutoclaveSeeder::class,
            // TITS juga nggak punya seeder kemampuan sendiri: CMC ketujuh tipe
            // sensornya sudah ada di lampiran akreditasi, jadi cukup baris dari
            // `CalibrationCapabilitySeeder`.
            TitsSeeder::class,
            // Enclosure (Oven/Furnace/Bath/Inkubator/Refrigerator) — WAJIB abis
            // `CalibrationCapabilitySeeder` (butuh baris CMC per jenis enclosure).
            EnclosureSeeder::class,
            // Tiga alat suhu ber-PASANGAN deret (Thermocouple, Termometer
            // Gelas, Thermohygrometer) — alat ke-18..20. Sama seperti TITS &
            // Enclosure, CMC ketiganya sudah ada di lampiran akreditasi, jadi
            // cukup baris dari `CalibrationCapabilitySeeder`.
            //
            // WAJIB abis `TitsSeeder`: dua kalibrator suhu (Constant &
            // Yokogawa) dibuat di sana, dan seeder ini menautkan sesinya ke
            // baris itu — bukan membuat baris kembar yang bikin
            // `tautkanStandar()` memilih sewenang-wenang.
            Suhu3AlatSeeder::class,
            // Alat ke-21 — Timbangan, kelompok Massa, tiga sesi contoh dari
            // tiga revisi workbook master (kg / gram / substitusi). CMC-nya
            // sudah ada di lampiran akreditasi no. 12 (17 pita), jadi cukup
            // baris dari `CalibrationCapabilitySeeder`.
            //
            // WAJIB abis `ThermohygroSeeder`: sesinya menautkan TH-2 sebagai
            // sumber koreksi kondisi lingkungan.
            TimbanganSeeder::class,
            // Alat ke-22..24 — kelompok "Waktu dan Frekuensi", tiga sesi contoh
            // dari tiga workbook master (Tachometer / Centrifuge / Timer).
            // CMC ketiganya sudah ada di lampiran akreditasi no. 37, 38, 39,
            // jadi cukup baris dari `CalibrationCapabilitySeeder` — nggak perlu
            // seeder kemampuan sendiri.
            //
            // WAJIB abis `ThermohygroSeeder`: ketiga sesinya menautkan TH-4
            // sebagai sumber koreksi kondisi lingkungan.
            WaktuFrekuensiSeeder::class,
            // Alat ke-25 — kelompok Dimensi, sesi contoh dari workbook master
            // 25-50 mm. CMC-nya sudah ada di lampiran akreditasi no. 34, jadi
            // cukup baris dari `CalibrationCapabilitySeeder` — nggak perlu
            // seeder kemampuan sendiri.
            //
            // WAJIB abis `ThermohygroSeeder`: sesinya menautkan TH-3 sebagai
            // sumber koreksi kondisi lingkungan.
            MicrometerSeeder::class,
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
     * Sandi `rahasia123` yang ada di tabel itu berlaku di LAPTOP doang. Di
     * server sandinya dari `SEED_ADMIN_PASSWORD`, atau acak kalau nggak
     * disetel — lihat `MenyetelSandiAwal` buat duduk perkaranya.
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

            $atribut = [...$account, 'organization_id' => 1];

            $lama = User::query()
                ->whereIn('employee_id', [$account['employee_id'], $sebelumnya])
                ->first();

            // Sandi cuma ikut waktu barisnya BARU. Kalau dia ikut di `update()`
            // juga, tiap seed ulang ngebalikin sandi yang sudah diganti admin
            // ke bawaan — diam-diam, tanpa error. Lihat MenyetelSandiAwal.
            $lama
                ? $lama->update($atribut)
                : User::create([...$atribut, 'password' => $this->sandiAwal()]);
        }
    }
}
