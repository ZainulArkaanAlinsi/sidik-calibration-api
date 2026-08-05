<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Chlorin Meter (2 titik: 1,74 / 1,83 mg/L), dari sheet `PERHITUNGAN U95%`
 * di `Master Olah Data_Chlorine Meter.xlsm` — baris "CMC Laboratory"
 * (DATABASE S5/S6 = 0,091 / 0,08), dan sama persis sama lampiran akreditasi
 * LK-285-IDN no. 42.
 *
 * **Titiknya 1,74 & 1,83, bukan 0,40 & 4,00** yang tercetak di lembar kerja
 * Rev.2 — alasan lengkapnya di docblock `ChlorineProfile`. Ringkasnya: lembar
 * cetaknya ketinggalan set standar, dan yang mengikat itu lampiran akreditasi.
 *
 * Pola sama kayak `TurbidimeterCapabilitySeeder`: `range_min = range_max =
 * titik` biar `GumCalculator::kemampuanUntukTitik()` nyocokin persis, dan
 * `updateOrCreate` nggak numpuk sama baris kategori dari
 * `CalibrationCapabilitySeeder`.
 *
 * BEDA dari pH: budget chlorine cuma butuh `u_temperature`. `ci_suhu`,
 * `u_perbedaan_suhu`, dan `ci_perbedaan_suhu` sengaja DIBIARKAN NULL —
 * `ChlorineProfile` ngitung koefisien sensitivitas suhunya sendiri
 * (`ci = UTemperature/400 · titik`), dan konstanta pengencerannya hidup di
 * profil, bukan di sini (lihat `ChlorineProfile::CI_PENGENCERAN`).
 *
 * UTemperature = √((U_thermometer/2)² + (U_sensor/2)²) dari kepala sheet
 * `PERHITUNGAN U95%` (U95 termometer 0,72 & sensor 0,06, dua-duanya k=2):
 *
 *   √(0,36² + 0,03²) = 0,36124783736376886
 *
 * Angkanya kebetulan sama persis sama Turbidimeter — termometer & sensor
 * standarnya emang unit yang sama.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder`.
 */
class ChlorineCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    /**
     * Nama alat ngikut LAMPIRAN AKREDITASI: "Chlorin Meter", tanpa 'e'. Ini
     * yang dicocokin `CalibrationProfileRegistry::untukNamaAlat()` — jangan
     * "dibenerin" jadi "Chlorine" tanpa ngubah profilnya juga.
     */
    private const NAMA_ALAT = 'Chlorin Meter';

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        // CMC Laboratory ASLI (DATABASE S5/S6). Di titik 1,74 U hitung
        // (0,0890) < CMC (0,091) jadi yang dilaporkan CMC-nya.
        $titik = [
            ['titik' => 1.74, 'ketidakpastian_terbaik' => 0.091],
            ['titik' => 1.83, 'ketidakpastian_terbaik' => 0.08],
        ];

        foreach ($titik as $t) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => self::NAMA_ALAT,
                    'range_min' => $t['titik'],
                    'range_max' => $t['titik'],
                ],
                [
                    'parameter' => 'Chlorine',
                    'satuan' => 'mg/L',
                    'ketidakpastian_terbaik' => $t['ketidakpastian_terbaik'],
                    'satuan_ketidakpastian' => 'mg/L',
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0524',
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
