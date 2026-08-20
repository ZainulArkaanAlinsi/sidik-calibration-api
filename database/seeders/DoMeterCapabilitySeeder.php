<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC DO Meter (alat ke-9), dari sheet `PERHITUNGAN U95%` di
 * `Master Olah Data_DO Meter.xlsm` — baris "CMC Laboratory" (DATABASE `S5` =
 * 0,16 mg/L). SATU titik: 8,77 mg/L.
 *
 * **Titiknya 8,77, bukan 0,00** yang tercetak di form Rev.2 — alasan lengkapnya
 * di docblock `DoMeterProfile`. Ringkasnya: "Zero Oxygen Std. 0.0" di form itu
 * larutan penol alat, bukan titik kalibrasinya; yang dilaporkan & terakreditasi
 * 8,77 mg/L (satu-satunya titik yang punya CMC di DATABASE).
 *
 * BEDA dari pH: budget DO Meter cuma butuh `u_temperature`. `ci_suhu`,
 * `u_perbedaan_suhu`, `ci_perbedaan_suhu` DIBIARKAN NULL — `DoMeterProfile`
 * ngitung koefisien sensitivitas suhunya sendiri (`ci = UTemperature/400 ·
 * titik`), dan konstanta pengencerannya hidup di profil.
 *
 * UTemperature = √((U_thermometer/2)² + (U_sensor/2)²) dari kepala sheet
 * `PERHITUNGAN U95%` (U95 termometer 0,72 & sensor 0,06, dua-duanya k=2):
 *
 *   √(0,36² + 0,03²) = 0,36124783736376886
 *
 * Angkanya sama persis sama Chlorine & Turbidimeter — termometer & sensor
 * standarnya emang unit yang sama.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder`.
 */
class DoMeterCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    private const NAMA_ALAT = 'DO Meter';

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        // Satu titik. CMC 0,16 mg/L (DATABASE S5). Di titik ini U hitung
        // (0,148) < CMC (0,16), jadi yang dilaporkan CMC-nya — persis kayak
        // sel `AB34 = MAX(U, CMC)` di sheet.
        CalibrationCapability::updateOrCreate(
            [
                'equipment_category_id' => $kategori->id,
                'nama_alat' => self::NAMA_ALAT,
                'range_min' => 8.77,
                'range_max' => 8.77,
            ],
            [
                'parameter' => 'Dissolved Oxygen',
                'satuan' => 'mg/L',
                'ketidakpastian_terbaik' => 0.16,
                'satuan_ketidakpastian' => 'mg/L',
                'faktor_cakupan' => 2,
                'metode' => 'SIDIK-IK-CAL-0530',
                'u_temperature' => $uTemperature,
                // Sengaja null — profil yang ngitung ci suhu & pengenceran.
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ],
        );
    }
}
