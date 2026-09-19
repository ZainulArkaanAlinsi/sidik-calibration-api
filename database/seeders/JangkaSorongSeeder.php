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
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use App\Services\KondisiLingkungan;
use App\Support\JangkaSorongMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Jangka Sorong (Vernier Caliper)** — lampiran LK-285-IDN no. 35.
 *
 * Sumbernya `Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm`
 * (ber-password), sesi `001-CAL-126`. Masukannya digenerate
 * `docs/skrip/gen-sesi-jangka-sorong.py`, bukan diketik.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN. Hasilnya lahir dari
 * `JangkaSorongProfile::hitungPerGrup()`, jadi kalau mesin hitungnya bergeser
 * `HitungUlangSemuaSesiTest` yang merah.
 *
 * ## Sesi ini TIDAK menerbitkan U95 — dan itu ditanam apa adanya
 *
 * Alat di sesi master berkapasitas 600 mm, sementara pita akreditasi Vernier
 * Caliper cuma 0-300 mm. Master tetap menerbitkannya (tanpa lantai CMC, dengan
 * logo LK-285-IDN); di sini sesinya ditahan dan `belum_dihitung` menyebut
 * alasannya. Kapasitasnya TIDAK diubah demi data demo yang "hijau" — mengarang
 * kapasitas berarti sesi contoh berhenti mewakili master yang dibuktikannya.
 * Pertanyaan lab §2.
 */
class JangkaSorongSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /** Caliper Checker — baris yang sama dengan `HeightGaugeSeeder::STANDAR`. */
    private const STANDAR = [
        'nama' => 'Caliper Checker',
        'merk' => 'Metrology',
        'model' => 'CMG-9060C',
        'serial' => '800035',
        'tertelusur' => 'LK-404-IDN',
        'berlaku_sampai' => '2028-01-09',
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $standar = $this->seedStandar();

        // Balok ukur Depth ditanam `MicrometerSeeder`; kalau belum ada, baris
        // Depth tetap tersimpan dengan standar sesi (Caliper Checker).
        $balokUkur = Standard::where('organization_id', 1)->where('nama', 'Gauge Block Standard')->first();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-jangka-sorong.json')),
            true,
        );
        $m = $data['_sesi'];

        $thermohygro = Standard::where('organization_id', 1)->where('nama', $m['thermohygro'])->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Panjang')],
            ['organization_id' => 1, 'nama' => 'Panjang'],
        );

        $profil = new JangkaSorongProfile;

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'JS-'.$m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profil — PERSIS `namaAlatKemampuan()`.
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

        $spek = [
            'rentang_ukur' => (string) $m['rentang'],
            'kapasitas' => (string) $m['kapasitas_mm'],
            'resolusi' => (string) $m['resolusi_mm'],
            'satuan' => 'mm',
            JangkaSorongMentah::KUNCI_SESI => [
                'satuan' => 'mm',
                'kapasitas_mm' => (float) $m['kapasitas_mm'],
                'resolusi_mm' => (float) $m['resolusi_mm'],
                'pra_evaluasi_outside' => array_map('floatval', $data['pra_evaluasi_outside_mm']),
                'pra_evaluasi_inside' => array_map('floatval', $data['pra_evaluasi_inside_mm']),
                'kesejajaran' => array_map(static fn (array $k): array => [
                    'posisi' => $k['posisi'],
                    'nominal' => (float) $k['nominal_mm'],
                    'pembacaan' => (float) $k['pembacaan_mm'],
                ], $data['kesejajaran']),
                'kerataan_muka_ukur' => $m['kerataan_muka_ukur'],
            ],
        ];

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
                'spesifikasi_alat' => $spek,
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $suhuRata = JangkaSorongMentah::rataSuhuRuang($m['suhu_awal'], $m['suhu_akhir']);
        $siapHitung = [];

        foreach (JangkaSorongMentah::GRUP as $grup) {
            // Nominal dari baris PRA-CETAK profil — jalur yang sama dengan
            // `susunBlokJangkaSorong()` — dan diadu ke sesi master supaya dua
            // sumber itu tidak menyimpang diam-diam.
            $praCetak = $profil->barisPraCetak($grup);
            $standarBaris = $grup === 'depth' ? ($balokUkur ?? $standar) : $standar;

            foreach ($data[$grup] as $titik) {
                $nominal = array_map('floatval', $titik['nominal_mm']);

                // Dipetakan lewat NOMINAL, bukan urutan: master Outside
                // melompati 25 mm (sepuluh baris), lembar memuat kesebelasnya.
                $posisi = null;
                foreach ($praCetak as $i => $b) {
                    if ($b['nominal'] == $nominal) {
                        $posisi = $i;
                        break;
                    }
                }

                if ($posisi === null) {
                    throw new \RuntimeException("Nominal {$grup} ".implode('+', $nominal).' master tidak ada di baris pra-cetak lembar.');
                }

                $titikKe = JangkaSorongMentah::OFFSET_TITIK[$grup] + $posisi + 1;
                $titikUkur = array_sum($nominal);
                $deret = [
                    JangkaSorongMentah::peranNominal($grup) => [$nominal, JangkaSorongMentah::SATUAN_NOMINAL],
                    JangkaSorongMentah::peranPembacaan($grup) => [array_map('floatval', $titik['pembacaan_mm']), 'mm'],
                ];

                foreach ($deret as $peran => [$nilai, $satuan]) {
                    foreach ($nilai as $ke => $angka) {
                        RawMeasurement::create([
                            'calibration_session_id' => $sesi->id,
                            'titik_ke' => $titikKe,
                            'pembacaan_ke' => $ke + 1,
                            'sensor_ke' => $ke + 1,
                            'peran_sensor' => $peran,
                            'tahap' => 'sesudah_adjustment',
                            'titik_ukur' => $titikUkur,
                            'pembacaan' => $angka,
                            'satuan' => $satuan,
                            'standard_id' => $standarBaris->id,
                            'input_source' => 'manual',
                            'is_verified' => true,
                        ]);
                    }
                }

                $siapHitung[] = [
                    'titik_ke' => $titikKe,
                    'titik_ukur' => $titikUkur,
                    'pembacaan' => [],
                    'standard' => $standarBaris,
                    'konteks' => [
                        JangkaSorongMentah::KONTEKS_GRUP => $grup,
                        JangkaSorongMentah::KONTEKS_NOMINAL => $nominal,
                        JangkaSorongMentah::KONTEKS_PEMBACAAN => array_map('floatval', $titik['pembacaan_mm']),
                        'spesifikasi_alat' => $sesi->spesifikasi_alat,
                        'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                        'suhu_ruang_rata' => $suhuRata,
                    ],
                ];
            }
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    private function seedStandar(): Standard
    {
        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => self::STANDAR['nama']],
            [
                'organization_id' => 1,
                'merk' => self::STANDAR['merk'],
                'model' => self::STANDAR['model'],
                'serial_number' => self::STANDAR['serial'],
                'no_sertifikat' => self::STANDAR['serial'],
                'tertelusur_ke' => self::STANDAR['tertelusur'],
                'berlaku_sampai' => $this->berlakuSampaiDemo(self::STANDAR['nama'], self::STANDAR['berlaku_sampai']),
                'ketidakpastian' => 4.1,
                'satuan_ketidakpastian' => 'µm',
                'faktor_cakupan' => 2,
            ],
        );
    }

    /** @param  array<string, mixed>|null  $perGrup */
    private function tulisHitungan(CalibrationSession $sesi, ?array $perGrup): void
    {
        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($perGrup['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
                'formula_version_id' => $versiRumus,
            ]);
        }

        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
