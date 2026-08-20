<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\AutoclaveCalculator;
use App\Services\Calibration\AutoclaveInputBuilder;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Autoklaf dari `Master Olah Data_Autoclave.xlsm` — satu autoklaf
 * pelanggan, empat kalibrator Tecnosoft, dan satu sesi kalibrasi end-to-end.
 *
 * ## Kenapa seeder ini lahir belakangan
 *
 * Autoklaf sempat jadi satu-satunya dari sembilan alat yang NGGAK punya data
 * demo sama sekali: nggak ada baris `equipments` ber-`nama_alat_kemampuan`
 * 'Autoklaf', jadi di layar teknisi alatnya nggak bisa dipilih dan seluruh
 * fitur yang udah jadi itu nggak keliatan pernah jalan. Ketahuan waktu audit
 * alur sembilan alat, 20 Agu 2026.
 *
 * ## Bedanya sama delapan seeder sesi yang lain
 *
 * Alat lain nyimpen hasil per titik di `uncertainty_calculations` lewat
 * `GumCalculator`. Autoklaf NGGAK: olah datanya lahir di `AutoclaveCalculator`
 * dan dibekukan utuh di kolom `hasil_autoclave`. Jadi seeder ini manggil
 * `AutoclaveInputBuilder` + `AutoclaveCalculator` — jalur yang sama persis
 * dengan `CalibrationController::simpanAutoclave()`, bukan angka jadi yang
 * ditempel. Kalau rumusnya berubah, data demo ini ikut berubah.
 *
 * Konsekuensinya `hasil_autoclave` juga bawa `lembar` — data mentah yang
 * ditulis teknisi — karena tanpa itu baris kertas (Indikator Pressure, Tekanan
 * atm awal, jam tiap kolom) hilang begitu sesi tersimpan.
 *
 * ## Kalibrator: nama & serialnya WAJIB sama dengan config
 *
 * Tabel koreksi kalibratornya nggak di DB, tapi di `config/autoclave.php`.
 * Baris `standards` di sini cuma buat KETERTELUSURAN — yang kecetak di
 * "STANDARD USED" sertifikat. Sesi Autoklaf nggak punya
 * `uncertainty_calculations`, jadi satu-satunya jalan standarnya nyampe
 * sertifikat itu lewat "Usage Check" (`calibration_session_standard`) —
 * makanya keempatnya di-attach dengan `dipakai = true`.
 *
 * Nama & serialnya dicocokin ke DUA tempat sekaligus: `config/autoclave.php`
 * dan `AutoclaveProfile::STANDARD_TERCETAK` (yang nyocokin lewat `cocok`).
 * Beda satu karakter = kotak centang di lembar kerja nggak ketemu standarnya.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh baris CMC Autoklaf
 * Suhu & Tekanan dari lampiran akreditasi) dan `ThermohygroSeeder`.
 */
class AutoclaveSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;

    /**
     * Tiga Temperature Calibrator + satu Pressure Disk Logger.
     *
     * `ketidakpastian` = U95 sertifikat kalibratornya (kolom `u95` di
     * `config/autoclave.php`, seragam di semua set point), k=2.
     *
     * @var list<array{nama: string, merk: string, model: string, serial: string, ketidakpastian: float, satuan: string, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Temperature Calibrator 1',
            'merk' => 'Tecnosoft',
            'model' => 'SterilDisk',
            'serial' => '1011001961',
            'ketidakpastian' => 0.43,
            'satuan' => '°C',
            'berlaku_sampai' => '2025-11-14',
        ],
        [
            'nama' => 'Temperature Calibrator 2',
            'merk' => 'Tecnosoft',
            'model' => 'TS01SD',
            'serial' => '1011004038',
            'ketidakpastian' => 0.44,
            'satuan' => '°C',
            'berlaku_sampai' => '2025-11-14',
        ],
        [
            'nama' => 'Temperature Calibrator 3',
            'merk' => 'Tecnosoft',
            'model' => 'TS01SD',
            'serial' => '1011004063',
            'ketidakpastian' => 0.44,
            'satuan' => '°C',
            'berlaku_sampai' => '2025-11-14',
        ],
        [
            'nama' => 'Pressure Disk Logger',
            'merk' => 'Tecnosoft',
            'model' => 'PressureDisk',
            'serial' => '3501009550',
            'ketidakpastian' => 0.0046,
            'satuan' => 'Bar',
            'berlaku_sampai' => '2025-11-14',
        ],
    ];

    /**
     * Data ukur satu siklus sterilisasi 121 °C, bentuknya persis kayak yang
     * dikirim `POST /calibrations/autoclave`.
     *
     * Angkanya dari master; `keseragaman` yang keluar 0,464 — BUKAN 0,466 kayak
     * master — dan itu disengaja: sel disk di master kegeser. Lihat docblock
     * `AutoclaveCalculator`, jangan "dibenerin" balik tanpa arahan pemilik repo.
     *
     * `pembacaan_standar` dalam BAR (hasil unduh Pressure Disk Logger),
     * sementara `uut_setting` ngikut satuan display alatnya (MPa). 0,112 MPa =
     * 1,12 Bar — dan itulah kenapa dua baris ini angkanya keliatan beda jauh.
     *
     * @return array<string, mixed>
     */
    private function dataUkur(): array
    {
        return [
            'set_point' => 121.0,
            'waktu' => ['09:00', '09:05', '09:10', '09:15', '09:20'],
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
                'resolusi_alat' => 0.01,
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'resolusi_alat' => 0.001,
                'indikator_pressure' => [0.112, 0.112, 0.112, 0.112, 0.112],
                'tekanan_atm_awal' => [0.0, 0.0, 0.0, 0.0, 0.0],
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ],
        ];
    }

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'LPKL PDAM TIRTAWENING'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Atlas No. 6 Antapani Kota Bandung',
            ],
        );

        $standar = [];
        foreach (self::STANDAR as $s) {
            $standar[] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'],
                    'model' => $s['model'],
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => 'SNSU-BSN',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => $s['satuan'],
                    'faktor_cakupan' => 2,
                    // Kalibrator suhu & tekanan nggak punya kurva suhu larutan —
                    // AutoclaveProfile::standarBerkurvaSuhu() nggak dipakai di
                    // jalur ini, koreksinya dari tabel set point di config.
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'AC-2119-0847'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Autoclave',
                'nama_alat_kemampuan' => 'Autoklaf',
                'merk' => 'Hirayama',
                'model' => 'HVE-50',
                'range_min' => 105,
                'range_max' => 135,
                'satuan' => '°C',
                'resolusi' => 0.1,
                // NULL, bukan 0: Autoklaf nggak divonis PASS/FAIL
                // (`AutoclaveProfile::punyaToleransi()` = false), jadi dia nggak
                // punya batas keberterimaan. 0 di kolom ini kebaca sebagai
                // "toleransinya nol °C" — pernyataan salah di jejak audit.
                'toleransi' => null,
                'lokasi' => 'Insitu',
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

        // Autoklaf dikerjain di tempat pelanggan, jadi thermohygro-nya yang
        // grup Insitu. TH-2 sama kayak sesi DO Meter.
        $th2 = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $dataUkur = $this->dataUkur();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2406.51.S'],
            [
                'equipment_id' => $equipment->id,
                'nomor_order' => '2406.51.S.NK',
                'teknisi_id' => $teknisi->id,
                'thermohygro_standard_id' => $th2?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-06-18',
                'tanggal_terima' => '2024-06-18',
                'lokasi' => 'onsite',
                'lokasi_nama' => 'LPKL PDAM Tirtawening',
                'alat_merk' => 'Hirayama',
                'alat_model' => 'HVE-50',
                'alat_serial_number' => 'AC-2119-0847',
                'pemilik_nama' => $customer->nama,
                'pemilik_alamat' => $customer->alamat,
                // Jam mulai & selesai dari baris `Time` di lembar kerjanya.
                'waktu_awal' => '09:00',
                'waktu_akhir' => '09:20',
                // Awal & akhir apa adanya; `suhu_ruang` & U95-nya JANGAN
                // ditulis — biar `KondisiLingkungan` yang ngitung dari
                // awal/akhir + koreksi sertifikat TH-2, persis kayak alur
                // teknisi beneran.
                'suhu_awal' => 25.0,
                'suhu_akhir' => 25.4,
                'kelembaban_awal' => 55,
                'kelembaban_akhir' => 56,
                // Autoklaf nggak divonis PASS/FAIL.
                'keputusan' => null,
                // Olah datanya lewat jalur yang sama dengan controller —
                // `lembar` ikut supaya baris kertas yang nggak ikut ngitung
                // (Indikator Pressure, Tekanan atm awal, jam) nggak hilang.
                'hasil_autoclave' => [
                    ...app(AutoclaveCalculator::class)->hitung(
                        app(AutoclaveInputBuilder::class)->dari($dataUkur),
                    ),
                    'lembar' => $dataUkur,
                ],
            ],
        );

        app(KondisiLingkungan::class)->terapkan($sesi);

        // "Usage Check" — satu-satunya jalan kalibrator Autoklaf nyampe tabel
        // STANDARD USED di sertifikat (lihat docblock kelas).
        $sesi->standarDicek()->sync(
            collect($standar)
                ->mapWithKeys(fn (Standard $s): array => [
                    $s->id => ['dipakai' => true, 'keterangan' => null],
                ])
                ->all(),
        );
    }
}
