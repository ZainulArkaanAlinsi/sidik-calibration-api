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
use App\Services\Calibration\Profiles\TimbanganProfile;
use App\Support\TimbanganMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tiga sesi contoh alat ke-21 (**Timbangan**, kelompok Massa), dari tiga
 * workbook master ber-password yang turun dari lab 31 Agt 2026:
 *
 *  - **011-CAL-525** — Timbangan Bestar 100 kg / 0,02 kg, pembebanan langsung
 *    (`New_Master_Olda_Timbangan_kg.xlsm`, PT Trimandiri Plasindo, 2 Mei 2025).
 *  - **019-CAL-425** — Moisture Analyzer Mettler Toledo HB53 54 g / 0,0001 g,
 *    pembebanan langsung, timbangan ANALYTICAL
 *    (`New_Master_Olda_Timbangan_gram.xlsm`, PT Kaldu Sari Nabati, 4 Apr 2025).
 *  - **0136-CAL-123** — Timbangan Elektronik Dini Argeo 2000 kg / 0,1 kg,
 *    metode beban SUBSTITUSI
 *    (`TERBARU_Master_Olda_Timbangan_Subtitusi_291025.xlsm`, PT Sidik, 12 Jan 2023).
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Sama seperti dua belas seeder sesi lain, ketidakpastiannya beneran lewat
 * jalur produksi (`TimbanganProfile::hitungPerGrup()`), bukan angka jadi yang
 * disalin dari Excel. Kalau rumusnya berubah, data demo ini ikut berubah dan
 * `TimbanganMasterTest` yang mencocokkannya ke master langsung merah.
 *
 * ## Satu berkas data, dua pembaca
 *
 * Pembacaan mentahnya dibaca dari `database/data/sesi-master-timbangan.json`
 * — berkas yang SAMA yang dipakai `TimbanganMasterTest` buat mengadu angka.
 * Dipisah jadi dua salinan, sesi demo dan angka acuan bisa berbeda diam-diam,
 * dan yang merah bukan angkanya melainkan tidak ada.
 *
 * ## Lima blok tingkat-sesi masuk `spesifikasi_alat`
 *
 * Keterulangan, eksentrisitas, histeresis, dan metode pembebanan tidak punya
 * `titik_ke`, jadi tidak bisa tinggal di `raw_measurements` tanpa melahirkan
 * titik hantu di jalur hitung ulang. Alasan lengkapnya di
 * `App\Support\TimbanganMentah`.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh 17 baris CMC
 * Timbangan dari lampiran akreditasi) dan `ThermohygroSeeder`.
 */
class TimbanganSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Ketujuh anak timbangan standar lab, dari `DATABASE!R25:V31` master.
     *
     * Nama & nominalnya WAJIB cocok dengan `TimbanganProfile::STANDARD_TERCETAK`,
     * kalau tidak kotak "Usage Check" di lembar kerja tidak ketemu standarnya
     * dan pulang `terdaftar: false` — kegagalan yang tidak bersuara.
     *
     * `ketidakpastian` NULL untuk ketujuhnya: ketidakpastiannya beda per KEPING
     * (26 baris F1 + 15 baris E2), dan tabelnya hidup di
     * `tabel-standar-timbangan.json`. Satu skalar di kolom ini bakal jadi angka
     * kedua yang mengaku mewakili hal yang sama.
     */
    private const STANDAR = [
        ['nama' => 'Anak Timbangan E2', 'merk' => '-', 'model' => 'E2', 'serial' => '2434',
            'tertelusur' => 'LK-023-IDN', 'berlaku_sampai' => '2027-08-21'],
        ['nama' => 'Anak Timbangan F1', 'merk' => 'Excellent', 'model' => 'F1', 'serial' => '113106',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-09-18'],
        ['nama' => 'Anak Timbangan F21', 'merk' => 'Sonic', 'model' => 'F2', 'serial' => 'A 3659',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-09-18'],
        ['nama' => 'Anak Timbangan F22', 'merk' => 'Sonic', 'model' => 'F2', 'serial' => 'A 3660',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-09-18'],
        ['nama' => 'Anak Timbangan F25', 'merk' => 'Sonic', 'model' => 'F2', 'serial' => 'A 3661',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-09-18'],
        ['nama' => 'Anak Timbangan M2', 'merk' => 'RC', 'model' => 'M2', 'serial' => 'A-J',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-09-18'],
        ['nama' => 'Anak Timbangan F1-10', 'merk' => 'Excellent', 'model' => 'F1', 'serial' => '4321',
            'tertelusur' => 'LK-279-IDN', 'berlaku_sampai' => '2026-10-21'],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $this->seedStandar();

        $utama = Standard::where('organization_id', 1)
            ->where('nama', 'Anak Timbangan M2')
            ->firstOrFail();

        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-timbangan.json')),
            true,
        );

        foreach (['kg', 'gram', 'sub'] as $varian) {
            $this->seedSesi($data[$varian], $teknisi, $utama, $thermohygro);
        }
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
                    'ketidakpastian' => null,
                    'satuan_ketidakpastian' => 'g',
                    'faktor_cakupan' => 2,
                ],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function seedSesi(array $data, User $teknisi, Standard $standar, ?Standard $thermohygro): void
    {
        $m = $data['_sesi'];
        $satuan = (string) $data['satuan'];

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Massa')],
            ['organization_id' => 1, 'nama' => 'Massa'],
        );

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS seperti
                // di lampiran akreditasi no. 12, kurungnya ikut. Kalau meleset,
                // alatnya jatuh ke profil default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => (new TimbanganProfile)->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                'range_min' => 0.0,
                'range_max' => (float) $data['kapasitas'],
                'satuan' => $satuan,
                'resolusi' => (float) $data['resolusi'],
                // NULL, bukan 0: batas keberterimaan Timbangan datang dari MPE
                // kelas (SNSU PK.M-02:2021), bukan dari kolom ini —
                // `punyaToleransi()` = false.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $m['nomor_sesi']],
            [
                'equipment_id' => $alat->id,
                'nomor_order' => $m['nomor_order'],
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $m['tanggal'],
                'tanggal_terima' => $m['tanggal'],
                'lokasi' => $m['lokasi'],
                'lokasi_nama' => $m['lokasi_nama'],
                'suhu_awal' => $m['suhu_awal'],
                'suhu_akhir' => $m['suhu_akhir'],
                'kelembaban_awal' => $m['rh_awal'],
                'kelembaban_akhir' => $m['rh_akhir'],
                'alat_merk' => $m['merk'],
                'alat_model' => $m['model'],
                'alat_serial_number' => $m['serial'],
                'pemilik_nama' => $m['pelanggan'],
                'pemilik_alamat' => $m['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $data['kapasitas'],
                    'kapasitas' => (string) $data['kapasitas'],
                    'resolusi' => (string) $data['resolusi'],
                    // Empat kunci yang MENENTUKAN hitungan, dan yang tanpanya
                    // hitung ulang memakai varian & tabel yang salah tanpa error.
                    'varian_master' => $data['varian'],
                    'tipe_display' => $data['tipe_display'],
                    'tipe_timbangan' => $data['tipe_timbangan'],
                    'satuan' => $satuan,
                    // Blok tingkat-sesi — lihat docblock kelas.
                    'keterulangan' => $data['keterulangan'],
                    'eksentrisitas' => $data['eksentrisitas'],
                    'histeresis' => $data['histeresis'],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($data['akurasi'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $nominal = array_map('floatval', $titik['nominal']);
            $titikUkur = array_sum($nominal);

            foreach ($nominal as $slot => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $slot + 1,
                    'sensor_ke' => $slot + 1,
                    'peran_sensor' => TimbanganMentah::PERAN_NOMINAL,
                    'titik_ukur' => $titikUkur,
                    'pembacaan' => $nilai,
                    'satuan' => $satuan,
                    'standard_id' => $standar->id,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            foreach (TimbanganMentah::PERAN_PEMBACAAN as $peran) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => 1,
                    'peran_sensor' => $peran,
                    'titik_ukur' => $titikUkur,
                    'pembacaan' => (float) $titik['baca'][$peran],
                    'satuan' => $satuan,
                    'standard_id' => $standar->id,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $titikUkur,
                'pembacaan' => [],
                'standard' => $standar,
                'standard_id' => $standar->id,
                'satuan' => $satuan,
                'suhu' => null,
                'konteks' => [
                    'nominal' => $nominal,
                    'z1' => (float) $titik['baca']['z1'],
                    'm' => (float) $titik['baca']['m'],
                    'm_aksen' => (float) $titik['baca']['m_aksen'],
                    'z2' => (float) $titik['baca']['z2'],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                ],
            ];
        }

        $hasil = (new TimbanganProfile)->hitungPerGrup($siapHitung, $alat);
        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($hasil['hitungan'] ?? [] as $hitungan) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$hitungan,
                'formula_version_id' => $versiRumus,
            ]);
        }
    }
}
