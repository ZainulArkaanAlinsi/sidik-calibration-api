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
use App\Services\Calibration\Profiles\BuretDigitalProfile;
use App\Services\Calibration\Profiles\DispensettProfile;
use App\Services\Calibration\Profiles\PistonPipetteProfile;
use App\Services\Calibration\Profiles\PistonVolumeProfile;
use App\Support\PistonVolumeMentah as M;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tiga sesi contoh keluarga PISTON VOLUME.
 *
 * - **Piston Pipette** (volume tetap) & **Buret Digital** (graduated): data
 *   contoh master Fixed & Graduated APA ADANYA, dibaca dari
 *   `database/data/sesi-master-piston-volume.json` (keluaran generator yang
 *   mengadu 274 sel ke cache Excel) — bukan diketik ulang.
 * - **Dispensett**: SINTETIS — data Fixed yang sama dengan jenis Dispensett
 *   Single Stroke, supaya profil ketiga punya sesi contoh. Ditulis terang di
 *   sini supaya tidak dikira data lab.
 *
 * Tanggal sesi 2026-02-13 (`NOW()` tersimpan di kedua master): di dalam masa
 * berlaku neraca (2027-01-19), termometer Yokogawa (2026-08-12), dan sensor
 * PT100 (2027-02-15) menurut tabel master. Standar neraca, Yokogawa, dan PT100
 * sudah diseed `AnakTimbanganSeeder`/`TitsSeeder`/`Suhu3AlatSeeder` — seeder ini
 * memakainya, tidak menimpanya.
 */
class PistonVolumeSeeder extends Seeder
{
    use MenstempelVersiRumus;

    private const SERIAL_NERACA = [
        'Analytical Balance' => '1129063525',
        'Electronic Balance Fujitsu' => 'SIDIK/134/2024',
        'Electronic Balance Excellent' => 'HSEX1403752',
    ];

    public function run(): void
    {
        $contoh = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true)['sesi'];
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $kategori = EquipmentCategory::firstOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Volume')],
            ['organization_id' => 1, 'nama' => 'Volume', 'kode' => Str::slug('Volume')],
        );
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT PIPET CONTOH SENTOSA'],
            ['organization_id' => 1, 'alamat' => 'Jl. Contoh Laboratorium No. 9, Kec. Contoh Barat, Kab. Contoh 40100'],
        );

        $fixed = $contoh['fixed']['masukan'];
        $grad = $contoh['graduated']['masukan'];

        $this->sesi(new PistonPipetteProfile, $teknisi, $kategori, $pelanggan, 'DEMO-PP-FIX-001', 'Micropipette', $fixed, null);
        $this->sesi(new BuretDigitalProfile, $teknisi, $kategori, $pelanggan, 'DEMO-BD-GRD-001', 'Buret Digital', $grad, $grad['sub_jenis']);
        $this->sesi(new DispensettProfile, $teknisi, $kategori, $pelanggan, 'DEMO-DS-FIX-001', 'Dispensett', $fixed, 'single_stroke');
    }

    /** @param  array<string, mixed>  $m */
    private function sesi(
        PistonVolumeProfile $profil,
        User $teknisi,
        EquipmentCategory $kategori,
        Customer $pelanggan,
        string $serial,
        string $namaAlat,
        array $m,
        ?string $subJenis,
    ): void {
        $standar = Standard::where('organization_id', 1)
            ->where('serial_number', self::SERIAL_NERACA[$m['timbangan']])
            ->firstOrFail();
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();
        $lingkungan = [
            'suhu_awal' => $m['suhu_ruang'][0], 'suhu_akhir' => $m['suhu_ruang'][1],
            'kelembaban_awal' => $m['kelembaban'][0], 'kelembaban_akhir' => $m['kelembaban'][1],
            'tekanan_awal' => $m['tekanan_udara'][0], 'tekanan_akhir' => $m['tekanan_udara'][1],
        ];

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $serial],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $namaAlat,
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Contoh Pipet',
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas'],
                'satuan' => (string) $m['satuan'],
                'resolusi' => null,
                'toleransi' => null,
                'lokasi' => 'Lab. Volumetrik PT. SIDIK',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $serial],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2026-02-13',
                'tanggal_terima' => '2026-02-13',
                'lokasi' => 'lab',
                ...$lingkungan,
                'alat_merk' => 'Contoh Pipet',
                'alat_serial_number' => $serial,
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => [
                    'keluarga' => $m['keluarga'],
                    'sub_jenis' => $subJenis,
                    'satuan' => $m['satuan'],
                    'kapasitas' => $m['kapasitas'],
                    'timbangan' => $m['timbangan'],
                    'penguapan' => array_combine(
                        range(1, count($m['titik'])),
                        array_map(static fn (array $t): float => (float) $t['penguapan'], $m['titik']),
                    ),
                ]],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($m['titik'] as $i => $t) {
            $titikKe = $i + 1;

            foreach ([M::PERAN_KUMULATIF => [$t['kumulatif'], 0, 'g'], M::PERAN_SUHU_AIR => [$t['suhu_air'], 1, '°C']] as $peran => [$deret, $awal, $satuan]) {
                foreach (array_values($deret) as $urutan => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $urutan + 1,
                        'sensor_ke' => $urutan + $awal,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => (float) $t['nominal'],
                        'pembacaan' => (float) $nilai,
                        'satuan' => $satuan,
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => (float) $t['nominal'],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    M::KONTEKS_KUMULATIF => array_map('floatval', $t['kumulatif']),
                    M::KONTEKS_SUHU_AIR => array_map('floatval', $t['suhu_air']),
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => '2026-02-13',
                    ...$lingkungan,
                ],
            ];
        }

        $hasil = $profil->hitungPerGrup($siapHitung, $alat);

        if (($hasil['belum_dihitung'] ?? []) !== []) {
            throw new \RuntimeException(sprintf('Sesi contoh %s tidak bisa dihitung: %s', $serial, $hasil['belum_dihitung'][0]['alasan']));
        }

        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($hasil['hitungan'] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
                'formula_version_id' => $versiRumus,
            ]);
        }
    }
}
