<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;

/**
 * CMC pH Meter presisi penuh (3 titik buffer: 4, 7, 10), dari sheet
 * `FORM VALIDASI` di trial workbook pH ("Uncertainty sertifikat") — beda dari
 * angka pH yang ada di `database/data/kemampuan-kalibrasi.json` (lampiran
 * akreditasi resmi, dibulatkan 3 desimal: 0.023/0.021/0.031). Dua-duanya
 * sengaja dibiarkan hidup berdampingan: baris seeder ini pakai
 * `range_min = range_max = titik` (bukan `min: null` kayak konvensi JSON),
 * jadi `updateOrCreate`-nya nggak numpuk sama baris dari
 * `CalibrationCapabilitySeeder` — dan `GumCalculator::kemampuanUntukTitik()`
 * sengaja nyari match yang persis (`range_min = range_max = titik`) biar
 * yang kepake presisi penuh punya seeder ini, bukan versi bulat.
 *
 * PENTING — urutan run: harus abis `CalibrationCapabilitySeeder`. Seeder itu
 * ngehapus SEMUA `CalibrationCapability` di bawah kategori
 * `instrumen-analitik` sebelum nulis ulang dari JSON (termasuk baris pH punya
 * seeder ini). Kalau `CalibrationCapabilitySeeder` dijalanin sendirian
 * (`--class=`) di luar urutan `DatabaseSeeder`, seeder ini WAJIB di-re-run
 * abis itu buat munculin lagi baris presisi penuhnya.
 */
class PhMeterCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => 'instrumen-analitik'],
            ['nama' => 'Instrumen Analitik'],
        );

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
