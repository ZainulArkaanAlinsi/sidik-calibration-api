<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;

/**
 * CMC pH Meter (3 titik buffer: 4, 7, 10), diambil dari `DATABASE.csv` /
 * `FORM VALIDASI.csv` di trial workbook pH. Sengaja berdiri sendiri, nggak
 * lewat `CalibrationCapabilitySeeder` — seeder itu gantung ke
 * `Project-PT-ASMO/04 - Referensi Teknis/data-kemampuan-kalibrasi.json` yang
 * lagi nggak ada di working tree, jadi selalu gagal duluan.
 */
class PhMeterCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => 'instrumen-analitik'],
            ['nama' => 'Instrumen Analitik'],
        );

        // Dicocokkan lewat range_min=range_max=titik nominal (round-trip sama
        // GumCalculator::kemampuanUntukTitik()) — konvensi kemampuan titik
        // tunggal yang sama kayak yang dipakai CalibrationCapabilitySeeder.
        $titik = [
            ['titik' => 4, 'ketidakpastian_terbaik' => 0.02343221021262627],
            ['titik' => 7, 'ketidakpastian_terbaik' => 0.02110894987572546],
            ['titik' => 10, 'ketidakpastian_terbaik' => 0.030327201537199536],
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
                ],
            );
        }
    }
}
