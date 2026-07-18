<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC pH Meter presisi tinggi (3 titik buffer: 4, 7, 10), dari sheet
 * `FORM VALIDASI` di trial workbook pH ("Uncertainty sertifikat") — beda dari
 * angka pH yang ada di `database/data/kemampuan-kalibrasi.json` (lampiran
 * akreditasi resmi, dibulatkan 3 desimal: 0.023/0.021/0.031). Dua-duanya
 * sengaja dibiarkan hidup berdampingan: baris seeder ini pakai
 * `range_min = range_max = titik` (bukan `min: null` kayak konvensi JSON),
 * jadi `updateOrCreate`-nya nggak numpuk sama baris dari
 * `CalibrationCapabilitySeeder` — dan `GumCalculator::kemampuanUntukTitik()`
 * sengaja nyari match yang persis (`range_min = range_max = titik`) biar
 * yang kepake presisi tinggi punya seeder ini, bukan versi bulat.
 *
 * Angkanya SUDAH dipotong ke 8 desimal (bukan 17 digit penuh dari
 * `FORM VALIDASI`) karena `calibration_capabilities.ketidakpastian_terbaik`
 * itu `decimal(20,8)` — nyimpen lebih dari 8 desimal cuma keliatan presisi di
 * SQLite (dipakai test), MySQL beneran (dipakai app) bakal motong sendiri ke
 * 8 desimal pas INSERT. Nulis 17 digit di sini bikin kesan salah kalau
 * presisi itu beneran kesimpen. 8 desimal masih jauh lebih presisi dari
 * kebutuhan fisiknya (resolusi pH meter 0.01), jadi nggak ada bedanya secara
 * praktis — cuma jujur soal apa yang beneran ada di DB.
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

        $titik = [
            ['titik' => 4, 'ketidakpastian_terbaik' => 0.02343221],
            ['titik' => 7, 'ketidakpastian_terbaik' => 0.02110895],
            ['titik' => 10, 'ketidakpastian_terbaik' => 0.03032720],
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
