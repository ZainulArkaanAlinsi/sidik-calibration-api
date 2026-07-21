<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC pH Meter presisi tinggi (3 titik buffer: 4, 7, 10) + konstanta budget
 * ketidakpastian per titik, dari sheet `PERHITUNGAN U95%` di trial workbook pH.
 *
 * `ketidakpastian_terbaik` = CMC LAB MENTAH (0.023 / 0.021 / 0.031) — angka
 * akreditasi resmi, dibulatkan 3 desimal. Ini BUKAN lagi hasil "max" yang udah
 * dihitung Excel (dulu sempat begitu). Sekarang `GumCalculator::hitungTitik()`
 * ngitung budget penuh sendiri dari konstanta di bawah (`u_temperature`,
 * `ci_suhu`, `u_perbedaan_suhu`, `ci_perbedaan_suhu`) lalu ngambil
 * max(U_hitung, CMC) — jadi "BATASAN YANG DIKETAHUI" yang dulu dicatat di sini
 * (app belum ngitung, cuma nempel hasil) SUDAH TERATASI.
 *
 * Hasil buat sesi 2405.13.A tetap persis sertifikat 012-CAL-524:
 *   titik   U hitung      CMC     dilaporkan (max)
 *     4     0.02343221    0.023   0.02343221   <- hitung menang
 *     7     0.02110895    0.021   0.02110895   <- hitung menang
 *    10     0.03032720    0.031   0.031        <- CMC menang
 *
 * Bedanya sekarang: sesi pH LAIN (suhu ruang beda, pengulangan lebih berisik)
 * ngasih U_hitung yang beda & max()-nya ikut nyesuaikan — bukan angka beku.
 *
 * Konstanta budget (per titik, dari `PERHITUNGAN U95%.csv`):
 *   u_temperature     : UTemperature gabungan = √((0.72/2)²+(0.06/2)²) = 0.36124784 °C
 *                       (sertifikat termometer 0.72 °C + sensor 0.06 °C, k=2)
 *   ci_suhu           : koef. sensitivitas suhu buffer @25°C
 *   u_perbedaan_suhu  : U95% pengaruh perbedaan suhu (konstanta lab)
 *   ci_perbedaan_suhu : koef. sensitivitas komponen itu
 *
 * Angka dipotong 8 desimal (kolom `decimal(20,8)`) — MySQL motong sendiri saat
 * INSERT, jadi ditulis apa adanya biar jujur soal presisi yang beneran kesimpen.
 * 8 desimal masih jauh lebih presisi dari resolusi fisik pH meter (0.01).
 *
 * Baris ini pakai `range_min = range_max = titik` (bukan `null` kayak konvensi
 * `CalibrationCapabilitySeeder`) supaya `updateOrCreate` nggak numpuk sama baris
 * generik dari lampiran akreditasi, dan `GumCalculator::kemampuanUntukTitik()`
 * milih baris presisi ini duluan.
 *
 * PENTING — urutan run: harus abis `CalibrationCapabilitySeeder`. Seeder itu
 * ngehapus SEMUA CalibrationCapability di bawah kategori `instrumen-analitik`
 * sebelum nulis ulang dari JSON. Kalau dijalanin sendirian di luar urutan
 * DatabaseSeeder, seeder ini WAJIB di-re-run abis itu.
 */
class PhMeterCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    /** UTemperature gabungan sertifikat termometer (0.72°C) + sensor (0.06°C), k=2. */
    private const U_TEMPERATURE = 0.36124784;

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        // cmc          : CMC lab mentah (akreditasi, 3 desimal).
        // ci_suhu      : koef. sensitivitas suhu @25°C (sheet Nilai Koef. Sensitifitas).
        // u_perbedaan  : U95% pengaruh perbedaan suhu (konstanta lab).
        // ci_perbedaan : koef. sensitivitas komponen perbedaan suhu.
        $titik = [
            ['titik' => 4, 'cmc' => 0.023, 'ci_suhu' => 0.00077, 'u_perbedaan' => 0.01, 'ci_perbedaan' => 1.0],
            ['titik' => 7, 'cmc' => 0.021, 'ci_suhu' => 0.00352, 'u_perbedaan' => 0.02, 'ci_perbedaan' => 0.00304],
            ['titik' => 10, 'cmc' => 0.031, 'ci_suhu' => 0.01021, 'u_perbedaan' => 0.05, 'ci_perbedaan' => 0.00949],
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
                    'ketidakpastian_terbaik' => $t['cmc'],
                    'satuan_ketidakpastian' => 'pH',
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0506',
                    'u_temperature' => self::U_TEMPERATURE,
                    'ci_suhu' => $t['ci_suhu'],
                    'u_perbedaan_suhu' => $t['u_perbedaan'],
                    'ci_perbedaan_suhu' => $t['ci_perbedaan'],
                ],
            );
        }
    }
}
