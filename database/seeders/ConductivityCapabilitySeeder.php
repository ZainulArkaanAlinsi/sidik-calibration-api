<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Conductivity Meter (3 titik: 25 µS/cm / 1412 µS/cm / 111 mS/cm).
 *
 * Angkanya punya DUA sumber yang saling menguatkan, dan dua-duanya dicek:
 *
 *  - sheet `DATABASE` di `Master Olah Data_Conductivity.xlsm`, sel S5/S6/S8 =
 *    0,41 / 5,1 / 1,7; dan
 *  - lampiran akreditasi KAN **LK-285-IDN** alat no. 40 `Conductivitymeter`,
 *    yang udah lebih dulu ada di repo (`database/data/kemampuan-kalibrasi.json`).
 *
 * Cocok persis. Ini bukan angka tebakan.
 *
 * `range_min = range_max = titik` biar `GumCalculator::kemampuanUntukTitik()`
 * nyocokin ke titik ukurnya, sama polanya kayak pH & Turbidimeter.
 *
 * BEDA satuan antar titik: dua titik pertama µS/cm, titik ketiga mS/cm. Master
 * emang nyampur satuan dalam satu lembar — jangan "diseragamkan", karena CMC
 * lampiran akreditasinya juga tertulis begitu.
 *
 * `u_temperature` = √((U_termometer/2)² + (U_sensor/2)²) dari kepala sheet
 * `PERHITUNGAN U95%` (Z3=0,72 & Z4=0,06, dua-duanya k=2):
 *
 *   √(0,36² + 0,03²) = 0,36124783736376886   (sel Z5)
 *
 * Kebetulan sama persis kayak turbidimeter — termometer & sensor yang dipakai
 * emang unit yang sama.
 *
 * `ci_suhu` dibiarin NULL: `ConductivityProfile` ngitung koefisien
 * sensitivitasnya sendiri (`ci = (u_temperature/100)·titik nominal`), jadi
 * konstanta itu nggak ada artinya di jalur ini.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder` (yang ngehapus
 * semua kemampuan di kategori `instrumen-analitik` sebelum nulis ulang dari
 * JSON), sama persis kayak seeder kemampuan alat lain.
 */
class ConductivityCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        $titik = [
            ['titik' => 25, 'satuan' => 'µS/cm', 'ketidakpastian_terbaik' => 0.41],
            ['titik' => 1412, 'satuan' => 'µS/cm', 'ketidakpastian_terbaik' => 5.1],
            ['titik' => 111, 'satuan' => 'mS/cm', 'ketidakpastian_terbaik' => 1.7],
        ];

        foreach ($titik as $t) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => 'Conductivitymeter',
                    'range_min' => $t['titik'],
                    'range_max' => $t['titik'],
                ],
                [
                    'parameter' => 'Conductivity',
                    'satuan' => $t['satuan'],
                    'ketidakpastian_terbaik' => $t['ketidakpastian_terbaik'],
                    'satuan_ketidakpastian' => $t['satuan'],
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0507_Rev.6',
                    'u_temperature' => $uTemperature,
                    // Sengaja null — lihat docblock kelas.
                    'ci_suhu' => null,
                    'u_perbedaan_suhu' => null,
                    'ci_perbedaan_suhu' => null,
                ],
            );
        }
    }
}
