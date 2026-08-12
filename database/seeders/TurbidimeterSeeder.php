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
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Turbidimeter dari `Master Olah Data_Turbidimeter.xlsm` (sheet INPUT DATA)
 * — standar turbidity 1/100/1000 NTU, alat HACH 2100Q, dan satu sesi kalibrasi
 * end-to-end yang ketidakpastiannya BENERAN dihitung `GumCalculator` (bukan
 * angka jadi ditempel), sama polanya kayak `PhMeterSeeder`.
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
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Tiga larutan standar turbidity ASLI yang dimiliki lab (sheet DATABASE
     * workbook S13/S14/S15): Supelco/Merck, U95 sertifikat 0,04 / 3 / 21 NTU,
     * k=2, tertelusur Merck KGaA. Bukan placeholder.
     *
     * `berlaku_sampai` = Due Date ASLI dari sheet DATABASE workbook. Ketiganya
     * UDAH LEWAT (akhir 2025) — dipanjangin `MemanjangkanMasaBerlaku` buat demo,
     * dan perpanjangannya diumumin. Di produksi ketiga larutan ini memang perlu
     * dikalibrasi ulang; nggak ada kode yang bisa mbenerin itu.
     *
     * @var list<array{nama: string, serial: string, ketidakpastian: float, berlaku_sampai: string}>
     */
    private const STANDAR = [
        ['nama' => 'Turbidity Standard 1 NTU', 'serial' => 'LRAD7304', 'ketidakpastian' => 0.04, 'berlaku_sampai' => '2025-12-04'],
        ['nama' => 'Turbidity Standard 100 NTU', 'serial' => 'LRAD7305', 'ketidakpastian' => 3.0, 'berlaku_sampai' => '2025-11-03'],
        ['nama' => 'Turbidity Standard 1000 NTU', 'serial' => 'LRAD7089', 'ketidakpastian' => 21.0, 'berlaku_sampai' => '2025-12-19'],
    ];

    /**
     * Pembacaan After Adjustment per titik (INPUT DATA G/I/K 46-50) — data job
     * trial 0189-CAL-624. STDEV keluar 0,00894 / 0,04472 / 0,54772.
     *
     * @var list<array{titik: float, standar: int, pembacaan: list<float>}>
     */
    private const TITIK = [
        ['titik' => 1.0, 'standar' => 0, 'pembacaan' => [1, 1, 1, 1, 1.02]],
        ['titik' => 100.0, 'standar' => 1, 'pembacaan' => [100, 100, 100, 100, 100.1]],
        ['titik' => 1000.0, 'standar' => 2, 'pembacaan' => [1000, 1000, 1001, 1001, 1001]],
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
                    'merk' => 'Supelco/Merck',
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => 'Merck KGaA',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
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
                // persentase (±2% pembacaan) - satu angka ini nggak pas buat
                // 1..1000 NTU sekaligus; dipilih 24 supaya guarded-acceptance
                // titik 1000 (|error| 0,6 + U 22) tetap PASS buat demo. Toleransi
                // per-titik = follow-up (lihat SPEC-turbidimeter-profile.md).
                'toleransi' => 24,
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

        // Thermohygro TH-6 (INPUT DATA "Thermohygro used: TH-6"). Sertifikatnya
        // udah diseed ThermohygroSeeder (yang jalan sebelum ini) dengan koreksi
        // suhu -0,23 & kelembaban -3,17 + U95 std 1,7 / 4,8.
        $th6 = Standard::where('organization_id', 1)->where('nama', 'TH-6')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2406.32.A'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th6?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-06-22',
                'lokasi' => 'lab',
                // Pembacaan thermohygro AWAL & AKHIR apa adanya (PERHITUNGAN
                // KONDISI LINGKUNGAN). suhu_ruang & U95-nya JANGAN ditulis di
                // sini — biar `KondisiLingkungan` yang ngitung dari awal/akhir +
                // koreksi sertifikat TH-6, persis kayak alur teknisi beneran.
                // Kelembaban 55/55 ngikut sheet PERHITUNGAN (INPUT DATA nulis
                // Awal=2 — salah ketik, 2 %RH mustahil di lab).
                'suhu_awal' => 23.2,
                'suhu_akhir' => 23.4,
                'kelembaban_awal' => 55.0,
                'kelembaban_akhir' => 55.0,
                'submitted_at' => now(),
            ],
        );

        // suhu_ruang -> 23,07 (avg 23,3 + koreksi -0,23), U95 -> √(1,7²+0,2²) =
        // 1,7117242768623688; kelembaban -> 51,83, U95 -> 4,8. Cocok sama sel
        // "U95% Sertifikat" di PERHITUNGAN.csv baris 14-15.
        app(KondisiLingkungan::class)->terapkan($sesi);

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

        // Sekali per sesi, di luar loop — semua titik pakai versi yang sama.
        $versiRumus = $this->versiRumusUntuk($sesi);

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

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }

        $sesi->update(['keputusan' => $keputusanSesi]);
    }
}
