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
use App\Services\Calibration\Profiles\TitsProfile;
use App\Services\Calibration\TabelKalibratorSuhu;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data TITS (Temperature Indikator Tanpa Sensor, alat ke-11) dari dua master:
 * `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` dan `… fungsi Source
 * utk UUT.xlsm`.
 *
 * DUA sesi, karena alat ini punya dua MODE dan dua-duanya harus kelihatan jalan:
 *
 *  - **01-CAL-625** (order 22506.01.A, 8 Mei 2025) — mode `measure`, sensor
 *    Type N, kalibrator Yokogawa, sembilan titik −20…1000 °C.
 *  - **0159-CAL-626** (order 2606.08.C, 10 Juni 2026) — mode `source`, sensor
 *    Type S, kalibrator Yokogawa, delapan titik 0…1200 °C.
 *
 * Sama seperti sembilan seeder sesi lain, ketidakpastiannya BENERAN dihitung
 * lewat jalur produksi (`TitsProfile::hitungPerGrup()`), bukan angka jadi yang
 * ditempel — kalau rumusnya berubah, data demo ini ikut berubah dan test yang
 * mencocokkannya ke master langsung merah.
 *
 * ## Dua kalibrator, dan kolom `merk`-nya bukan hiasan
 *
 * `TitsProfile` membaca tabel koreksi lewat `standards.merk` (`constant` /
 * `yokogawa`). Salah tulis di kolom itu berarti sesi jatuh ke tabel kalibrator
 * yang salah — atau tidak kehitung sama sekali. Nama & serialnya juga harus
 * cocok dengan `TitsProfile::STANDARD_TERCETAK`, kalau tidak kotak "Usage Check"
 * di lembar kerja tidak ketemu standarnya.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh baris CMC "Temperature
 * Indicator tanpa Sensor" per tipe sensor dari lampiran akreditasi) dan
 * `ThermohygroSeeder`.
 */
class TitsSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Dua kalibrator suhu lab (`DATABASE!Q13:U16` + `STANDAR KALIBRATOR` B4:B6
     * & M4:M6).
     *
     * `ketidakpastian` sengaja NULL: ketidakpastian kalibrator ini tidak satu
     * angka — dia beda per tipe sensor DAN per titik, dan tabelnya hidup di
     * `database/data/tabel-kalibrator-suhu.json`. Mengisi satu angka di sini
     * berarti menaruh nilai yang kelihatan sah tapi tidak pernah dipakai
     * hitungan, dan itu jenis data yang menyesatkan pembaca jejak audit.
     *
     * @var list<array{nama: string, merk: string, model: string, serial: string, tertelusur: string, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Temperature Calibrator Constant 40T',
            'merk' => 'Constant',
            'model' => '40T',
            'serial' => '99875850',
            'tertelusur' => 'LK-202-IDN',
            'berlaku_sampai' => '2025-08-28',
        ],
        [
            'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            'merk' => 'Yokogawa',
            'model' => 'CA 150 Handy Cal',
            'serial' => '23P1005',
            'tertelusur' => 'LK-241-IDN',
            'berlaku_sampai' => '2026-08-12',
        ],
    ];

    /**
     * Sesi mode MEASURE — `INPUT DATA` B33:H50 master fungsi Measure.
     *
     * Enam pembacaan per titik: tiga UP lalu tiga DOWN, urut seperti di kertas.
     *
     * @var list<array{titik: float, pembacaan: list<float>}>
     */
    private const TITIK_MEASURE = [
        ['titik' => -20.0, 'pembacaan' => [-20.05, -20.11, -20.09, -20.49, -20.60, -20.42]],
        ['titik' => 10.0, 'pembacaan' => [9.85, 9.97, 9.89, 9.38, 9.45, 9.61]],
        ['titik' => 50.0, 'pembacaan' => [50.16, 50.00, 50.02, 49.55, 49.56, 49.52]],
        ['titik' => 100.0, 'pembacaan' => [100.30, 100.15, 100.20, 99.90, 99.75, 99.65]],
        ['titik' => 200.0, 'pembacaan' => [199.70, 199.65, 199.75, 199.35, 199.40, 199.25]],
        ['titik' => 400.0, 'pembacaan' => [399.85, 399.85, 399.80, 400.80, 399.80, 399.80]],
        ['titik' => 600.0, 'pembacaan' => [600.70, 600.80, 600.60, 599.90, 600.10, 599.90]],
        ['titik' => 800.0, 'pembacaan' => [800.80, 800.80, 800.90, 800.00, 800.00, 800.00]],
        ['titik' => 1000.0, 'pembacaan' => [1000.10, 1000.00, 1000.10, 1000.10, 1000.10, 1000.10]],
    ];

    /**
     * Sesi mode SOURCE — `INPUT DATA` B33:H48 master fungsi Source.
     *
     * Di mode ini `titik` adalah angka yang di-SET di UUT, dan `pembacaan`
     * adalah bacaan KALIBRATOR. Kebalikan dari mode measure.
     *
     * @var list<array{titik: float, pembacaan: list<float>}>
     */
    private const TITIK_SOURCE = [
        ['titik' => 0.0, 'pembacaan' => [4.9, 4.9, 4.9, 4.7, 4.7, 4.7]],
        ['titik' => 100.0, 'pembacaan' => [104.6, 104.6, 104.6, 104.0, 104.0, 104.0]],
        ['titik' => 300.0, 'pembacaan' => [304.3, 304.3, 304.3, 304.6, 304.6, 304.6]],
        ['titik' => 500.0, 'pembacaan' => [503.9, 503.9, 503.9, 504.0, 504.0, 504.0]],
        ['titik' => 700.0, 'pembacaan' => [704.5, 704.5, 704.5, 704.6, 704.6, 704.6]],
        ['titik' => 900.0, 'pembacaan' => [904.1, 904.1, 904.1, 904.2, 904.2, 904.2]],
        ['titik' => 1100.0, 'pembacaan' => [1103.8, 1103.8, 1103.8, 1103.9, 1103.9, 1103.9]],
        ['titik' => 1200.0, 'pembacaan' => [1203.7, 1203.7, 1203.7, 1203.7, 1203.7, 1203.7]],
    ];

    public function run(): void
    {
        $standar = $this->seedStandar();

        $teknisi = User::where('organization_id', 1)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->first();

        if ($teknisi === null) {
            return; // user demo belum diseed — cukup master data-nya aja
        }

        // TH-4 di kedua master (`INPUT DATA` E23 = 4).
        $th4 = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();
        $yokogawa = $standar['Yokogawa'];

        $this->seedSesi(
            customer: [
                'nama' => 'PT. Sistem Dirgantara Inovasi Teknologi',
                'alamat' => 'Kawasan Niaga MIM Blok J No.25, Jl. Soekarno Hatta 590, Bandung, Jawa Barat',
            ],
            alat: [
                'nama_alat' => 'Temperature Calibrator',
                'merk' => 'Graphtech',
                'model' => 'GL840',
                'serial_number' => 'C305B1470',
                'range_min' => 0,
                'range_max' => 1000,
                'kapasitas' => '2000',
            ],
            sesi: [
                'nomor_sesi' => '22506.01.A',
                'nomor_order' => '22506.01.A',
                'tanggal' => '2025-05-08',
                'mode' => TabelKalibratorSuhu::MODE_MEASURE,
                'tipe_sensor' => 'Type N',
            ],
            titik: self::TITIK_MEASURE,
            standar: $yokogawa,
            teknisi: $teknisi,
            thermohygro: $th4,
        );

        $this->seedSesi(
            customer: [
                'nama' => 'PT GE Nusantara Turbine Services',
                'alamat' => 'Jl. Pajajaran No. 154, KP IV Bandung 40174',
            ],
            alat: [
                'nama_alat' => 'Temperature Recorder Controller',
                'merk' => 'Siemens',
                'model' => 'Simatic IPC477FE',
                'serial_number' => '44C-00021',
                'range_min' => 0,
                'range_max' => 1200,
                // Master menulis "-" di kolom Kapasitas Alat: alat ini nggak
                // punya angka kapasitas terpisah dari rentang ukurnya.
                'kapasitas' => '-',
            ],
            sesi: [
                'nomor_sesi' => '2606.08.C',
                'nomor_order' => '2606.08.C',
                'tanggal' => '2026-06-10',
                'mode' => TabelKalibratorSuhu::MODE_SOURCE,
                'tipe_sensor' => 'Type S',
            ],
            titik: self::TITIK_SOURCE,
            standar: $yokogawa,
            teknisi: $teknisi,
            thermohygro: $th4,
        );
    }

    /**
     * @return array<string, Standard>
     */
    private function seedStandar(): array
    {
        $hasil = [];

        foreach (self::STANDAR as $s) {
            $hasil[$s['merk']] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'],
                    'model' => $s['model'],
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => $s['tertelusur'],
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    // Lihat catatan di [STANDAR] — ketidakpastiannya per titik &
                    // per tipe sensor, bukan satu angka.
                    'ketidakpastian' => null,
                    'satuan_ketidakpastian' => TitsProfile::SATUAN,
                    'faktor_cakupan' => 2,
                    'koefisien_suhu' => null,
                ],
            );
        }

        return $hasil;
    }

    /**
     * @param  array{nama: string, alamat: string}  $customer
     * @param  array{nama_alat: string, merk: string, model: string, serial_number: string, range_min: float|int, range_max: float|int, kapasitas: string}  $alat
     * @param  array{nomor_sesi: string, nomor_order: string, tanggal: string, mode: string, tipe_sensor: string}  $sesi
     * @param  list<array{titik: float, pembacaan: list<float>}>  $titik
     */
    private function seedSesi(
        array $customer,
        array $alat,
        array $sesi,
        array $titik,
        Standard $standar,
        User $teknisi,
        ?Standard $thermohygro,
    ): void {
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
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $alat['nama_alat'],
                // Kunci pencocokan ke TitsProfile — ejaannya harus PERSIS
                // seperti di lampiran akreditasi.
                'nama_alat_kemampuan' => 'Temperature Indicator tanpa Sensor',
                'merk' => $alat['merk'],
                'model' => $alat['model'],
                'range_min' => $alat['range_min'],
                'range_max' => $alat['range_max'],
                'satuan' => TitsProfile::SATUAN,
                'resolusi' => 0.1,
                // NULL, bukan 0: master TITS nggak punya batas keberterimaan
                // sama sekali dan sertifikatnya nggak nyetak vonis PASS/FAIL —
                // TitsProfile::punyaToleransi() = false.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $baris = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $sesi['nomor_sesi']],
            [
                'equipment_id' => $equipment->id,
                'nomor_order' => $sesi['nomor_order'],
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $sesi['tanggal'],
                'tanggal_terima' => $sesi['tanggal'],
                'lokasi' => 'lab',
                'mode_kalibrasi' => $sesi['mode'],
                'tipe_sensor' => $sesi['tipe_sensor'],
                'alat_merk' => $alat['merk'],
                'alat_model' => $alat['model'],
                'alat_serial_number' => $alat['serial_number'],
                'pemilik_nama' => $customer['nama'],
                'pemilik_alamat' => $customer['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $alat['range_max'],
                    'kapasitas' => $alat['kapasitas'],
                    'resolusi' => '0.1',
                ],
                // Kondisi lingkungan sama di kedua master (`INPUT DATA` E21:F22).
                // suhu_ruang & U95-nya JANGAN ditulis — biar `KondisiLingkungan`
                // yang menghitungnya dari awal/akhir + koreksi sertifikat TH-4,
                // persis seperti alur teknisi.
                'suhu_awal' => 24.1,
                'suhu_akhir' => 24.3,
                'kelembaban_awal' => 61,
                'kelembaban_akhir' => 62,
            ],
        );

        app(KondisiLingkungan::class)->terapkan($baris);

        $this->isiTitikUkur($baris, $equipment, $standar, $titik, $sesi['mode'], $sesi['tipe_sensor']);
    }

    /**
     * @param  list<array{titik: float, pembacaan: list<float>}>  $titik
     */
    private function isiTitikUkur(
        CalibrationSession $sesi,
        Equipment $equipment,
        Standard $standar,
        array $titik,
        string $mode,
        string $tipeSensor,
    ): void {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $versiRumus = $this->versiRumusUntuk($sesi);
        $siapHitung = [];

        foreach ($titik as $index => $t) {
            $titikKe = $index + 1;

            foreach ($t['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    // 1–3 UP, 4–6 DOWN. Urutannya yang membawa arah; TITS
                    // nggak melaporkan histeresis terpisah.
                    'pembacaan_ke' => $i + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $t['titik'],
                    'pembacaan' => $nilai,
                    'satuan' => TitsProfile::SATUAN,
                    'standard_id' => $standar->id,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $t['titik'],
                'pembacaan' => $t['pembacaan'],
                'standard' => $standar,
                'konteks' => ['mode_tits' => $mode, 'tipe_sensor' => $tipeSensor],
            ];
        }

        // Jalur produksi yang sama dengan controller: satu budget untuk seluruh
        // sesi, bukan per titik.
        $hasil = (new TitsProfile)->hitungPerGrup($siapHitung, $equipment);

        foreach ($hasil['hitungan'] ?? [] as $baris) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$baris,
            ]);
        }

        // TITS nggak divonis (punyaToleransi=false) — keputusan sesi null.
        $sesi->update(['keputusan' => null]);
    }
}
