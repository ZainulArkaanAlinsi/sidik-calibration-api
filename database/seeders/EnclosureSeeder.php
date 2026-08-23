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
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data ENCLOSURE dari dua master:
 * `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm` (sesi 0123-CAL-524,
 * Incubator-02, Inkubator, kalibrator Yokogawa + Type N, 4 set point) dan
 * `… _Recorder.xlsm` (sesi 0304-CAL-624, Oven, kalibrator Recorder GL840 +
 * Type K, 3 set point).
 *
 * Sama seperti seeder sesi lain, ketidakpastiannya BENERAN dihitung lewat jalur
 * produksi (`EnclosureProfileBase::hitungPerGrup()`), bukan angka jadi — kalau
 * rumusnya berubah, data demo ikut berubah dan `Tests\Feature\EnclosureSesiTest`
 * langsung merah.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh baris CMC per jenis
 * enclosure dari lampiran akreditasi).
 */
class EnclosureSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Grid Yokogawa (Inkubator, Type N) — `INPUT DATA` master Constant/Yokogawa,
     * 9 termokopel (No.3–11) × 5 pembacaan + Indikator, per set point.
     *
     * @var list<array{setpoint: float, indikator: list<float>, sensors: list<array{no: int, pembacaan: list<float>}>}>
     */
    private const GRID_YOKO = [
        ['setpoint' => 15.0, 'indikator' => [15.0, 15.0, 15.0, 15.0, 15.0], 'sensors' => [
            ['no' => 3, 'pembacaan' => [15.0, 15.1, 15.1, 15.1, 15.1]],
            ['no' => 4, 'pembacaan' => [15.2, 15.3, 15.2, 15.3, 15.2]],
            ['no' => 5, 'pembacaan' => [15.1, 15.1, 15.1, 15.2, 15.2]],
            ['no' => 6, 'pembacaan' => [15.1, 15.1, 15.2, 15.2, 15.3]],
            ['no' => 7, 'pembacaan' => [15.0, 14.9, 14.9, 14.9, 14.9]],
            ['no' => 8, 'pembacaan' => [15.2, 15.2, 15.3, 15.3, 15.4]],
            ['no' => 9, 'pembacaan' => [15.0, 15.1, 15.1, 15.2, 15.2]],
            ['no' => 10, 'pembacaan' => [15.1, 15.2, 15.2, 15.2, 15.3]],
            ['no' => 11, 'pembacaan' => [14.9, 14.9, 14.9, 15.0, 15.0]],
        ]],
        ['setpoint' => 35.0, 'indikator' => [35.0, 35.0, 35.0, 35.0, 35.0], 'sensors' => [
            ['no' => 3, 'pembacaan' => [34.9, 34.9, 34.9, 34.9, 34.9]],
            ['no' => 4, 'pembacaan' => [34.9, 34.9, 34.9, 34.9, 34.9]],
            ['no' => 5, 'pembacaan' => [35.1, 35.0, 35.0, 35.0, 35.0]],
            ['no' => 6, 'pembacaan' => [34.8, 34.7, 34.8, 34.7, 34.8]],
            ['no' => 7, 'pembacaan' => [34.5, 34.5, 34.6, 34.6, 34.6]],
            ['no' => 8, 'pembacaan' => [34.9, 34.9, 35.0, 35.0, 35.1]],
            ['no' => 9, 'pembacaan' => [34.7, 34.7, 34.8, 34.8, 34.9]],
            ['no' => 10, 'pembacaan' => [35.0, 35.0, 35.0, 34.9, 34.9]],
            ['no' => 11, 'pembacaan' => [35.1, 35.1, 35.2, 35.2, 35.1]],
        ]],
        ['setpoint' => 75.0, 'indikator' => [75.0, 75.0, 75.0, 75.0, 75.0], 'sensors' => [
            ['no' => 3, 'pembacaan' => [74.6, 74.7, 74.8, 74.8, 74.8]],
            ['no' => 4, 'pembacaan' => [74.5, 74.7, 74.9, 74.9, 74.9]],
            ['no' => 5, 'pembacaan' => [75.0, 75.0, 75.1, 75.0, 75.1]],
            ['no' => 6, 'pembacaan' => [74.8, 74.8, 74.9, 74.9, 74.9]],
            ['no' => 7, 'pembacaan' => [74.7, 74.7, 74.8, 74.8, 74.8]],
            ['no' => 8, 'pembacaan' => [75.1, 75.1, 75.1, 75.1, 75.2]],
            ['no' => 9, 'pembacaan' => [74.6, 74.6, 74.6, 74.7, 74.7]],
            ['no' => 10, 'pembacaan' => [75.0, 75.0, 75.0, 75.0, 75.0]],
            ['no' => 11, 'pembacaan' => [74.9, 75.0, 75.0, 75.1, 75.1]],
        ]],
        ['setpoint' => 100.0, 'indikator' => [100.0, 100.0, 100.0, 100.0, 100.0], 'sensors' => [
            ['no' => 3, 'pembacaan' => [99.7, 99.6, 99.8, 99.8, 99.8]],
            ['no' => 4, 'pembacaan' => [99.8, 99.6, 99.7, 99.7, 99.8]],
            ['no' => 5, 'pembacaan' => [100.24, 100.1, 100.1, 100.24, 100.22]],
            ['no' => 6, 'pembacaan' => [100.05, 100.08, 100.11, 100.12, 100.13]],
            ['no' => 7, 'pembacaan' => [99.8, 99.9, 99.8, 99.9, 99.9]],
            ['no' => 8, 'pembacaan' => [99.5, 99.5, 99.6, 99.7, 99.7]],
            ['no' => 9, 'pembacaan' => [99.7, 99.7, 99.7, 99.8, 99.9]],
            ['no' => 10, 'pembacaan' => [100.0, 100.02, 100.02, 100.03, 100.03]],
            ['no' => 11, 'pembacaan' => [100.03, 100.03, 100.03, 100.05, 100.05]],
        ]],
    ];

    /**
     * Grid Recorder (Oven, Type K) — `INPUT DATA` master Recorder, 9 termokopel
     * (No.1–9) × 5 pembacaan + Indikator, dengan nomor Channel per termokopel.
     *
     * @var list<array{setpoint: float, indikator: list<float>, sensors: list<array{no: int, channel: int, pembacaan: list<float>}>}>
     */
    private const GRID_RECORDER = [
        ['setpoint' => 67.0, 'indikator' => [67.3, 67.4, 67.4, 67.3, 67.4], 'sensors' => [
            ['no' => 1, 'channel' => 1, 'pembacaan' => [66.8, 66.81, 66.82, 66.81, 66.8]],
            ['no' => 2, 'channel' => 2, 'pembacaan' => [67.85, 67.82, 67.81, 67.82, 67.82]],
            ['no' => 3, 'channel' => 3, 'pembacaan' => [69.05, 69.04, 69.05, 69.02, 69.02]],
            ['no' => 4, 'channel' => 4, 'pembacaan' => [68.15, 68.14, 68.13, 68.14, 68.15]],
            ['no' => 5, 'channel' => 5, 'pembacaan' => [66.55, 66.52, 66.51, 66.52, 66.5]],
            ['no' => 6, 'channel' => 6, 'pembacaan' => [67.45, 67.44, 67.43, 67.42, 67.41]],
            ['no' => 7, 'channel' => 7, 'pembacaan' => [68.3, 68.31, 68.32, 68.31, 68.32]],
            ['no' => 8, 'channel' => 8, 'pembacaan' => [67.0, 67.02, 67.02, 67.01, 67.02]],
            ['no' => 9, 'channel' => 9, 'pembacaan' => [67.3, 67.31, 67.32, 67.31, 67.32]],
        ]],
        ['setpoint' => 67.0, 'indikator' => [67.3, 67.4, 67.4, 67.3, 67.4], 'sensors' => [
            ['no' => 1, 'channel' => 1, 'pembacaan' => [66.8, 66.81, 66.82, 66.81, 66.8]],
            ['no' => 2, 'channel' => 2, 'pembacaan' => [67.85, 67.82, 67.81, 67.82, 67.82]],
            ['no' => 3, 'channel' => 3, 'pembacaan' => [69.05, 69.04, 69.05, 69.02, 69.02]],
            ['no' => 4, 'channel' => 4, 'pembacaan' => [68.15, 68.14, 68.13, 68.14, 68.15]],
            ['no' => 5, 'channel' => 5, 'pembacaan' => [66.55, 66.52, 66.51, 66.52, 66.5]],
            ['no' => 6, 'channel' => 6, 'pembacaan' => [67.45, 67.44, 67.43, 67.42, 67.41]],
            ['no' => 7, 'channel' => 7, 'pembacaan' => [68.3, 68.31, 68.32, 68.31, 68.32]],
            ['no' => 8, 'channel' => 8, 'pembacaan' => [67.0, 67.02, 67.02, 67.01, 67.02]],
            ['no' => 9, 'channel' => 9, 'pembacaan' => [67.3, 67.31, 67.32, 67.31, 67.32]],
        ]],
        ['setpoint' => 67.0, 'indikator' => [67.3, 67.4, 67.4, 67.3, 67.4], 'sensors' => [
            ['no' => 1, 'channel' => 1, 'pembacaan' => [66.8, 66.81, 66.82, 66.81, 66.8]],
            ['no' => 2, 'channel' => 2, 'pembacaan' => [67.85, 67.82, 67.81, 67.82, 67.82]],
            ['no' => 3, 'channel' => 3, 'pembacaan' => [69.05, 69.04, 69.05, 69.02, 69.02]],
            ['no' => 4, 'channel' => 4, 'pembacaan' => [68.15, 68.14, 68.13, 68.14, 68.15]],
            ['no' => 5, 'channel' => 5, 'pembacaan' => [66.55, 66.52, 66.51, 66.52, 66.5]],
            ['no' => 6, 'channel' => 6, 'pembacaan' => [67.45, 67.44, 67.43, 67.42, 67.41]],
            ['no' => 7, 'channel' => 7, 'pembacaan' => [68.3, 68.31, 68.32, 68.31, 68.32]],
            ['no' => 8, 'channel' => 8, 'pembacaan' => [67.0, 67.02, 67.02, 67.01, 67.02]],
            ['no' => 9, 'channel' => 9, 'pembacaan' => [67.3, 67.31, 67.32, 67.31, 67.32]],
        ]],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->first();

        if ($teknisi === null) {
            return;
        }

        $yokogawa = Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal'],
            [
                'organization_id' => 1, 'merk' => 'Yokogawa', 'model' => 'CA 150 Handy Cal',
                'serial_number' => '23P1005', 'no_sertifikat' => '23P1005', 'tertelusur_ke' => 'LK-202-IDN',
                'berlaku_sampai' => $this->berlakuSampaiDemo('Enclosure Yokogawa', '2026-08-12'),
                'ketidakpastian' => null, 'satuan_ketidakpastian' => EnclosureProfileBase::SATUAN,
                'faktor_cakupan' => 2, 'koefisien_suhu' => null,
            ],
        );

        // Merk 'Graphtech' → EnclosureProfileBase::merkKalibrator() memetakannya
        // ke tabel 'recorder'. Master menandainya EXPIRED (lihat
        // docs/pertanyaan-lab-enclosure.md #10); di demo masa berlakunya
        // dipanjangkan supaya sesinya bisa jalan.
        $recorder = Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Temperature Recorder Graphtech GL840'],
            [
                'organization_id' => 1, 'merk' => 'Graphtech', 'model' => 'GL840',
                'serial_number' => 'C305B1470', 'no_sertifikat' => 'C305B1470', 'tertelusur_ke' => 'LK-285-IDN',
                'berlaku_sampai' => $this->berlakuSampaiDemo('Enclosure Recorder', '2025-06-08'),
                'ketidakpastian' => null, 'satuan_ketidakpastian' => EnclosureProfileBase::SATUAN,
                'faktor_cakupan' => 2, 'koefisien_suhu' => null,
            ],
        );

        $this->seedSesi(
            customer: ['nama' => 'PT. Freshland Inovasi Sejahtera', 'alamat' => 'Jl. Cimerang No. 170, Padalarang, Kab. Bandung Barat, Jawa Barat 40553'],
            alat: ['nama_alat' => 'Incubator', 'nama_alat_kemampuan' => 'Inkubator', 'merk' => 'INCUCELL', 'model' => 'LSIS-B2Y/IC 55', 'serial_number' => 'D132469', 'range_min' => 15, 'range_max' => 100, 'kapasitas' => '100'],
            sesi: ['nomor_sesi' => '2405.03.AV', 'nomor_order' => '2405.03.AV', 'tanggal' => '2024-05-02', 'tipe_sensor' => 'Type N'],
            grid: self::GRID_YOKO,
            standar: $yokogawa,
            teknisi: $teknisi,
        );

        $this->seedSesi(
            customer: ['nama' => 'PT. Gunung Madu Plantations', 'alamat' => 'KM 90 Terbanggi Besar, Terusan Nunyai, Lampung Tengah'],
            alat: ['nama_alat' => 'Oven', 'nama_alat_kemampuan' => 'Oven', 'merk' => 'Memmert', 'model' => 'UN260', 'serial_number' => 'B616-0871', 'range_min' => 0, 'range_max' => 300, 'kapasitas' => '300'],
            sesi: ['nomor_sesi' => '2406.25.AI', 'nomor_order' => '2406.25.AI', 'tanggal' => '2024-06-27', 'tipe_sensor' => 'Type K'],
            grid: self::GRID_RECORDER,
            standar: $recorder,
            teknisi: $teknisi,
        );
    }

    /**
     * @param  array{nama: string, alamat: string}  $customer
     * @param  array{nama_alat: string, nama_alat_kemampuan: string, merk: string, model: string, serial_number: string, range_min: float|int, range_max: float|int, kapasitas: string}  $alat
     * @param  array{nomor_sesi: string, nomor_order: string, tanggal: string, tipe_sensor: string}  $sesi
     * @param  list<array{setpoint: float, indikator: list<float>, sensors: list<array<string, mixed>>}>  $grid
     */
    private function seedSesi(array $customer, array $alat, array $sesi, array $grid, Standard $standar, User $teknisi): void
    {
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $customer['nama']],
            ['organization_id' => 1, 'alamat' => $customer['alamat']],
        );

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Suhu dan Kelembapan'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $alat['serial_number']],
            [
                'organization_id' => 1, 'customer_id' => $pelanggan->id, 'equipment_category_id' => $kategori->id,
                'nama_alat' => $alat['nama_alat'],
                // Kunci pencocokan ke profil enclosure — ejaan PERSIS baris CMC.
                'nama_alat_kemampuan' => $alat['nama_alat_kemampuan'],
                'merk' => $alat['merk'], 'model' => $alat['model'],
                'range_min' => $alat['range_min'], 'range_max' => $alat['range_max'],
                'satuan' => EnclosureProfileBase::SATUAN, 'resolusi' => 0.1,
                'toleransi' => null, 'lokasi' => 'Lab PT. Sidik', 'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $baris = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $sesi['nomor_sesi']],
            [
                'equipment_id' => $equipment->id, 'nomor_order' => $sesi['nomor_order'],
                'teknisi_id' => $teknisi->id, 'standard_id' => $standar->id,
                'input_method' => 'manual', 'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $sesi['tanggal'], 'tanggal_terima' => $sesi['tanggal'],
                'lokasi' => 'lab', 'tipe_sensor' => $sesi['tipe_sensor'],
                'alat_merk' => $alat['merk'], 'alat_model' => $alat['model'], 'alat_serial_number' => $alat['serial_number'],
                'pemilik_nama' => $customer['nama'], 'pemilik_alamat' => $customer['alamat'],
                'spesifikasi_alat' => ['rentang_ukur' => (string) $alat['range_max'], 'kapasitas' => $alat['kapasitas'], 'resolusi' => '0.1'],
                'suhu_awal' => 23.7, 'suhu_akhir' => 23.7, 'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            ],
        );

        $this->isiGrid($baris, $equipment, $standar, $grid, $sesi['tipe_sensor']);
    }

    /**
     * @param  list<array{setpoint: float, indikator: list<float>, sensors: list<array<string, mixed>>}>  $grid
     */
    private function isiGrid(CalibrationSession $sesi, Equipment $equipment, Standard $standar, array $grid, string $tipeSensor): void
    {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $versiRumus = $this->versiRumusUntuk($sesi);
        $siapHitung = [];

        foreach ($grid as $index => $sp) {
            $titikKe = $index + 1;

            // Termokopel: satu baris per (sensor, pengulangan).
            foreach ($sp['sensors'] as $s) {
                foreach ($s['pembacaan'] as $i => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id, 'titik_ke' => $titikKe,
                        'pembacaan_ke' => $i + 1, 'sensor_ke' => $s['no'], 'peran_sensor' => 'termokopel',
                        'channel' => $s['channel'] ?? null,
                        'tahap' => 'sesudah_adjustment', 'titik_ukur' => $sp['setpoint'], 'pembacaan' => $nilai,
                        'satuan' => EnclosureProfileBase::SATUAN, 'standard_id' => $standar->id,
                        'input_source' => 'manual', 'is_verified' => true,
                    ]);
                }
            }

            // Indikator enclosure: satu baris per pengulangan, sensor_ke null.
            foreach ($sp['indikator'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id, 'titik_ke' => $titikKe,
                    'pembacaan_ke' => $i + 1, 'sensor_ke' => null, 'peran_sensor' => 'indikator',
                    'tahap' => 'sesudah_adjustment', 'titik_ukur' => $sp['setpoint'], 'pembacaan' => $nilai,
                    'satuan' => EnclosureProfileBase::SATUAN, 'standard_id' => $standar->id,
                    'input_source' => 'manual', 'is_verified' => true,
                ]);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $sp['setpoint'],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => $tipeSensor,
                    'sensor_grid' => $sp['sensors'],
                    'indikator' => $sp['indikator'],
                ],
            ];
        }

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($equipment);
        $hasil = $profil->hitungPerGrup($siapHitung, $equipment);

        foreach ($hasil['hitungan'] ?? [] as $row) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$row,
            ]);
        }

        $sesi->update(['keputusan' => null]);
    }
}
