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
use App\Services\Calibration\Profiles\UtmProfile;
use App\Support\GayaMentah as M;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh Mesin UTM — angkanya DARI master, bukan dikarang.
 *
 * Sumbernya sesi `0169-CAL-324` di `Gaya_UTM/` (PERHITUNGAN FC & INPUT DATA):
 * mesin Hung Ta 1300, rentang 500 kgf, arah tarik, standar Load Cell 5 kN.
 * Enam titik beban x 12 pembacaan (empat posisi x tiga replikat), plus preload
 * dan empat pengukuran misalignment.
 *
 * ## Datanya SERAGAM, dan itu perlu diketahui sebelum dipakai menguji
 *
 * Dua dari enam titik (100 dan 500 kgf) punya 12 pembacaan identik sampai digit
 * terakhir; tiga lainnya cuma punya dua nilai unik. Mesin uji nyata tidak pernah
 * berperilaku begitu — load cell punya noise elektronik, mesin punya gesekan
 * mekanis. Ini jelas template isian, bukan rekaman pengukuran.
 *
 * Konsekuensinya: sesi ini membuktikan RUMUSNYA benar, tapi tidak pernah
 * menyentuh cabang "STDEV kecil tapi bukan nol" maupun "MAX/MIN di antara banyak
 * nilai berbeda". Yang menutup itu `GayaCalculatorTest` blok B dengan data
 * sintetis lapangan. Keduanya wajib; yang satu saja meninggalkan test hijau yang
 * jebol di sesi nyata pertama.
 */
class UtmSeeder extends Seeder
{
    private const SERIAL = 'DEMO-UTM-001';

    /** Suhu ruangan awal/akhir jadi suhu load cell standar saat kalibrasi. */
    private const LINGKUNGAN = [
        'suhu_awal' => 24.5, 'suhu_akhir' => 24.3,
        'kelembaban_awal' => 55, 'kelembaban_akhir' => 54,
    ];

    /**
     * Enam titik beban. Tiap posisi tiga replikat; yang ditulis di sini persis
     * urutan sel master, termasuk satu-dua nilai yang menyimpang sendiri.
     *
     * @return list<array{titik_ukur: float, posisi: array<string, list<float>>}>
     */
    private function titik(): array
    {
        $seragam = static fn (float $x): array => [
            'gaya_pos_0' => [$x, $x, $x],
            'gaya_pos_90' => [$x, $x, $x],
            'gaya_pos_180' => [$x, $x, $x],
            'gaya_pos_270' => [$x, $x, $x],
        ];

        return [
            ['titik_ukur' => 0.0, 'posisi' => $seragam(0.0)],
            ['titik_ukur' => 100.0, 'posisi' => $seragam(100.16)],
            ['titik_ukur' => 200.0, 'posisi' => [
                // Satu pembacaan 200,6 di posisi 0° — inilah yang membuat titik
                // ini punya STDEV bukan nol, dan yang dipakai test rekonsiliasi.
                'gaya_pos_0' => [200.538, 200.6, 200.538],
                'gaya_pos_90' => [200.538, 200.538, 200.538],
                'gaya_pos_180' => [200.538, 200.538, 200.538],
                'gaya_pos_270' => [200.538, 200.538, 200.538],
            ]],
            ['titik_ukur' => 300.0, 'posisi' => [
                // DUA pembacaan 300,2 di titik ini, bukan satu — sel ke-4 dan
                // ke-9 deret `C:Q` masternya. Sempat ditulis satu, dan
                // akibatnya rata-ratanya meleset 0,0084 kgf: cukup kecil untuk
                // lolos pandangan mata, cukup besar untuk membuat kolom
                // `Standard Value` sertifikat berbeda dari master di digit
                // yang tidak tercetak. `GayaSesiContohCocokMasterTest` yang
                // menangkapnya.
                'gaya_pos_0' => [300.3006, 300.3006, 300.3006],
                'gaya_pos_90' => [300.2, 300.3006, 300.3006],
                'gaya_pos_180' => [300.3006, 300.3006, 300.2],
                'gaya_pos_270' => [300.3006, 300.3006, 300.3006],
            ]],
            ['titik_ukur' => 400.0, 'posisi' => [
                'gaya_pos_0' => [399.939, 399.9, 399.939],
                'gaya_pos_90' => [399.939, 399.939, 399.939],
                'gaya_pos_180' => [399.939, 399.939, 399.939],
                'gaya_pos_270' => [399.939, 399.939, 399.939],
            ]],
            ['titik_ukur' => 500.0, 'posisi' => $seragam(500.16)],
        ];
    }

    public function run(): void
    {
        $profil = new UtmProfile;
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();

        $kategori = EquipmentCategory::firstOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Gaya')],
            ['organization_id' => 1, 'nama' => 'Gaya', 'kode' => Str::slug('Gaya')],
        );

        // Pelanggan SENDIRI, bukan menumpang milik seeder lain.
        //
        // Versi pertama memakai `PT LOGAM CONTOH CIKARANG` — yang ternyata sudah
        // dipakai `ViscometerSeeder`. `updateOrCreate` menimpa alamatnya jadi
        // lebih panjang, dan sertifikat Viscometer kehilangan 2 px ruang sisa:
        // dari aman jadi 4 px, di bawah ambang 6 px. Satu baris tambahan dan dia
        // jatuh ke halaman dua sementara kop-nya tetap mencetak `Page : 1 of 1`.
        //
        // Nol error muncul dari itu; `SertifikatPunyaMarginTest` yang menangkap.
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT MESIN UJI CONTOH NUSANTARA'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Contoh Industri No. 8, Kec. Contoh Selatan, Kab. Contoh 17000',
            ],
        );

        // Load cell standar 5 kN — identitasnya dari `STANDAR_LOADCELL.csv`
        // master. Dibuat kalau belum ada supaya baris "Standard Used" di lembar
        // punya pasangan terdaftar; tanpa itu lembarnya tetap terbit tapi
        // ketertelusurannya kosong.
        $standar = Standard::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'LC-01-SDK'],
            [
                'organization_id' => 1,
                'nama' => 'Load Cell 5 kN',
                'merk' => 'Usscell/STI-C3-500kg',
                'model' => 'STI-C3-500kg',
                'tertelusur_ke' => 'LK-057-IDN',
                // Master menulis `berlaku_sampai` 13 Jun 2026 — SUDAH LEWAT, dan
                // itu temuan nyata buat lab: standar acuan gaya 5 kN tidak boleh
                // dipakai kalibrasi sampai direkalibrasi. Sesi CONTOH ini tidak
                // ikut memakai tanggal itu, karena sesi contoh yang selalu
                // ber-ERROR "standar kadaluarsa" melatih orang mengabaikan
                // temuan yang justru paling penting dibaca.
                //
                // Diangkat sebagai pertanyaan lab bernomor; tanggal di bawah
                // cuma supaya sesi contohnya bisa dipakai menguji jalur hitung.
                'berlaku_sampai' => '2027-06-13',
                // U95% sertifikat standar, 0,4% reading — komponen pertama
                // budget. Angkanya juga ada di `tabel-standar-gaya.json`; yang
                // di sini supaya baris standarnya berdiri sendiri kalau dibaca
                // dari panel.
                'ketidakpastian' => 0.4,
                'satuan_ketidakpastian' => '% reading',
                'faktor_cakupan' => 2,
            ],
        );

        // Dua standar lain yang IKUT TERCETAK di baris "Standard Used" lembar
        // UTM. Tanpa barisnya di master `standards`, HP menampilkannya merah
        // dengan kotak mati — teknisi mengira kertasnya berubah. Identitasnya
        // dari `STANDAR_LOADCELL.csv`.
        Standard::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'J10CC13283'],
            [
                'organization_id' => 1,
                'nama' => 'Load Cell 100 kN',
                'merk' => 'Uscell',
                'model' => 'ST2-C3-10+',
                'tertelusur_ke' => 'LK-057-IDN',
                'berlaku_sampai' => '2026-12-17',
                'ketidakpastian' => 0.13,
                'satuan_ketidakpastian' => '% reading',
                'faktor_cakupan' => 2,
            ],
        );

        Standard::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'C-140-BZ/0008'],
            [
                'organization_id' => 1,
                'nama' => 'Load Cell 3000 kN',
                'merk' => 'Matest',
                'model' => 'C-140-08',
                'tertelusur_ke' => 'LK-013-IDN',
                'berlaku_sampai' => '2026-09-10',
                'ketidakpastian' => 0.36,
                'satuan_ketidakpastian' => '% reading',
                'faktor_cakupan' => 2,
            ],
        );

        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-3')->first();

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => self::SERIAL],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Universal Testing Machine',
                // Ejaan PERSIS `namaAlatKemampuan()` — meleset satu huruf,
                // alatnya jatuh ke profil generik tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Hung Ta',
                'range_min' => 0,
                'range_max' => 500,
                'satuan' => 'kgf',
                'resolusi' => 0.1,
                'toleransi' => null,
                'lokasi' => 'Lab. Gaya PT. SIDIK',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => self::SERIAL],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2026-09-24',
                'tanggal_terima' => '2026-09-23',
                'lokasi' => 'lab',
                ...self::LINGKUNGAN,
                'alat_merk' => 'Hung Ta',
                'alat_serial_number' => self::SERIAL,
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => [
                    'satuan' => 'kgf',
                    'tipe_beban' => M::ARAH_PULL,
                    'standar' => '5kN',
                    'suhu_sertifikat_standar' => 23.15,
                    'kapasitas' => 500.0,
                    'resolusi_uut' => 0.1,
                    // Resolusi & kapasitas standar dalam kN, dari sertifikat
                    // load cell-nya — bukan dalam satuan mesin yang dikalibrasi.
                    'resolusi_standar' => 0.0000981,
                    'kapasitas_standar' => 5.0,
                    'preload_zero' => [0.0, 0.0, 0.0],
                    'preload_max' => [999.1, 998.9, 999.1],
                    // Empat sisi X1..X4 dari `Misalignment.csv`, apa adanya.
                    'misalignment' => [8.237, 8.234, 8.237, 8.238],
                ]],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($this->titik() as $i => $t) {
            $titikKe = $i + 1;
            $semuaBacaan = [];

            foreach ($t['posisi'] as $peran => $deret) {
                foreach ($deret as $urutan => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $urutan + 1,
                        'sensor_ke' => $urutan + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $t['titik_ukur'],
                        'pembacaan' => $nilai,
                        'satuan' => 'kgf',
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);

                    $semuaBacaan[] = $nilai;
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $t['titik_ukur'],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'bacaan' => $semuaBacaan,
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    ...self::LINGKUNGAN,
                ],
            ];
        }

        // Hasil hitungnya DISIMPAN, bukan dihitung ulang saat dibaca — aturan
        // repo (§Jalur angka). Dan dihitung lewat profilnya sendiri, bukan
        // angka yang diketik: seeder yang menempelkan hasil jadi tidak
        // membuktikan apa pun tentang kodenya.
        foreach ($profil->hitungPerGrup($siapHitung, $alat)['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
            ]);
        }
    }
}
