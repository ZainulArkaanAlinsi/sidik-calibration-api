<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data contoh biar layar Dashboard & Daftar Alat di mobile ada isinya (bukan
 * dummy hardcode di app). Sengaja ada alat yang UDAH LEWAT jatuh tempo, supaya
 * status `overdue` & angka "alat_overdue" di dashboard kebukti jalan.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $pelanggan = collect([
            ['nama' => 'PT Maju Jaya', 'alamat' => 'Jl. Soekarno Hatta No. 12, Bandung', 'contact_person' => 'Rina', 'telepon' => '0812-1111-2222'],
            ['nama' => 'PT Karya Logam', 'alamat' => 'Kawasan Industri Cimahi Blok C5', 'contact_person' => 'Dedi', 'telepon' => '0813-3333-4444'],
        ])->map(fn (array $c) => Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $c['nama']],
            [...$c, 'organization_id' => 1],
        ));

        Standard::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'GB-STD-001'],
            [
                'nama' => 'Gauge Block Set Grade 0',
                'merk' => 'Mitutoyo',
                'model' => '516-905',
                'no_sertifikat' => 'SNSU/2025/P-0142',
                'tertelusur_ke' => 'SNSU-BSN',
                'berlaku_sampai' => now()->addYear(),
                'ketidakpastian' => 0.0004,
                'satuan_ketidakpastian' => 'mm',
                'faktor_cakupan' => 2,
            ],
        );

        $kategori = fn (string $kode) => EquipmentCategory::where('kode', $kode)->value('id');

        $alat = [
            [
                'nama_alat' => 'Jangka Sorong Mitutoyo', 'merk' => 'Mitutoyo', 'model' => 'CD-6"ASX',
                'serial_number' => 'MT-500-196-30', 'kode_kategori' => 'panjang', 'customer' => 0,
                'range_min' => 0, 'range_max' => 300, 'satuan' => 'mm', 'resolusi' => 0.01,
                'toleransi' => 0.05,
                'terakhir' => '-11 months', 'jatuh_tempo' => '+1 month', 'status' => 'aktif',
            ],
            [
                'nama_alat' => 'Micrometer Luar 0-25', 'merk' => 'Mitutoyo', 'model' => '103-137',
                'serial_number' => 'MT-103-137-A', 'kode_kategori' => 'panjang', 'customer' => 0,
                'range_min' => 0, 'range_max' => 25, 'satuan' => 'mm', 'resolusi' => 0.001,
                'toleransi' => 0.004,
                // Sengaja lewat jatuh tempo -> harus muncul sebagai `overdue`.
                'terakhir' => '-14 months', 'jatuh_tempo' => '-2 months', 'status' => 'aktif',
            ],
            [
                'nama_alat' => 'Timbangan Analitik', 'merk' => 'Ohaus', 'model' => 'PA224',
                'serial_number' => 'OH-PA224-77', 'kode_kategori' => 'massa', 'customer' => 1,
                'range_min' => 0, 'range_max' => 220, 'satuan' => 'g', 'resolusi' => 0.0001,
                'toleransi' => 0.0005,
                'terakhir' => '-6 months', 'jatuh_tempo' => '+6 months', 'status' => 'aktif',
            ],
            [
                'nama_alat' => 'Oven Memmert', 'merk' => 'Memmert', 'model' => 'UN55',
                'serial_number' => 'MM-UN55-19', 'kode_kategori' => 'suhu-dan-kelembapan', 'customer' => 1,
                'range_min' => 30, 'range_max' => 300, 'satuan' => '°C', 'resolusi' => 0.1,
                'toleransi' => 2.0,
                'terakhir' => '-13 months', 'jatuh_tempo' => '-1 month', 'status' => 'aktif',
            ],
            [
                'nama_alat' => 'pH Meter Bench', 'merk' => 'Hanna', 'model' => 'HI2211',
                'serial_number' => 'HN-2211-05', 'kode_kategori' => 'instrumen-analitik', 'customer' => 0,
                'range_min' => 0, 'range_max' => 14, 'satuan' => 'pH', 'resolusi' => 0.01,
                'toleransi' => 0.05,
                'terakhir' => '-3 months', 'jatuh_tempo' => '+9 months', 'status' => 'nonaktif',
            ],
        ];

        foreach ($alat as $a) {
            Equipment::updateOrCreate(
                ['organization_id' => 1, 'serial_number' => $a['serial_number']],
                [
                    'organization_id' => 1,
                    'customer_id' => $pelanggan[$a['customer']]->id,
                    'equipment_category_id' => $kategori($a['kode_kategori']),
                    'nama_alat' => $a['nama_alat'],
                    'merk' => $a['merk'],
                    'model' => $a['model'],
                    'range_min' => $a['range_min'],
                    'range_max' => $a['range_max'],
                    'satuan' => $a['satuan'],
                    'resolusi' => $a['resolusi'],
                    // Batas spesifikasi pabrikan. WAJIB ada: tanpa toleransi, PASS/FAIL
                    // nggak bisa diputusin, dan sesi kalibrasinya ditolak 422.
                    'toleransi' => $a['toleransi'],
                    'lokasi' => 'Lab Kalibrasi',
                    'tanggal_kalibrasi_terakhir' => now()->modify($a['terakhir']),
                    'tanggal_jatuh_tempo' => now()->modify($a['jatuh_tempo']),
                    'status' => $a['status'],
                ],
            );
        }

        $this->seedSertifikatContoh();
    }

    /**
     * Satu sesi kalibrasi + sertifikat "terbit", supaya halaman verifikasi QR
     * (`/verify/{qr_token}`) bisa dites sekarang — fitur kalibrasi & generator
     * sertifikat baru dibikin Minggu 4-8.
     *
     * qr_token-nya sengaja dibikin gampang diketik pas dev: `DEMOQR123`.
     * Yang asli nanti diacak.
     */
    private function seedSertifikatContoh(): void
    {
        $alat = Equipment::where('serial_number', 'MT-500-196-30')->first();
        $teknisi = User::where('employee_id', 'ASM-0002')->first();
        $admin = User::where('employee_id', 'ASM-0001')->first();

        if (! $alat || ! $teknisi || ! $admin) {
            return;
        }

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => 'SES/2026/07/0001'],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'reviewed_by' => $admin->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_DISETUJUI,
                'keputusan' => 'PASS',
                'tanggal_kalibrasi' => now()->subMonth(),
                'lokasi' => 'lab',
                'suhu_ruang' => 23.5,
                'kelembaban' => 55.0,
                'submitted_at' => now()->subMonth(),
                'reviewed_at' => now()->subMonth()->addDay(),
            ],
        );

        Certificate::updateOrCreate(
            ['organization_id' => 1, 'nomor' => 'CAL/2026/07/0001'],
            [
                'calibration_session_id' => $sesi->id,
                'issued_by' => $admin->id,
                'qr_token' => 'DEMOQR123',
                'diterbitkan_pada' => now()->subMonth()->addDay(),
                'berlaku_sampai' => now()->addMonths(11),
                'status' => Certificate::STATUS_TERBIT,
            ],
        );
    }
}
