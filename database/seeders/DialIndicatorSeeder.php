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
use App\Services\Calibration\Profiles\DialIndicatorProfile;
use App\Services\KondisiLingkungan;
use App\Support\DialIndicatorMentah;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Dial Indicator** — alat ke-30, kelompok Panjang.
 *
 * Masukannya digenerate `docs/skrip/gen-sesi-dial-indicator.py` ke
 * `database/data/sesi-master-dial-indicator.json`; hasilnya lahir dari
 * `DialIndicatorProfile::hitungPerGrup()`, bukan ditempel.
 *
 * ## U95 sesi ini SENGAJA beda dari yang tercetak di master
 *
 * Dua sebab, keduanya tercatat di `DialIndicatorCalculator`: umur drift dari
 * tanggal sesi (103 hari, bukan 695 hari `NOW()` master) dan panjang
 * sensitivitas dari tumpukan terpanjang (24,5 mm, bukan keping 21 mm). Angka
 * master yang asli dijaga `DialIndicatorMasterTest`.
 *
 * ## Baris standar balok ukur TIDAK ditulis di sini
 *
 * Set fisiknya milik `MicrometerSeeder` (GB-9122-0 S/N 160006), dan kedua
 * workbook tidak sepakat masa berlakunya: Micrometer `2026-01-24`, Dial
 * Indicator interval 3 tahun → `2027-01-24`. Menulisnya dua kali berarti yang
 * jalan belakangan diam-diam menimpa yang lain. Seeder ini cuma membacanya —
 * wajib jalan SESUDAH `MicrometerSeeder`. Pertanyaan lab §7.
 */
class DialIndicatorSeeder extends Seeder
{
    use MenstempelVersiRumus;

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $standar = Standard::where('organization_id', 1)->where('nama', 'Gauge Block Standard')->firstOrFail();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-dial-indicator.json')),
            true,
        );

        $m = $data['_sesi'];

        // `INPUT DATA!E23 = 1` → TH-1.
        $thermohygro = Standard::where('organization_id', 1)->where('nama', $m['thermohygro'])->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Panjang')],
            ['organization_id' => 1, 'nama' => 'Panjang'],
        );

        $profil = new DialIndicatorProfile;

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profil — WAJIB persis `namaAlatKemampuan()`.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas_mm'],
                'satuan' => 'mm',
                'resolusi' => (float) $m['resolusi_mm'],
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => (string) $m['nomor_sertifikat']],
            [
                'equipment_id' => $alat->id,
                'nomor_order' => $m['nomor_order'],
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $m['tanggal'],
                'tanggal_terima' => $m['tanggal_terima'],
                'lokasi' => 'lab',
                'suhu_awal' => $m['suhu_awal'],
                'suhu_akhir' => $m['suhu_akhir'],
                'kelembaban_awal' => $m['rh_awal'],
                'kelembaban_akhir' => $m['rh_akhir'],
                'alat_merk' => $m['merk'],
                'alat_model' => $m['model'],
                'alat_serial_number' => (string) $m['serial'],
                'pemilik_nama' => $m['pelanggan'],
                'pemilik_alamat' => $m['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas_mm'],
                    'resolusi' => (string) $m['resolusi_mm'],
                    'satuan' => 'mm',
                    DialIndicatorMentah::KUNCI_SESI => [
                        'satuan' => $m['satuan_alat'],
                        'kapasitas_mm' => (float) $m['kapasitas_mm'],
                        'resolusi_mm' => (float) $m['resolusi_mm'],
                        'pra_evaluasi' => array_map('floatval', $data['pra_evaluasi_mm']),
                        'balok_pra_evaluasi' => array_map('floatval', $data['balok_pra_evaluasi_mm']),
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $suhuRata = DialIndicatorMentah::rataSuhuRuang($m['suhu_awal'], $m['suhu_akhir']);
        $siapHitung = [];

        foreach ($data['titik'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $deret = [
                DialIndicatorMentah::PERAN_BALOK => array_map('floatval', $titik['balok_mm']),
                DialIndicatorMentah::PERAN_PEMBACAAN => array_map('floatval', $titik['pembacaan_mm']),
            ];
            $totalNominal = array_sum($deret[DialIndicatorMentah::PERAN_BALOK]);

            foreach ($deret as $peran => $nilai) {
                foreach ($nilai as $ke => $angka) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $totalNominal,
                        'pembacaan' => $angka,
                        'satuan' => 'mm',
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $totalNominal,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    ...$deret,
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    'suhu_ruang_rata' => $suhuRata,
                ],
            ];
        }

        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($profil->hitungPerGrup($siapHitung, $alat)['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
                'formula_version_id' => $versiRumus,
            ]);
        }

        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
