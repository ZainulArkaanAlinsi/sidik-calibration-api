<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Turbidimeter (3 titik: 1 / 100 / 1000 NTU), dari sheet `PERHITUNGAN U95%`
 * di `Master Olah Data_Turbidimeter.xlsm` — baris "CMC Laboratory"
 * (DATABASE!T5/T6/T7 = 0.041 / 3.1 / 22).
 *
 * Sama polanya kayak `PhMeterCapabilitySeeder`: pakai `range_min = range_max =
 * titik` biar `GumCalculator::kemampuanUntukTitik()` nyocokin persis ke titik
 * ukurnya, dan `updateOrCreate` nggak numpuk sama baris kategori dari
 * `CalibrationCapabilitySeeder`.
 *
 * BEDA dari pH: budget turbidimeter cuma butuh `u_temperature` (UTemperature
 * dari sertifikat termometer & sensor). `ci_suhu`, `u_perbedaan_suhu`, dan
 * `ci_perbedaan_suhu` sengaja DIBIARKAN NULL — `TurbidimeterProfile` yang
 * ngitung koefisien sensitivitas suhunya sendiri (`ci = UTemperature/400 ·
 * titik`), jadi konstanta itu nggak ada artinya di jalur ini. Profil yang
 * mutusin bentuk budget, bukan `CalibrationCapability::punyaBudgetPenuh()`.
 *
 * UTemperature = √((U_thermometer/2)² + (U_sensor/2)²) dari kepala sheet
 * `PERHITUNGAN U95%` (U95 termometer 0.72 & sensor 0.06, dua-duanya k=2):
 *
 *   √((0.72/2)² + (0.06/2)²) = √(0.36² + 0.03²) = 0.36124783736376886
 *
 * Angka ini dipotong ke presisi kolom `decimal(20,8)` waktu INSERT — sama
 * pertimbangannya kayak `PhMeterCapabilitySeeder`.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder` (yang ngehapus
 * semua kemampuan di kategori `instrumen-analitik` sebelum nulis ulang dari
 * JSON), sama persis kayak `PhMeterCapabilitySeeder`.
 */
class TurbidimeterCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        // CMC (`ketidakpastian_terbaik`) = CMC Laboratory ASLI dari sheet
        // DATABASE workbook (T5/T6/T7 = 0,041 / 3,1 / 22). Bukan placeholder —
        // ini angka lampiran akreditasi yang beneran dipakai lab, sama yang
        // kecetak di sertifikat trial 0189-CAL-624. Di ketiga titik U hitung <
        // CMC, jadi yang dilaporkan CMC-nya (sel AB21/AB35/AB49 U95%).
        $titik = [
            ['titik' => 1, 'ketidakpastian_terbaik' => 0.041],
            ['titik' => 100, 'ketidakpastian_terbaik' => 3.1],
            ['titik' => 1000, 'ketidakpastian_terbaik' => 22],
        ];

        foreach ($titik as $t) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => 'Turbidimeter',
                    'range_min' => $t['titik'],
                    'range_max' => $t['titik'],
                ],
                [
                    'parameter' => 'Turbidity',
                    'satuan' => 'NTU',
                    'ketidakpastian_terbaik' => $t['ketidakpastian_terbaik'],
                    'satuan_ketidakpastian' => 'NTU',
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0523',
                    'u_temperature' => $uTemperature,
                    // Sengaja null — lihat docblock kelas (profil ngitung ci sendiri).
                    'ci_suhu' => null,
                    'u_perbedaan_suhu' => null,
                    'ci_perbedaan_suhu' => null,
                ],
            );
        }
    }
}
