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
use App\Services\Calibration\Profiles\DifferentialPressureProfile;
use App\Services\Calibration\Profiles\PressureGaugeProfile;
use App\Services\Calibration\Profiles\TekananProfile;
use App\Services\Calibration\Profiles\VacuumGaugeProfile;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Support\TekananMentah as M;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Kalibrator tekanan lab + tiga sesi contoh keluarga TEKANAN.
 *
 * ## Dari mana angkanya
 *
 * Ketiga sesi DATA SINTETIS di dalam rentang kalibratornya, ditulis terang di
 * sini supaya tidak dikira data lab. Angka hasilnya DIHITUNG profil, tidak
 * ditempel. Kenapa tidak memakai data contoh master:
 *
 * - DRUCK07G mengkalibrasi sampai 1500 cmHg (≈2000 kPa) dengan kalibrator
 *   200 kPa, Differential 0–10 psi (≈690 mbar) dengan kalibrator ±10 mbar —
 *   keduanya diblokir penjaga rentang (temuan T-4), dan itu memang benar.
 * - Data contoh SPMK di dalam rentang, tapi SPMK 700 jatuh tempo 2026-08-05,
 *   jadi sesi yang menunggu persetujuan dengan standar itu ditahan validator.
 *
 * Data contoh master tetap diuji utuh — di `TekananMasterTest` (962 sel) dan
 * `TekananSesiTest` (SPMK lewat jalur API).
 */
class TekananSeeder extends Seeder
{
    use MenstempelVersiRumus;

    /**
     * Kalibrator yang punya master olah data. Tanggal jatuh tempo dari
     * `DATABASE!X13` masing-masing master — KECUALI DRUCK07G, yang menulis dua
     * tanggal berbeda untuk standar yang sama (`STANDAR_DRUCK!K3` 2027-04-29
     * lawan `DATABASE!X13` 2028-04-29, temuan T-5). Yang diambil yang LEBIH
     * AWAL: kalau salah, sesinya tertahan lebih cepat, bukan terbit memakai
     * standar yang sudah lewat masanya. Pertanyaan lab P-9.
     */
    private const STANDAR = [
        [
            'serial_number' => '223180480', 'nama' => 'SPMK 700', 'merk' => 'SPMK', 'model' => 'SPMK 700',
            'tertelusur_ke' => 'LK-025-IDN', 'berlaku_sampai' => '2026-08-05',
            'ketidakpastian' => 0.12, 'satuan_ketidakpastian' => 'bar',
        ],
        [
            'serial_number' => '211H18280008', 'nama' => 'Additel ADT681-05-DP5-MBAR', 'merk' => 'Additel',
            'model' => 'ADT681-05-DP5-MBAR', 'tertelusur_ke' => 'BSN-SNSU', 'berlaku_sampai' => '2027-08-19',
            'ketidakpastian' => 0.003, 'satuan_ketidakpastian' => 'mbar',
        ],
        [
            'serial_number' => '5568079', 'nama' => 'Druck DPI611-07G', 'merk' => 'GE',
            'model' => 'Druck DPI611-07G', 'tertelusur_ke' => 'LK-045-IDN', 'berlaku_sampai' => '2027-04-29',
            'ketidakpastian' => 0.09, 'satuan_ketidakpastian' => 'kPa',
        ],
        [
            'serial_number' => '5608066', 'nama' => 'Druck DPI611-13G', 'merk' => 'GE',
            'model' => 'Druck DPI611-13G', 'tertelusur_ke' => 'LK-460-IDN', 'berlaku_sampai' => '2028-04-02',
            'ketidakpastian' => 0.079, 'satuan_ketidakpastian' => 'psi',
        ],
    ];

    public function run(): void
    {
        foreach (self::STANDAR as $s) {
            Standard::updateOrCreate(
                ['organization_id' => 1, 'serial_number' => $s['serial_number']],
                ['organization_id' => 1, ...$s, 'faktor_cakupan' => 2],
            );
        }

        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $kategori = EquipmentCategory::firstOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Tekanan')],
            ['organization_id' => 1, 'nama' => 'Tekanan', 'kode' => Str::slug('Tekanan')],
        );
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT TEKANAN CONTOH MANDIRI'],
            ['organization_id' => 1, 'alamat' => 'Jl. Contoh Pompa No. 3, Kec. Contoh Timur, Kab. Contoh 40000'],
        );

        // SINTETIS di dalam rentang Druck 13G (0…290 psi). Data contoh master
        // SPMK sebenarnya di dalam rentang, tapi SPMK 700 jatuh tempo
        // 2026-08-05 (`DATABASE!X13`), jadi sesi yang menunggu persetujuan
        // dengan standar itu benar-benar ditahan validator — itu penjaganya
        // bekerja, bukan sesi contoh yang perlu dibela. Data SPMK dipakai di
        // `TekananMasterTest` & `TekananSesiTest`.
        $this->sesi(new PressureGaugeProfile, $teknisi, $kategori, $pelanggan, [
            'serial' => 'DEMO-PG-13G-001',
            'nama_alat' => 'Pressure Gauge',
            'merk' => 'Contoh Keiki',
            'standar' => '5608066',
            'tanggal' => '2026-09-24',
            'lingkungan' => ['suhu_awal' => 24.5, 'suhu_akhir' => 24.7, 'kelembaban_awal' => 51, 'kelembaban_akhir' => 52],
            'blok' => [
                'varian' => Tabel::DRUCK13G, 'satuan' => 'Psi', 'tampilan' => M::TAMPILAN_ANALOG,
                'rasio_jarum' => '1/5', 'resolusi' => 1.0, 'kapasitas' => 200.0,
            ],
            'titik' => [
                [0.0, [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]],
                [50.0, [49.8, 49.9, 49.8], [49.6, 49.7, 49.7]],
                [100.0, [99.7, 99.8, 99.7], [99.5, 99.6, 99.6]],
                [150.0, [149.7, 149.6, 149.7], [149.5, 149.5, 149.6]],
                [200.0, [199.6, 199.7, 199.6], [199.6, 199.5, 199.6]],
            ],
        ]);

        // SINTETIS di dalam rentang Druck 07G (-80…200 kPa); lihat docblock kelas.
        $this->sesi(new VacuumGaugeProfile, $teknisi, $kategori, $pelanggan, [
            'serial' => 'DEMO-VG-07G-001',
            'nama_alat' => 'Vacuum Gauge',
            'merk' => 'Contoh Vakum',
            'standar' => '5568079',
            'tanggal' => '2026-09-24',
            'lingkungan' => ['suhu_awal' => 24.5, 'suhu_akhir' => 24.7, 'kelembaban_awal' => 51, 'kelembaban_akhir' => 52],
            'blok' => [
                'varian' => Tabel::DRUCK07G, 'satuan' => 'kPa', 'tampilan' => M::TAMPILAN_ANALOG,
                'rasio_jarum' => '1/5', 'resolusi' => 1.0, 'kapasitas' => 80.0,
            ],
            'titik' => [
                [0.0, [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]],
                [-20.0, [-20.3, -20.2, -20.3], [-20.1, -20.2, -20.1]],
                [-40.0, [-40.4, -40.3, -40.4], [-40.2, -40.2, -40.3]],
                [-60.0, [-60.5, -60.4, -60.5], [-60.3, -60.3, -60.4]],
                [-80.0, [-79.6, -79.7, -79.6], [-79.8, -79.7, -79.8]],
            ],
        ]);

        // SINTETIS di dalam rentang Additel (±10 mbar); lihat docblock kelas.
        $this->sesi(new DifferentialPressureProfile, $teknisi, $kategori, $pelanggan, [
            'serial' => 'DEMO-DP-ADT-001',
            'nama_alat' => 'Differential Pressure Gauge',
            'merk' => 'Contoh Controls',
            'standar' => '211H18280008',
            'tanggal' => '2026-09-24',
            'lingkungan' => ['suhu_awal' => 24.2, 'suhu_akhir' => 24.6, 'kelembaban_awal' => 55, 'kelembaban_akhir' => 57],
            'blok' => [
                'varian' => Tabel::DIFFERENTIAL, 'satuan' => 'mBar', 'tampilan' => M::TAMPILAN_DIGITAL,
                'rasio_jarum' => null, 'resolusi' => 0.01, 'kapasitas' => 10.0,
            ],
            'titik' => [
                [0.0, [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]],
                [2.0, [2.03, 2.02, 2.03], [2.01, 2.02, 2.01]],
                [4.0, [4.05, 4.04, 4.05], [4.03, 4.03, 4.04]],
                [6.0, [6.06, 6.05, 6.06], [6.04, 6.05, 6.04]],
                [8.0, [8.07, 8.06, 8.07], [8.05, 8.06, 8.05]],
                [10.0, [10.08, 10.07, 10.08], [10.06, 10.07, 10.06]],
            ],
        ]);
    }

    /**
     * @param  array{serial: string, nama_alat: string, merk: string, standar: string, tanggal: string,
     *               lingkungan: array<string, float|int>, blok: array<string, mixed>,
     *               titik: list<array{0: float, 1: list<float>, 2: list<float>}>}  $d
     */
    private function sesi(
        TekananProfile $profil,
        User $teknisi,
        EquipmentCategory $kategori,
        Customer $pelanggan,
        array $d,
    ): void {
        $standar = Standard::where('organization_id', 1)->where('serial_number', $d['standar'])->firstOrFail();
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();
        $satuan = (string) $d['blok']['satuan'];

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $d['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $d['nama_alat'],
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $d['merk'],
                'range_min' => min(0.0, ...array_map(static fn (array $t): float => $t[0], $d['titik'])),
                'range_max' => (float) $d['blok']['kapasitas'],
                'satuan' => $satuan,
                'resolusi' => (float) $d['blok']['resolusi'],
                'toleransi' => null,
                'lokasi' => 'Lab. Tekanan PT. SIDIK',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $d['serial']],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $d['tanggal'],
                'tanggal_terima' => $d['tanggal'],
                'lokasi' => 'lab',
                ...$d['lingkungan'],
                'alat_merk' => $d['merk'],
                'alat_serial_number' => $d['serial'],
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => $d['blok']],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($d['titik'] as $i => [$setelan, $up, $down]) {
            $titikKe = $i + 1;

            foreach ([M::PERAN_UP => $up, M::PERAN_DOWN => $down] as $peran => $deret) {
                foreach ($deret as $urutan => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $urutan + 1,
                        'sensor_ke' => $urutan + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $setelan,
                        'pembacaan' => $nilai,
                        'satuan' => $satuan,
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $setelan,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    M::KONTEKS_UP => $up,
                    M::KONTEKS_DOWN => $down,
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    ...$d['lingkungan'],
                ],
            ];
        }

        $hasil = $profil->hitungPerGrup($siapHitung, $alat);

        if (($hasil['belum_dihitung'] ?? []) !== []) {
            throw new \RuntimeException(sprintf(
                'Sesi contoh %s tidak bisa dihitung: %s',
                $d['serial'],
                $hasil['belum_dihitung'][0]['alasan'],
            ));
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
