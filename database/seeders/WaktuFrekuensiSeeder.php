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
use App\Services\Calibration\Profiles\CentrifugeProfile;
use App\Services\Calibration\Profiles\TachometerProfile;
use App\Services\Calibration\Profiles\TimerStopwatchProfile;
use App\Services\KondisiLingkungan;
use App\Support\WaktuMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tiga sesi contoh kelompok **Waktu dan Frekuensi**, disalin dari workbook
 * master ber-password yang turun 16 Apr 2026.
 *
 * Angkanya **DIHITUNG**, bukan ditempel: pembacaan mentahnya disalin dari
 * sheet `PERHITUNGAN` masing-masing workbook, lalu `hitungPerGrup()` yang
 * mengisi `uncertainty_calculations`. Menempel hasilnya berarti seeder ini
 * tetap hijau walau mesin hitungnya rusak — dan itu justru kelas kegagalan yang
 * paling mahal di repo ini.
 *
 * ## Lima titik hantu master TIDAK ikut di-seed
 *
 * Sheet Timer masternya punya sepuluh blok set point, lima di antaranya kosong
 * seluruhnya — dan sel kosong yang dibaca nol tetap melahirkan `CORRECTION =
 * 30 ms` yang tercetak seperti titik sungguhan. Kelimanya dibuang di generator
 * (`docs/skrip/gen-sesi-waktu-frekuensi.py`), bukan di sini, supaya berkas
 * datanya sendiri sudah bersih.
 *
 * Yang menjaga bahwa kalkulatornya TETAP memblokir titik semacam itu kalau toh
 * masuk: `WaktuFrekuensiMasterTest::test_titik_hantu_timer_diblokir`.
 */
class WaktuFrekuensiSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Kedua keping standar lab. Tachometer & Centrifuge berbagi yang PERTAMA —
     * di kedua workbook master sheet `SERTIFIKAT KALIBRATOR`-nya identik baris
     * demi baris, dan nomor serinya sama.
     */
    private const STANDAR = [
        [
            'nama' => 'Infrared Tachometer NK-300',
            'merk' => 'NKTECH', 'model' => 'NK-300', 'serial' => '1186.01.23-1',
            'tertelusur' => 'LK-305-IDN', 'berlaku_sampai' => '2026-07-04',
            'satuan_u' => 'rpm',
        ],
        [
            'nama' => 'Stopwatch SW-1',
            'merk' => 'CASIO', 'model' => 'Digital', 'serial' => 'SW-1',
            'tertelusur' => 'LK-361-IDN', 'berlaku_sampai' => '2026-12-15',
            'satuan_u' => 's',
        ],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $this->seedStandar();

        $tacho = Standard::where('organization_id', 1)
            ->where('nama', 'Infrared Tachometer NK-300')->firstOrFail();
        $stopwatch = Standard::where('organization_id', 1)
            ->where('nama', 'Stopwatch SW-1')->firstOrFail();
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-waktu-frekuensi.json')),
            true,
        );

        $this->seedRpm($data['tachometer'], new TachometerProfile, $teknisi, $tacho, $thermohygro);
        $this->seedRpm($data['centrifuge'], new CentrifugeProfile, $teknisi, $tacho, $thermohygro);
        $this->seedWaktu($data['timer'], $teknisi, $stopwatch, $thermohygro);
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
                    // NULL, bukan angka: ketidakpastian kedua keping ini
                    // berbentuk PITA per nominal (`see certificate` di sheet
                    // DATABASE masternya), dan yang membacanya
                    // `TabelStandarPutaran`/`TabelStandarWaktu` — bukan kolom
                    // ini. Diisi satu angka, budget-nya diam-diam memakai pita
                    // yang salah di sebagian besar titik.
                    'ketidakpastian' => null,
                    'satuan_ketidakpastian' => $s['satuan_u'],
                    'faktor_cakupan' => 2,
                ],
            );
        }
    }

    /**
     * Sesi rpm — satu titik = satu set point + lima pembacaan tachometer standar.
     *
     * @param  array<string, mixed>  $data
     */
    private function seedRpm(
        array $data,
        CalibrationProfile $profil,
        User $teknisi,
        Standard $standar,
        ?Standard $thermohygro,
    ): void {
        $m = $data['_sesi'];
        $sesi = $this->seedSesi($m, $profil, $teknisi, $standar, $thermohygro, 'Waktu dan Frekuensi', 'rpm');
        $alat = $sesi->equipment;

        $siapHitung = [];

        foreach ($data['titik'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $setPoint = (float) $titik['set_point'];
            $pembacaan = array_map('floatval', $titik['pembacaan']);

            foreach ($pembacaan as $ke => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $ke + 1,
                    // `peran_sensor` SENGAJA kosong. Bentuk titik kelompok
                    // Putaran memang deret datar, dan kosakata baru apa pun di
                    // kolom ini menjatuhkan tiap titik ke cabang enclosure di
                    // `HitungUlangSesi` — perintahnya "sukses" tanpa menghitung
                    // apa pun. Lihat docblock `ProfilPutaran`.
                    'peran_sensor' => null,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $setPoint,
                    'pembacaan' => $nilai,
                    'satuan' => 'rpm',
                    'standard_id' => $standar->id,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $setPoint,
                'pembacaan' => $pembacaan,
                'standard' => $standar,
                'standard_id' => $standar->id,
                'satuan' => 'rpm',
                'suhu' => null,
                'konteks' => [],
            ];
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    /**
     * Sesi Timer/Stopwatch — satu titik = DUA deret waktu dalam milidetik.
     *
     * @param  array<string, mixed>  $data
     */
    private function seedWaktu(array $data, User $teknisi, Standard $standar, ?Standard $thermohygro): void
    {
        $profil = new TimerStopwatchProfile;
        $m = $data['_sesi'];
        $sesi = $this->seedSesi($m, $profil, $teknisi, $standar, $thermohygro, 'Waktu dan Frekuensi', 's');
        $alat = $sesi->equipment;

        $siapHitung = [];

        foreach ($data['titik'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $setPoint = (float) $titik['set_point_detik'];

            $deret = [
                WaktuMentah::PERAN_STANDAR => array_map('floatval', $titik['standar_ms']),
                WaktuMentah::PERAN_UUT => array_map('floatval', $titik['uut_ms']),
            ];

            foreach ($deret as $peran => $nilai) {
                foreach ($nilai as $ke => $ms) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        // Nomor ULANGAN — `WaktuMentah` memasangkan standar
                        // ke-i dengan UUT ke-i lewat kolom ini.
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $setPoint,
                        'pembacaan' => $ms,
                        'satuan' => WaktuMentah::SATUAN,
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $setPoint,
                'pembacaan' => [],
                'standard' => $standar,
                'standard_id' => $standar->id,
                'satuan' => 's',
                'suhu' => null,
                'konteks' => $deret,
            ];
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    /**
     * Faktor pengali dari satuan spesifikasi master ke satuan HASIL alatnya.
     *
     * Cuma tiga satuan yang muncul di ketiga workbook (`Rpm`, `min`, `s`);
     * yang tidak dikenal dipulangkan 1,0 supaya angka masternya tidak digeser
     * oleh tebakan.
     */
    private static function keSatuanHasil(string $satuanMaster): float
    {
        return match (mb_strtolower(trim($satuanMaster))) {
            'min', 'menit' => 60.0,
            'jam', 'hour' => 3600.0,
            default => 1.0,
        };
    }

    /**
     * Pelanggan + kategori + alat + sesi, bagian yang sama untuk ketiganya.
     *
     * @param  array<string, mixed>  $m
     */
    private function seedSesi(
        array $m,
        CalibrationProfile $profil,
        User $teknisi,
        Standard $standar,
        ?Standard $thermohygro,
        string $namaKategori,
        string $satuan,
    ): CalibrationSession {
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug($namaKategori)],
            ['organization_id' => 1, 'nama' => $namaKategori],
        );

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS seperti
                // di lampiran akreditasi. Kalau meleset, alatnya jatuh ke profil
                // default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                'range_min' => 0.0,
                // `kapasitas` master bersatuan `$m['satuan']` — `min` buat
                // Stopwatch, `Rpm` buat dua alat putaran — sementara
                // `equipments.satuan` menyimpan satuan HASIL: detik, karena
                // tabel sertifikatnya detik. Disalin apa adanya, "60 menit"
                // jadi "0–60 detik", dan seluruh lembar (set point 300 sampai
                // 1800 detik) mendarat di luar rentang alatnya sendiri.
                'range_max' => (float) $m['kapasitas'] * self::keSatuanHasil((string) $m['satuan']),
                'satuan' => $satuan,
                'resolusi' => (float) $m['resolusi'],
                // NULL: kelompok ini nggak divonis PASS/FAIL — lampiran maupun
                // ketiga master nggak menyebut satu pun batas keberterimaan.
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
                'lokasi' => 'onsite',
                'lokasi_nama' => $m['lokasi_nama'],
                'suhu_awal' => $m['suhu_awal'],
                'suhu_akhir' => $m['suhu_akhir'],
                'kelembaban_awal' => $m['rh_awal'],
                'kelembaban_akhir' => $m['rh_akhir'],
                'alat_merk' => $m['merk'],
                'alat_model' => $m['model'],
                'alat_serial_number' => (string) $m['serial'],
                'pemilik_nama' => $m['pelanggan'],
                'pemilik_alamat' => $m['alamat'],
                // Blok spesifikasi TERCETAK di sertifikat, jadi ditulis dalam
                // satuan MASTERNYA sendiri (`60 min`) — bukan dikonversi. Yang
                // dikonversi cuma `equipments.range_max` di atas, karena itu
                // yang diadu ke pembacaan oleh pemeriksa.
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas'],
                    'resolusi' => (string) $m['resolusi'],
                    'satuan' => (string) $m['satuan'],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        return $sesi->refresh();
    }

    /**
     * @param  array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>}|null  $perGrup
     */
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

        // Suhu & kelembapan RUANG diturunkan di sini, bukan ditulis tangan —
        // jalur yang sama persis dengan `POST /calibrations` (lihat
        // `CalibrationController`), dan pola yang sama dengan tiga seeder lain
        // (Viscometer, Suhu3Alat, Autoclave).
        //
        // Dilupakan, ketiga sesi contoh ini terbit tanpa `suhu_ruang` &
        // `kelembaban`: blok "Environmental Condition" sertifikatnya kosong,
        // dan validator memunculkan `env_condition` di setiap sesi — padahal
        // ketiga master mencatat angkanya (21,3/21,5 °C & 53/56 %RH untuk
        // Timer). Data contoh yang memunculkan temuan palsu melatih pembacanya
        // mengabaikan temuan.
        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
