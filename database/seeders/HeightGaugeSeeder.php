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
use App\Services\Calibration\Profiles\HeightGaugeProfile;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Services\KondisiLingkungan;
use App\Support\HeightGaugeMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Height Gauge** — alat ke-26, kelompok Panjang.
 *
 * Sumbernya `Master_olda_Height_Gauge_600_mm_2026.xlsm` (password `spirit285`),
 * sesi `001-UBLK-05.26`. Masukannya digenerate
 * `docs/skrip/gen-sesi-height-gauge.py` ke
 * `database/data/sesi-master-height-gauge.json`, bukan diketik.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN — paralelisme, blok Evaluation, sepuluh titik,
 * suhu. Hasilnya lahir dari `HeightGaugeProfile::hitungPerGrup()` lewat jalur
 * hitung ulang yang sama dengan sesi sungguhan, jadi kalau mesin hitungnya
 * bergeser `HitungUlangSemuaSesiTest` yang merah — bukan angka tempelan yang
 * diam-diam ikut bergeser.
 *
 * ## U95 sesi ini SENGAJA beda dari yang tercetak di master
 *
 * Master menghitung komponen drift dari `NOW()` (`DATABASE!X11`), yang di
 * snapshot kami 2026-06-11 — 153,66 hari sesudah sertifikat Caliper Checker.
 * Sesinya sendiri dikalibrasi 2026-05-05, yaitu **116 hari**. Kami memakai
 * tanggal sesi supaya angkanya bisa diulang, jadi yang terbit di sini
 * **0,0156260 mm** sementara masternya mencetak 0,0156680 mm. Selisih 0,27 %,
 * dan seluruhnya berasal dari tanggal — bukan dari pengukuran.
 *
 * Angka master yang asli tetap dijaga: `HeightGaugeMasterTest` mengoper
 * `NOW()`-nya master apa adanya dan mengadu kesepuluh koreksi plus kelima
 * agregatnya sampai 5·10⁻⁶.
 *
 * ## Sesinya terbit DI LUAR lingkup akreditasi, dan itu ditanam apa adanya
 *
 * Height Gauge tidak ada di lampiran LK-285-IDN, jadi sesi ini memunculkan
 * peringatan `height_gauge_diluar_akreditasi` di `kalibrasi:sapu-sesi`. Itu
 * temuan yang BENAR, bukan artefak data demo — dan dibiarkan muncul justru
 * supaya admin melihat bentuknya sebelum sesi pelanggan sungguhan datang.
 */
class HeightGaugeSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Caliper Checker, dari `Std_CaliperCek!C2:O6` dan `DATABASE!S5:Z5`.
     *
     * `ketidakpastian` diisi 4,1 µm — dan di sini dia BOLEH satu angka, beda
     * dari Micrometer yang harus null. Sertifikat Caliper Checker menulis
     * `U95 = 4,1 µm` untuk KESEPULUH baris di kedua tabelnya, jadi tidak ada
     * tangga per nominal yang bisa dipilih salah.
     *
     * Yang membacanya buat budget tetap `TabelStandarHeightGauge`, bukan kolom
     * ini — kolom ini yang tercetak di blok "Standard used" sertifikat.
     */
    private const STANDAR = [
        'nama' => 'Caliper Checker',
        'merk' => 'Metrology',
        'model' => 'CMG-9060C',
        'serial' => '800035',
        'tertelusur' => 'LK-404-IDN',
        // `DATABASE!Z5` — tanggal kalibrasi 2026-01-09 + interval 2 tahun.
        'berlaku_sampai' => '2028-01-09',
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $standar = $this->seedStandar();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-height-gauge.json')),
            true,
        );

        $m = $data['_sesi'];

        // `TH-1` — `INPUT DATA!E23 = 1` yang menunjuk baris pertama
        // `DATABASE!B31`. Bukan TH-3 seperti Micrometer; disalin dari masternya
        // sendiri, karena unit thermohygro menentukan koreksi suhu & kelembapan
        // yang tercetak di blok Environmental Condition sertifikat.
        $thermohygro = Standard::where('organization_id', 1)
            ->where('nama', $m['thermohygro'])
            ->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        // **Panjang** — kelompok lampiran akreditasi yang sudah ada, bukan
        // kategori baru. Alat ini memang belum diakreditasi, tapi kelompoknya
        // ada; kategori sendiri melahirkan kartu kategori hantu di HP. Dijaga
        // `KategoriAlatIkutLampiranTest`.
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Panjang')],
            ['organization_id' => 1, 'nama' => 'Panjang'],
        );

        $profil = new HeightGaugeProfile;

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS sama
                // dengan `namaAlatKemampuan()`. Kalau meleset, alatnya jatuh ke
                // profil default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                // Rentang alat SELALU mm, apa pun skala alatnya — kolom ini
                // bukan tempat satuan teknisi hidup. Pembacaan teknisi mengambil
                // jalan yang BERBEDA: disimpan mentah berikut
                // `raw_measurements.satuan`, lalu diubah ke mm di tempat pakai
                // (`HeightGaugeMentah::keMm()`).
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas_mm'],
                'satuan' => 'mm',
                'resolusi' => (float) $m['resolusi_mm'],
                // NULL: masternya nggak nyebut satu pun batas keberterimaan per
                // titik, jadi sesi ini nggak divonis PASS/FAIL. Satu-satunya
                // kriteria di lembar itu paralelisme, dan itu vonis tingkat
                // SESI yang dicetak terpisah.
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
                    // Blok tingkat-SESI. Paralelisme, blok Evaluation,
                    // kapasitas, resolusi, dan kerataan muka ukur bukan titik
                    // ukur — memaksanya jadi `titik_ke` melahirkan titik hantu
                    // yang selalu gagal hitung ulang.
                    HeightGaugeMentah::KUNCI_SESI => [
                        // `mm` — angka master semuanya milimeter, dan
                        // `INPUT DATA!Y14` masternya pun `mm`. Kalau suatu saat
                        // ada varian `inch`, yang disalin harus tetap satuan
                        // ANGKA yang ditulis di sini, bukan label dropdown-nya:
                        // `HeightGaugeMentah::blokSesi()` mengalikan pra-evaluasi
                        // dengan faktor satuan ini.
                        'satuan' => 'mm',
                        'kapasitas_mm' => (float) $m['kapasitas_mm'],
                        'resolusi_mm' => (float) $m['resolusi_mm'],
                        // SELALU mm — dibaca pada Dial Indicator standar, bukan
                        // pada Height Gauge-nya.
                        'paralelisme' => array_map('floatval', $data['paralelisme_mm']),
                        'pra_evaluasi' => array_map('floatval', $data['pra_evaluasi_mm']),
                        'kerataan_muka_ukur' => $m['kerataan_muka_ukur'],
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        // Nominal datang dari TABEL STANDAR, bukan dari sesi master — jalur
        // yang sama persis dengan `susunBlokHeightGauge()`. Angkanya memang sama
        // (dua-duanya lahir dari workbook yang sama), dan memakainya dari satu
        // sumber yang sama itu yang menjaga keduanya tidak menyimpang diam-diam.
        $nominalCetak = (new TabelStandarHeightGauge)->titikPraCetak();
        $suhuRata = HeightGaugeMentah::rataSuhuRuang($m['suhu_awal'], $m['suhu_akhir']);

        $siapHitung = [];

        foreach ($data['titik'] as $i => $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $nominal = $nominalCetak[$i] ?? null;

            if ($nominal === null) {
                continue;
            }

            $deret = [
                HeightGaugeMentah::PERAN_NOMINAL => [$nominal],
                HeightGaugeMentah::PERAN_PEMBACAAN => array_map('floatval', $titik['pembacaan_mm']),
            ];

            foreach ($deret as $peran => $nilai) {
                foreach ($nilai as $ke => $angka) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        // Urutan slot / nomor ulangan — `HeightGaugeMentah`
                        // mengurut ulang lewat kolom ini.
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $nominal,
                        // Angka master semuanya MILIMETER.
                        'pembacaan' => $angka,
                        'satuan' => 'mm',
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        // Diketik tangan, bukan kamera. Tanpa penanda ini
                        // validator melaporkan `ocr_belum_diverifikasi` di sesi
                        // contoh, dan temuan palsu di data demo melatih admin
                        // mengabaikan temuan yang sama waktu dia nyata.
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $nominal,
                // Jalur datar TIDAK dipakai alat ini — lihat
                // `CalibrationController::susunBlokHeightGauge()`.
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    HeightGaugeMentah::PERAN_NOMINAL => $deret[HeightGaugeMentah::PERAN_NOMINAL],
                    HeightGaugeMentah::PERAN_PEMBACAAN => $deret[HeightGaugeMentah::PERAN_PEMBACAAN],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    'suhu_ruang_rata' => $suhuRata,
                ],
            ];
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
                // Dipanjangkan ke masa berlaku demo kalau yang asli sudah lewat.
                // Tanpa ini `kalibrasi:sapu-sesi` melaporkan
                // `standar_kadaluarsa` di sesi contoh — temuan yang benar untuk
                // data demo, tapi melatih pembacanya mengabaikan temuan yang
                // sama waktu dia nyata.
                'berlaku_sampai' => $this->berlakuSampaiDemo(
                    self::STANDAR['nama'],
                    self::STANDAR['berlaku_sampai'],
                ),
                // 4,1 µm untuk kesepuluh nominal — lihat docblock [STANDAR].
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

        // Suhu & kelembapan RUANG diturunkan di sini, bukan ditulis tangan —
        // jalur yang sama persis dengan `POST /calibrations`. Dilupakan, sesi
        // contoh ini terbit tanpa `suhu_ruang` & `kelembaban`, blok
        // "Environmental Condition" sertifikatnya kosong, dan validator
        // memunculkan `env_condition` di setiap sesi.
        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
