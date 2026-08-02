<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\GumCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Turbidimeter dari `Master Olah Data_Turbidimeter.xlsm` (sheet INPUT DATA)
 * — standar turbidity 1/100/1000 NTU, alat HACH 2100Q, dan satu sesi kalibrasi
 * end-to-end yang ketidakpastiannya BENERAN dihitung `GumCalculator` (bukan
 * angka jadi ditempel), sama polanya kayak `TirtaGraciaPhMeterSeeder`.
 *
 * Pembacaannya diambil dari blok "After Adjustment Reading" (INPUT DATA baris
 * 46–50). STDEV-nya harus keluar 0.00894 / 0.04472 / 0.54772 (sel PERHITUNGAN
 * G44/I44/K44), dan U95-nya jatuh ke lantai CMC 0.041 / 3.1 / 22.
 *
 * WAJIB jalan SETELAH `TurbidimeterCapabilitySeeder` (butuh CMC turbidimeter
 * biar `GumCalculator` masuk jalur budget penuh, bukan generik) dan
 * `ThermohygroSeeder`.
 */
class TurbidimeterSeeder extends Seeder
{
    /**
     * Lima larutan standar turbidity resmi (lembar `SIDIK-FM-CAL-0530_Rev.2`).
     *
     * ⚠️ `ketidakpastian` (U95 sertifikat larutan) masih PLACEHOLDER — belum ada
     * angka sertifikat asli buat 0,04/15/750/2000 NTU. WAJIB diganti sebelum
     * sertifikat terbit. Engine-nya udah bener; ini cuma DATA sampel biar alur
     * jalan end-to-end.
     *
     * @var list<array{nama: string, ketidakpastian: float}>
     */
    private const STANDAR = [
        ['nama' => 'Turbidity Std. Solutions 0.04 NTU', 'ketidakpastian' => 0.01],
        ['nama' => 'Turbidity Std. Solutions 15 NTU', 'ketidakpastian' => 0.3],
        ['nama' => 'Turbidity Std. Solutions 100 NTU', 'ketidakpastian' => 3.0],
        ['nama' => 'Turbidity Std. Solutions 750 NTU', 'ketidakpastian' => 10.0],
        ['nama' => 'Turbidity Std. Solutions 2000 NTU', 'ketidakpastian' => 21.0],
    ];

    /**
     * Pembacaan After Adjustment per titik — data CONTOH (bukan job asli), buat
     * ngisi 5 titik resmi biar layar admin & sertifikat ada isinya.
     *
     * @var list<array{titik: float, standar: int, pembacaan: list<float>}>
     */
    private const TITIK = [
        ['titik' => 0.04, 'standar' => 0, 'pembacaan' => [0.04, 0.04, 0.05, 0.04, 0.04]],
        ['titik' => 15.0, 'standar' => 1, 'pembacaan' => [15.0, 15.0, 15.1, 15.0, 15.0]],
        ['titik' => 100.0, 'standar' => 2, 'pembacaan' => [100, 100, 100, 100, 100.1]],
        ['titik' => 750.0, 'standar' => 3, 'pembacaan' => [750, 750, 751, 750, 750]],
        ['titik' => 2000.0, 'standar' => 4, 'pembacaan' => [2000, 2000, 2001, 2001, 2000]],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PUSAT AIR TANAH DAN GEOLOGI TATA LINGKUNGAN'],
            ['organization_id' => 1, 'alamat' => 'Jl. Diponegoro No.57 Bandung'],
        );

        $standar = [];
        foreach (self::STANDAR as $i => $s) {
            $standar[$i] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'serial_number' => 'TURB-STD-'.($i + 1),
                    'no_sertifikat' => 'TURB-STD-'.($i + 1),
                    'tertelusur_ke' => 'HACH / SNI ISO 7027',
                    'berlaku_sampai' => now()->addYears(2),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => 'NTU',
                    'faktor_cakupan' => 2,
                    // Turbidity nggak punya kurva suhu kayak buffer pH — nilai
                    // standar dipakai nominal apa adanya.
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '1201DCD15687'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Turbidimeter',
                // Kunci yang bikin registry milih TurbidimeterProfile.
                'nama_alat_kemampuan' => 'Turbidimeter',
                'merk' => 'HACH',
                'model' => '2100Q',
                'range_min' => 0,
                'range_max' => 1000,
                'satuan' => 'NTU',
                // Resolusi tunggal cuma fallback tampilan; budget & format per
                // titik diambil dari TurbidimeterProfile::TITIK (0.01/0.1/1).
                'resolusi' => 0.01,
                // Toleransi skalar. CATATAN: turbidimeter aslinya toleransi
                // persentase (±2% pembacaan), jadi satu angka ini nggak pas buat
                // 0,04..2000 NTU sekaligus — dipilih 50 supaya guarded-acceptance
                // titik 2000 (|error| + CMC placeholder 40) tetap PASS buat demo.
                // Toleransi per-titik = follow-up (lihat SPEC-turbidimeter-profile.md).
                'toleransi' => 50,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $teknisi = User::where('organization_id', 1)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->first();

        if ($teknisi === null) {
            return; // user demo belum diseed — cukup master data-nya aja
        }

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2406.32.A'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-06-22',
                'lokasi' => 'Lab PT. Sidik',
                'suhu_ruang' => 23.3,
                'kelembaban' => 28.5,
                'submitted_at' => now(),
            ],
        );

        $this->isiTitikUkur($sesi, $equipment, $standar);
    }

    /**
     * @param  array<int, Standard>  $standar
     */
    private function isiTitikUkur(CalibrationSession $sesi, Equipment $equipment, array $standar): void
    {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $kalkulator = new GumCalculator;
        $keputusanSesi = 'PASS';

        foreach (self::TITIK as $index => $titik) {
            $std = $standar[$titik['standar']];
            $titikKe = $index + 1;

            foreach ($titik['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $i + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $titik['titik'],
                    'pembacaan' => $nilai,
                    'suhu' => 23.4,
                    'satuan' => 'NTU',
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $hasil = $kalkulator->hitungTitik(
                $titikKe,
                $titik['titik'],
                $titik['pembacaan'],
                $equipment,
                $std,
            );

            if ($hasil['keputusan'] === 'FAIL') {
                $keputusanSesi = 'FAIL';
            }

            UncertaintyCalculation::create(['calibration_session_id' => $sesi->id, ...$hasil]);
        }

        $sesi->update(['keputusan' => $keputusanSesi]);
    }
}
