<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * SEMUA seeder baris kemampuan (`calibration_capabilities`), dalam satu daftar.
 *
 * ## Kenapa daftarnya diangkat ke sini
 *
 * Dua pemanggil butuh daftar yang SAMA PERSIS:
 *
 *  1. `DatabaseSeeder` — jalur `db:seed` penuh (lokal, test, deploy pertama).
 *  2. `kemampuan:pastikan` — jalur BOOT di produksi, yang cuma boleh menanam
 *     baris kemampuan dan TIDAK boleh menyentuh data demo.
 *
 * Sebelum berkas ini ada, jalur kedua tidak ada sama sekali: satu-satunya cara
 * menaruh baris kemampuan alat baru ke produksi adalah menyalakan
 * `SEED_ON_BOOT=true`, redeploy, lalu mematikannya lagi. Ritual itu menjalankan
 * `db:seed` PENUH — termasuk `DemoDataSeeder` dan sesi contoh 26 alat — dan
 * langkah "matikan lagi" tidak menerbitkan error kalau terlupa. Yang terjadi
 * kalau lupa: tiap container bangun (Render menidurkan yang nganggur 15 menit)
 * seluruh sesi demo ditulis ulang, dan data yang sedang diuji teknisi ketimpa
 * balik ke bawaan.
 *
 * Menyalin daftarnya ke dua tempat adalah bentuk kegagalan yang sudah berkali
 * menggigit repo ini: yang satu diperbarui, yang satu ketinggalan, dan bedanya
 * tidak menerbitkan error — cuma alat yang tidak muncul di HP.
 *
 * ## Yang SENGAJA tidak ada di sini
 *
 * Tiga seeder yang bertetangga di `DatabaseSeeder` TIDAK ikut, dan masing-masing
 * punya sebabnya sendiri — semuanya soal "aman dijalankan ulang tiap boot":
 *
 *  - **`OrganizationSeeder`** — menulis nama, alamat, nomor akreditasi, dan
 *    `settings` organisasi. `settings` memuat posisi & lebar tanda tangan serta
 *    `kop_path` yang disetel admin lewat panel. Menjalankannya tiap boot
 *    mengembalikan suntingan itu ke bawaan, tanpa satu pun error.
 *  - **`MetodeKalibrasiSeeder`** — mengisi `calibration_methods`, master data
 *    yang admin boleh tambah sendiri lewat Filament. Sertifikat TIDAK
 *    bergantung padanya (`CertificateSnapshotBuilder::metodeKalibrasi()` jatuh
 *    ke `CalibrationProfile::kodeMetode()`), jadi ketiadaannya tidak menggeser
 *    satu angka pun.
 *  - **`ThermohygroSeeder`** — menulis baris `standards` (TH-1..TH-7) yang
 *    tanggal kalibrasi & ketidakpastiannya diperbarui lab tiap kali unitnya
 *    dikalibrasi ulang. Menimpanya tiap boot mengembalikan angka lama.
 *
 * Ketiganya tetap jalan lewat `db:seed` penuh; yang dibatasi cuma jalur boot.
 *
 * ## Urutannya MENGIKAT
 *
 * `CalibrationCapabilitySeeder` wajib pertama. Sepuluh seeder di bawahnya
 * bertumpu pada baris lampiran yang sudah ada lebih dulu — `CalibrationCapabilitySeeder`
 * sendiri menyaring `sumber = akreditasi` supaya tidak melihat baris yang lahir
 * dari seeder per-alat, dan saringan itu cuma benar kalau urutannya begini.
 */
class KemampuanKalibrasiSeeder extends Seeder
{
    /**
     * Urutannya disalin apa adanya dari `DatabaseSeeder` sebelum daftar ini
     * diangkat — termasuk alasan kenapa yang lampiran duluan.
     *
     * @var list<class-string<Seeder>>
     */
    public const DAFTAR = [
        // Lampiran akreditasi LK-285-IDN, dibaca dari
        // `database/data/kemampuan-kalibrasi.json`. WAJIB pertama.
        CalibrationCapabilitySeeder::class,
        PhMeterCapabilitySeeder::class,
        TurbidimeterCapabilitySeeder::class,
        ConductivityCapabilitySeeder::class,
        ChlorineCapabilitySeeder::class,
        RefractometerCapabilitySeeder::class,
        SpectrophotometerCapabilitySeeder::class,
        ViscometerCapabilitySeeder::class,
        DoMeterCapabilitySeeder::class,
        // Dua alat DI LUAR lampiran, CMC nol. Barisnya tetap perlu ada supaya
        // jalur budget penuh jalan — lihat docblock masing-masing seeder.
        GasDetectorCapabilitySeeder::class,
        HeightGaugeCapabilitySeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::DAFTAR);
    }
}
