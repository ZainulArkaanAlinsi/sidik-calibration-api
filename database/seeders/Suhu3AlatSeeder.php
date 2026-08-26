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
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\Profiles\ThermohygroProfile;
use App\Services\Calibration\Profiles\ThermometerGlassProfile;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data tiga alat suhu yang mendarat bareng (alat ke-18…20), dari tiga master:
 *
 *  - **0513-CAL-1124** — Thermocouple, `Master_Olah_Data_Suhu_Thermocouple.xlsm`
 *    (order 2411.50.I, PT Kaldu Sari Nabati Indonesia, 3 Des 2024, 3 titik).
 *  - **0135-CAL-125** — Termometer Gelas,
 *    `Master_Olah_Data_Suhu_Thermometer_Glass.xlsm` (order 2501.16.G,
 *    PT Unilever Indonesia Tbk Skin Care Factory, 31 Jan 2025, 5 titik).
 *  - **0312-CAL-624** — Thermohygrometer,
 *    `Master_Olah_Data_Suhu__Kelembapan.xlsm` (order 2406.25.AR,
 *    PT Gunung Madu Plantations, 2 Jul 2024, 5 titik suhu + 5 titik RH).
 *
 * Sama seperti sebelas seeder sesi lain, ketidakpastiannya BENERAN dihitung
 * lewat jalur produksi (`…Profile::hitungPerGrup()`), bukan angka jadi yang
 * ditempel — kalau rumusnya berubah, data demo ini ikut berubah dan
 * `Suhu3AlatMasterTest` yang mencocokkannya ke master langsung merah.
 *
 * ## Kolom `merk` standar bukan hiasan
 *
 * Ketiga profil membaca tabel koreksi lewat `standards.merk` (`constant` /
 * `yokogawa`). Salah tulis di kolom itu berarti sesi jatuh ke tabel kalibrator
 * yang salah — atau tidak kehitung sama sekali. Nama & serialnya juga harus
 * cocok dengan `…Profile::STANDARD_TERCETAK`, kalau tidak kotak "Usage Check"
 * di lembar kerja tidak ketemu standarnya dan pulang `terdaftar: false`.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh baris CMC
 * Thermocouple / Termometer Gelas / Thermohygrometer dari lampiran akreditasi)
 * dan `ThermohygroSeeder`.
 */
class Suhu3AlatSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Probe & meter standar yang belum dibuat seeder lain.
     *
     * Dua kalibrator suhu (Constant & Yokogawa) SENGAJA tidak diulang di sini —
     * `TitsSeeder` sudah membuatnya dengan `merk` yang benar, dan membuat baris
     * kedua dengan nama yang sama akan membuat `tautkanStandar()` memilih salah
     * satunya secara sewenang-wenang.
     *
     * `ketidakpastian` NULL untuk ketiga probe: ketidakpastiannya beda per titik
     * DAN per probe, dan tabelnya hidup di `tabel-master-suhu-3alat.json`. Satu
     * skalar di kolom ini akan jadi angka kedua yang mengaku mewakili hal yang
     * sama.
     */
    private const STANDAR = [
        [
            'nama' => 'PRT Pt-100', 'merk' => null, 'model' => 'PT100', 'serial' => 'SH1/20',
            'tertelusur' => 'SNSU-BSN', 'berlaku_sampai' => '2027-02-14', 'ketidakpastian' => null,
        ],
        [
            'nama' => 'Thermocouple Type K', 'merk' => null, 'model' => 'Type K', 'serial' => 'TC-01,02',
            'tertelusur' => 'LK-064-IDN', 'berlaku_sampai' => '2026-09-06', 'ketidakpastian' => null,
        ],
        [
            'nama' => 'Thermocouple Type N', 'merk' => null, 'model' => 'Type N', 'serial' => 'TCN-06,11',
            'tertelusur' => 'LK-064-IDN', 'berlaku_sampai' => '2026-09-06', 'ketidakpastian' => null,
        ],
        [
            // Standar Thermohygrometer — SATU meter, dan cuma satu
            // (`DATABASE!Q14:Y14`). U95-nya memang satu angka per parameter, tapi
            // dua parameter; disimpan di tabel master, bukan di kolom ini.
            'nama' => 'Temperature Humidity Meter', 'merk' => null, 'model' => '-',
            'serial' => '201701023483', 'tertelusur' => 'LK-361-IDN',
            'berlaku_sampai' => '2027-01-09', 'ketidakpastian' => null,
        ],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $this->seedStandar();

        $yokogawa = Standard::where('organization_id', 1)->where('merk', 'Yokogawa')->firstOrFail();
        $thMeter = Standard::where('organization_id', 1)->where('nama', 'Temperature Humidity Meter')->firstOrFail();
        $th4 = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();

        $this->seedThermocouple($teknisi, $yokogawa, $th4);
        $this->seedTermometerGelas($teknisi, $yokogawa, $th4);
        $this->seedThermohygro($teknisi, $thMeter, $th4);
    }

    private function seedStandar(): void
    {
        foreach (self::STANDAR as $s) {
            Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'],
                    'model' => $s['model'],
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => $s['tertelusur'],
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => '°C',
                    'faktor_cakupan' => 2,
                    'koefisien_suhu' => null,
                ],
            );
        }
    }

    /** Sesi 0513-CAL-1124 — `INPUT DATA` baris 34–36 (standar) & 51–53 (UUT). */
    private function seedThermocouple(User $teknisi, Standard $standar, ?Standard $thermohygro): void
    {
        $titik = [
            ['titik' => 50.0, 'no_probe' => 1, 'standar' => [49.5, 49.5, 49.5, 49.5, 49.5], 'uut' => [49.9, 49.9, 49.9, 49.9, 49.9]],
            ['titik' => 100.0, 'no_probe' => 2, 'standar' => [99.0, 99.0, 99.0, 99.0, 99.0], 'uut' => [99.9, 99.9, 99.9, 99.9, 99.9]],
            ['titik' => 150.0, 'no_probe' => 3, 'standar' => [148.6, 148.6, 148.6, 148.6, 148.6], 'uut' => [150.1, 150.1, 150.1, 150.1, 150.1]],
        ];

        $sesi = $this->bikinSesi(
            customer: ['nama' => 'PT. Kaldu Sari Nabati Indonesia', 'alamat' => 'Jl. Raya Cirebon-Bandung KM. 24 Kel. Banjaran Kec. Sumber Jaya Kab. Majalengka 45468'],
            alat: [
                'nama_alat' => 'Thermocouple Thermometer', 'nama_alat_kemampuan' => 'Thermocouple',
                'merk' => 'Hanna Instrument', 'model' => 'HI93530', 'serial_number' => 'J0037794',
                'range_min' => 50.0, 'range_max' => 150.0, 'kapasitas' => '200', 'resolusi' => 0.1,
                'satuan' => ThermocoupleProfile::SATUAN,
            ],
            sesi: [
                'nomor_sesi' => '0513-CAL-1124', 'nomor_order' => '2411.50.I', 'tanggal' => '2024-12-03',
                // `INPUT DATA` E21:F22 — dipakai `KondisiLingkungan` buat
                // menurunkan suhu_ruang & U95-nya, persis alur teknisi.
                'suhu_awal' => 24.5, 'suhu_akhir' => 24.6, 'kelembaban_awal' => 61, 'kelembaban_akhir' => 62,
                'tipe_sensor' => 'Type K',
                'alat_bantu' => 'A',
            ],
            standar: $standar,
            teknisi: $teknisi,
            thermohygro: $thermohygro,
        );

        $this->isiPasangan($sesi, $titik, $standar, new ThermocoupleProfile, 'suhu', ThermocoupleProfile::SATUAN);
    }

    /** Sesi 0135-CAL-125 — `INPUT DATA` baris 33–37 (standar) & 50–54 (UUT). */
    private function seedTermometerGelas(User $teknisi, Standard $standar, ?Standard $thermohygro): void
    {
        $titik = [
            ['titik' => 30.0, 'no_probe' => 17, 'standar' => [29.9, 30.3, 30.3, 30.3, 30.3], 'uut' => [30, 30, 30, 30, 30]],
            ['titik' => 50.0, 'no_probe' => 17, 'standar' => [51.5, 51.6, 51.5, 51.5, 51.5], 'uut' => [51, 51, 51, 51, 51]],
            ['titik' => 60.0, 'no_probe' => 17, 'standar' => [61.7, 61.8, 61.7, 61.7, 61.7], 'uut' => [61, 61, 61, 61, 61]],
            ['titik' => 80.0, 'no_probe' => 17, 'standar' => [81.9, 81.8, 81.9, 81.9, 81.9], 'uut' => [82, 82, 82, 82, 82]],
            ['titik' => 100.0, 'no_probe' => 17, 'standar' => [99.2, 99.3, 99.3, 99.3, 99.3], 'uut' => [99, 99, 99, 99, 99]],
        ];

        $sesi = $this->bikinSesi(
            customer: ['nama' => 'PT. Unilever Indonesia Tbk (Skin Care Factory)', 'alamat' => 'Jl. Jababeka V Blok U No.14-16, Karangbaru, Kec. Cikarang Utara, Bekasi, Jawa Barat 17538'],
            alat: [
                'nama_alat' => 'Thermometer Glass', 'nama_alat_kemampuan' => 'Termometer Gelas',
                'merk' => 'Alla France', 'model' => 'Analog', 'serial_number' => 'IND-140',
                'range_min' => 0.0, 'range_max' => 100.0, 'kapasitas' => '100',
                // Skala terkecil termometer gelas, bukan daya baca digital —
                // dia masuk budget lewat `N25 = K16/2`.
                'resolusi' => 1.0,
                'satuan' => ThermometerGlassProfile::SATUAN,
            ],
            sesi: [
                'nomor_sesi' => '0135-CAL-125', 'nomor_order' => '2501.16.G', 'tanggal' => '2025-01-31',
                'suhu_awal' => 24.3, 'suhu_akhir' => 24.5, 'kelembaban_awal' => 54, 'kelembaban_akhir' => 55,
                'tipe_sensor' => ThermometerGlassProfile::TIPE_SENSOR_STANDAR,
                'alat_bantu' => 'dua',
                'tipe_pencelupan' => 'Total Immersion',
                // `INPUT DATA!N33:Q33` — ketiganya 0,0 di sesi ini, jadi
                // komponen budget titik es-nya nol. Disimpan apa adanya, bukan
                // dikosongkan: nol yang tercatat beda arti dari kolom yang
                // belum pernah diisi.
                'titik_es' => [0.0, 0.0, 0.0],
            ],
            standar: $standar,
            teknisi: $teknisi,
            thermohygro: $thermohygro,
        );

        $this->isiPasangan($sesi, $titik, $standar, new ThermometerGlassProfile, 'suhu', ThermometerGlassProfile::SATUAN);
    }

    /** Sesi 0312-CAL-624 — dua parameter, `INPUT DATA` baris 36–40, 53–57, 73–75, 90–92 & blok GEA. */
    private function seedThermohygro(User $teknisi, Standard $standar, ?Standard $thermohygro): void
    {
        $titik = [
            ['titik' => 15.0, 'parameter' => 'suhu', 'standar' => [14.96, 14.95, 14.99, 14.95, 14.97], 'uut' => [14.2, 14.2, 14.3, 14.5, 14.6]],
            ['titik' => 25.0, 'parameter' => 'suhu', 'standar' => [24.86, 24.85, 24.88, 24.85, 24.87], 'uut' => [24.2, 24.2, 24.5, 24.5, 24.6]],
            ['titik' => 35.0, 'parameter' => 'suhu', 'standar' => [34.94, 34.96, 34.93, 34.96, 34.92], 'uut' => [34.6, 34.6, 34.3, 34.5, 34.7]],
            ['titik' => 45.0, 'parameter' => 'suhu', 'standar' => [44.83, 44.85, 44.81, 44.85, 44.82], 'uut' => [44.5, 44.5, 44.3, 44.5, 44.6]],
            ['titik' => 50.0, 'parameter' => 'suhu', 'standar' => [54.91, 54.95, 54.99, 54.94, 54.97], 'uut' => [54.6, 54.6, 54.3, 54.5, 54.6]],
            ['titik' => 50.0, 'parameter' => 'kelembaban', 'standar' => [49.4, 49.5, 49.42, 49.43, 49.4], 'uut' => [49, 49, 49, 49, 49]],
            ['titik' => 70.0, 'parameter' => 'kelembaban', 'standar' => [69.4, 69.5, 69.42, 69.43, 69.4], 'uut' => [69, 69, 69, 69, 69]],
            ['titik' => 90.0, 'parameter' => 'kelembaban', 'standar' => [89.4, 89.5, 89.42, 89.43, 89.4], 'uut' => [89, 89, 89, 89, 89]],
            ['titik' => 30.0, 'parameter' => 'kelembaban', 'standar' => [29.49, 29.45, 29.32, 29.41, 29.46], 'uut' => [29, 29, 29, 29, 29]],
            ['titik' => 49.0, 'parameter' => 'kelembaban', 'standar' => [48.49, 48.45, 48.32, 48.41, 48.46], 'uut' => [48, 48, 48, 48, 48]],
        ];

        $sesi = $this->bikinSesi(
            customer: ['nama' => 'PT. Gunung Madu Plantations', 'alamat' => 'KM 90 Terbanggi Besar Gunung Batin udik, Terusan Nunyai, Lampung Tengah'],
            alat: [
                'nama_alat' => 'Thermohygrometer', 'nama_alat_kemampuan' => 'Thermohygrometer',
                'merk' => 'NOKLEAD', 'model' => 'NK5253', 'serial_number' => 'TR-001',
                'range_min' => 15.0, 'range_max' => 50.0, 'kapasitas' => '-10 - 60', 'resolusi' => 0.1,
                'satuan' => ThermohygroProfile::SATUAN_SUHU,
            ],
            sesi: [
                'nomor_sesi' => '0312-CAL-624', 'nomor_order' => '2406.25.AR', 'tanggal' => '2024-07-02',
                'suhu_awal' => 25.6, 'suhu_akhir' => 25.5, 'kelembaban_awal' => 53, 'kelembaban_akhir' => 54,
                'spesifikasi_tambahan' => [
                    'rentang_ukur_kelembaban' => '30-90',
                    'kapasitas_kelembaban' => '10 - 95',
                    'resolusi_kelembaban' => '1',
                ],
            ],
            standar: $standar,
            teknisi: $teknisi,
            thermohygro: $thermohygro,
        );

        $this->isiPasangan($sesi, $titik, $standar, new ThermohygroProfile, 'campur', ThermohygroProfile::SATUAN_SUHU);
    }

    /**
     * @param  array{nama: string, alamat: string}  $customer
     * @param  array<string, mixed>  $alat
     * @param  array<string, mixed>  $sesi
     */
    private function bikinSesi(
        array $customer,
        array $alat,
        array $sesi,
        Standard $standar,
        User $teknisi,
        ?Standard $thermohygro,
    ): CalibrationSession {
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
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS seperti
                // di lampiran akreditasi, kalau nggak alatnya jatuh ke profil
                // default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => $alat['nama_alat_kemampuan'],
                'merk' => $alat['merk'],
                'model' => $alat['model'],
                'range_min' => $alat['range_min'],
                'range_max' => $alat['range_max'],
                'satuan' => $alat['satuan'],
                'resolusi' => $alat['resolusi'],
                // NULL, bukan 0: ketiga master nggak punya batas keberterimaan
                // sama sekali — punyaToleransi() = false.
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
                'tipe_sensor' => $sesi['tipe_sensor'] ?? null,
                'alat_bantu' => $sesi['alat_bantu'] ?? null,
                'tipe_pencelupan' => $sesi['tipe_pencelupan'] ?? null,
                'titik_es' => $sesi['titik_es'] ?? null,
                'alat_merk' => $alat['merk'],
                'alat_model' => $alat['model'],
                'alat_serial_number' => $alat['serial_number'],
                'pemilik_nama' => $customer['nama'],
                'pemilik_alamat' => $customer['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $alat['range_max'],
                    'kapasitas' => $alat['kapasitas'],
                    'resolusi' => (string) $alat['resolusi'],
                    ...($sesi['spesifikasi_tambahan'] ?? []),
                ],
                // suhu_ruang & U95-nya JANGAN ditulis — biar `KondisiLingkungan`
                // yang menghitungnya dari awal/akhir + koreksi sertifikat TH-4,
                // persis seperti alur teknisi.
                'suhu_awal' => $sesi['suhu_awal'],
                'suhu_akhir' => $sesi['suhu_akhir'],
                'kelembaban_awal' => $sesi['kelembaban_awal'],
                'kelembaban_akhir' => $sesi['kelembaban_akhir'],
            ],
        );

        app(KondisiLingkungan::class)->terapkan($baris);

        return $baris;
    }

    /**
     * Tulis dua deret pembacaan per titik, lalu hitung lewat jalur produksi.
     *
     * @param  list<array<string, mixed>>  $titik
     */
    private function isiPasangan(
        CalibrationSession $sesi,
        array $titik,
        Standard $standar,
        CalibrationProfile $profil,
        string $besaran,
        string $satuanDefault,
    ): void {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $versiRumus = $this->versiRumusUntuk($sesi);
        $equipment = $sesi->loadMissing('equipment')->equipment;
        $siapHitung = [];

        foreach ($titik as $index => $t) {
            $titikKe = $index + 1;
            $parameter = (string) ($t['parameter'] ?? ThermohygroCalculator::PARAMETER_SUHU);
            $satuan = $besaran === 'campur' && $parameter === ThermohygroCalculator::PARAMETER_KELEMBABAN
                ? ThermohygroProfile::SATUAN_RH
                : $satuanDefault;

            foreach (['standar', 'uut'] as $peran) {
                foreach ($t[$peran] as $i => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $i + 1,
                        // Nomor probe cuma menempel ke sisi STANDAR — sisi UUT
                        // memakai probe bawaan alat pelanggan.
                        'sensor_ke' => $peran === 'standar' ? ($t['no_probe'] ?? null) : null,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $t['titik'],
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
                'titik_ukur' => (float) $t['titik'],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'standar' => $t['standar'],
                    'uut' => $t['uut'],
                    'no_probe' => $t['no_probe'] ?? null,
                    'parameter' => $parameter,
                    'tipe_sensor' => $sesi->tipe_sensor,
                    'alat_bantu' => $sesi->alat_bantu,
                    'titik_es' => $sesi->titik_es ?? [],
                ],
            ];
        }

        $hasil = $profil->hitungPerGrup($siapHitung, $equipment);

        foreach ($hasil['hitungan'] ?? [] as $baris) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$baris,
            ]);
        }

        // Ketiganya nggak divonis (punyaToleransi=false) — keputusan sesi null.
        $sesi->update(['keputusan' => null]);
    }
}
