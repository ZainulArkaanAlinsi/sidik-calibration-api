<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC pH Meter presisi tinggi (3 titik buffer: 4, 7, 10), dari sheet
 * `PERHITUNGAN U95%` di trial workbook pH — baris "Ketidakpastian Bentangan,
 * U = k Uc" / "Uncertainty sertifikat" di tabel budget ketidakpastian tiap
 * titik ukur. Beda dari angka pH yang ada di
 * `database/data/kemampuan-kalibrasi.json` (lampiran akreditasi resmi,
 * dibulatkan 3 desimal: 0.023/0.021/0.031). Dua-duanya sengaja dibiarkan
 * hidup berdampingan: baris seeder ini pakai `range_min = range_max = titik`
 * (bukan `min: null` kayak konvensi JSON), jadi `updateOrCreate`-nya nggak
 * numpuk sama baris dari `CalibrationCapabilitySeeder` — dan
 * `GumCalculator::kemampuanUntukTitik()` sengaja nyari match yang persis
 * (`range_min = range_max = titik`) biar yang kepake presisi tinggi punya
 * seeder ini, bukan versi bulat.
 *
 * Angkanya SUDAH dipotong ke 8 desimal (bukan 17 digit penuh dari
 * `PERHITUNGAN U95%`) karena `calibration_capabilities.ketidakpastian_terbaik`
 * itu `decimal(20,8)` — nyimpen lebih dari 8 desimal cuma keliatan presisi di
 * SQLite (dipakai test), MySQL beneran (dipakai app) bakal motong sendiri ke
 * 8 desimal pas INSERT. Nulis 17 digit di sini bikin kesan salah kalau
 * presisi itu beneran kesimpen. 8 desimal masih jauh lebih presisi dari
 * kebutuhan fisiknya (resolusi pH meter 0.01), jadi nggak ada bedanya secara
 * praktis — cuma jujur soal apa yang beneran ada di DB.
 *
 * BATASAN YANG DIKETAHUI — aturan `U = max(U_hitung, CMC)` belum jalan di
 * app, baru hasil akhirnya yang ditempel di sini.
 *
 * Excel ngitung budget ketidakpastian penuh tiap titik (sertifikat calibrator,
 * daya baca, UTemperature dengan koefisien sensitivitas `ci`, pengaruh beda
 * suhu, pengulangan → Welch-Satterthwaite → `k` dari t-student), terus
 * ngebandingin hasilnya sama CMC lab dan ngambil yang LEBIH BESAR.
 *
 * `GumCalculator::hitungDariKemampuan()` ngelewatin semua itu: dia langsung
 * pakai `ketidakpastian_terbaik` sebagai U. Jadi angka di seeder ini bukan
 * murni CMC — dia hasil `max()` yang udah dihitungin Excel duluan. Cocok buat
 * sesi ini, TAPI buat sesi lain (suhu ruang beda, pengulangan lebih berisik)
 * `U_hitung`-nya beda dan `max()`-nya bisa jatuh ke angka lain.
 *
 * Beneran benernya: app ngitung budgetnya sendiri lalu `max()` sama CMC asli
 * (0.023 / 0.021 / 0.031). Sampai itu ada, seeder ini cuma bener buat data
 * sesi 2405.13.A.
 *
 * PENTING — urutan run: harus abis `CalibrationCapabilitySeeder`. Seeder itu
 * ngehapus SEMUA `CalibrationCapability` di bawah kategori
 * `instrumen-analitik` sebelum nulis ulang dari JSON (termasuk baris pH punya
 * seeder ini). Kalau `CalibrationCapabilitySeeder` dijalanin sendirian
 * (`--class=`) di luar urutan `DatabaseSeeder`, seeder ini WAJIB di-re-run
 * abis itu buat munculin lagi baris presisi tingginya.
 */
class PhMeterCapabilitySeeder extends Seeder
{
    /**
     * Nama kelompok di `database/data/kemampuan-kalibrasi.json` yang jadi
     * induk kategori pH Meter — di-slug pakai cara yang SAMA kayak
     * `CalibrationCapabilitySeeder::run()`, biar kode kategorinya nggak
     * pernah nyimpang walaupun nama kelompoknya diedit di JSON.
     */
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        // Angka yang BENERAN dicetak di sertifikat — baris "Uncertainty
        // sertifikat" di `PERHITUNGAN U95%`, bukan baris "Ketidakpastian
        // Bentangan U = k·Uc" di atasnya. Dua baris itu beda buat titik 10:
        //
        //   titik   U hitung      CMC Lab   Uncertainty sertifikat
        //     4     0.02343221    0.023     0.02343221   <- hitung menang
        //     7     0.02110895    0.021     0.02110895   <- hitung menang
        //    10     0.03032720    0.031     0.031        <- CMC menang
        //
        // Lab NGGAK BOLEH ngelaporin ketidakpastian lebih baik dari CMC
        // terakreditasinya. Titik 10 hasil hitungnya (0.03032720) lebih kecil
        // dari CMC (0.031), jadi yang dilaporkan CMC-nya. Sebelumnya di sini
        // kesimpen angka hitung — artinya app ngeklaim ketelitian melebihi
        // akreditasi, dan itu temuan asesor.
        // Konstanta budget ketidakpastian, dari sheet `PERHITUNGAN U95%` lembar
        // olah data lab. Empat angka ini yang bikin `GumCalculator` nyusun
        // budget 5 komponen, bukan cuma ngelaporin CMC + pengulangan.
        //
        // `u_temperature` sama buat ketiga titik karena dia sifat ALAT UKUR
        // SUHU-nya, bukan sifat larutannya: √((0,5/2)² + (0,06/2)²) dari
        // sertifikat thermometer (U95 0,5 °C) & sensor (0,06 °C), dua-duanya k=2.
        //
        // `ci_suhu` beda jauh per titik dan itu memang fisiknya: pH 10 belasan
        // kali lebih sensitif ke suhu daripada pH 4 (lihat kurva buffer di
        // `standards.koefisien_suhu` — kemiringannya di 25 °C).
        //
        // `ci_perbedaan_suhu` di titik 4 nilainya 1, bukan pecahan kayak dua
        // titik lain. Itu KEANEHAN yang dibawa apa adanya dari lembar aslinya —
        // labnya sendiri belum bisa jelasin asalnya. Diikutin biar angkanya
        // reproducible lawan lembar manual; JANGAN "dirapikan" jadi pecahan
        // tanpa lab yang mutusin, karena itu ngubah U yang dilaporkan.
        // UTemperature = akar jumlah kuadrat dua sertifikat, sesuai kepala
        // lembar `PERHITUNGAN U95%`:
        //
        //   termometer  U95 0.72, k=2  ->  u = 0.36
        //   sensor      U95 0.06, k=2  ->  u = 0.03
        //   UTemperature = sqrt(0.36^2 + 0.03^2) = 0.36124783736376886
        //
        // Sebelumnya di sini kesimpen 0.25179356624028343 = sqrt(0.25^2 +
        // 0.03^2) — angka termometernya kebaca 0.25, bukan 0.36. Efeknya
        // `u_c` dan `v_eff` meleset di desimal ke-7 lawan lembar manual, dan
        // itu KETUTUP lantai CMC selama CMC-nya diisi jawaban jadi (lihat
        // bawah). Begitu pembacaannya lebih berisik, selisihnya kebuka.
        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        // `ketidakpastian_terbaik` = CMC Laboratory apa adanya (baris "CMC
        // Laboratory" di lembar), BUKAN hasil `max(U_hitung, CMC)`.
        //
        // Dulu yang disimpen di sini jawaban jadinya (0.02343221 / 0.02110895)
        // karena `hitungDariKemampuan()` mustahil ngitung budget sendiri. Itu
        // udah nggak berlaku: `hitungDariBudgetPenuh()` sekarang ngitung lima
        // komponennya, Welch-Satterthwaite, dan `k` dari t-student — lalu
        // `max()`-nya sama CMC. Nyimpen jawaban jadi sebagai CMC bikin
        // lantainya kelewat tinggi dan nutupin hasil hitung yang sebenarnya.
        $titik = [
            [
                'titik' => 4, 'ketidakpastian_terbaik' => 0.023,
                'u_temperature' => $uTemperature, 'ci_suhu' => 0.00077,
                'u_perbedaan_suhu' => 0.01, 'ci_perbedaan_suhu' => 1,
            ],
            [
                'titik' => 7, 'ketidakpastian_terbaik' => 0.021,
                'u_temperature' => $uTemperature, 'ci_suhu' => 0.00352,
                'u_perbedaan_suhu' => 0.02, 'ci_perbedaan_suhu' => 0.00304,
            ],
            [
                'titik' => 10, 'ketidakpastian_terbaik' => 0.031,
                'u_temperature' => $uTemperature, 'ci_suhu' => 0.01021,
                'u_perbedaan_suhu' => 0.05, 'ci_perbedaan_suhu' => 0.00949,
            ],
        ];

        foreach ($titik as $t) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => 'pH Meter',
                    'range_min' => $t['titik'],
                    'range_max' => $t['titik'],
                ],
                [
                    'parameter' => 'pH',
                    'satuan' => 'pH',
                    'ketidakpastian_terbaik' => $t['ketidakpastian_terbaik'],
                    'satuan_ketidakpastian' => 'pH',
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0506',
                    'u_temperature' => $t['u_temperature'],
                    'ci_suhu' => $t['ci_suhu'],
                    'u_perbedaan_suhu' => $t['u_perbedaan_suhu'],
                    'ci_perbedaan_suhu' => $t['ci_perbedaan_suhu'],
                ],
            );
        }
    }
}
