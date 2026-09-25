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
use App\Services\Calibration\Profiles\ProvingRingProfile;
use App\Support\GayaMentah as M;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh Proving Ring — angkanya DARI master, bukan dikarang.
 *
 * Sumbernya sesi `0410-CAL-324`: cincin Mitutoyo analog 500 kgf, dial 25 mm
 * beresolusi 0,002 mm, arah tekan, ruangan 23,1 °C.
 *
 * ## Tiga hal yang kelihatan aneh di data ini, dan ketiganya memang begitu
 *
 * **Pembacaannya ratusan sampai ribuan.** Itu jumlah DIVISI dial, bukan kgf.
 * Titik 500 kgf terbaca 4.045 divisi; dibaca sebagai gaya, angkanya delapan
 * kali kapasitas alatnya sendiri.
 *
 * **Standarnya Load Cell 3000 kN** untuk alat berkapasitas 4,905 kN — 600 kali
 * lebih besar, dan titik terkalibrasi terendah tabelnya 300 kN. Akibatnya
 * koreksi standar setiap titik diambil dari baris nol, dan itu **benar secara
 * hitungan** sekaligus tidak tertelusur. Master diam soal ini; sistem menandai
 * tiap titiknya. Pertanyaan lab G11 & G14 — dan sampai lab menjawab, sesi
 * contoh ini memang membawa peringatan, dan itu benar.
 *
 * **Urutan titiknya 0, 30, 90, 120, 150, 210, 240, 350, 450, 500 kgf.** Tidak
 * berjarak seragam, dan itu urutan masternya.
 */
class ProvingRingSeeder extends Seeder
{
    private const SERIAL = 'DEMO-PR-001';

    private const LINGKUNGAN = [
        'suhu_awal' => 23.1, 'suhu_akhir' => 23.1,
        'kelembaban_awal' => 57, 'kelembaban_akhir' => 57,
    ];

    /**
     * Sepuluh titik; tiap titik enam bacaan dial — UP tiga kali, DOWN tiga kali.
     *
     * Urutannya persis kolom `C..I` masternya: tiga pertama UP, tiga berikutnya
     * DOWN.
     *
     * @return list<array{titik_ukur: float, up: list<float>, down: list<float>}>
     */
    private function titik(): array
    {
        return [
            ['titik_ukur' => 0.0, 'up' => [0, 0, 0], 'down' => [0, 0, 0]],
            ['titik_ukur' => 30.0, 'up' => [237, 237, 238], 'down' => [238, 237, 238]],
            ['titik_ukur' => 90.0, 'up' => [726, 726, 725], 'down' => [725, 725, 724]],
            ['titik_ukur' => 120.0, 'up' => [934, 935, 935], 'down' => [936, 936, 937]],
            ['titik_ukur' => 150.0, 'up' => [1205, 1205, 1206], 'down' => [1205, 1206, 1208]],
            ['titik_ukur' => 210.0, 'up' => [1698, 1697, 1697], 'down' => [1696, 1697, 1696]],
            ['titik_ukur' => 240.0, 'up' => [1941, 1942, 1943], 'down' => [1942, 1943, 1944]],
            ['titik_ukur' => 350.0, 'up' => [2832, 2833, 2832], 'down' => [2833, 2833, 2834]],
            ['titik_ukur' => 450.0, 'up' => [3642, 3641, 3644], 'down' => [3643, 3644, 3644]],
            ['titik_ukur' => 500.0, 'up' => [4047, 4045, 4045], 'down' => [4046, 4048, 4045]],
        ];
    }

    public function run(): void
    {
        $profil = new ProvingRingProfile;
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();

        $kategori = EquipmentCategory::firstOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Gaya')],
            ['organization_id' => 1, 'nama' => 'Gaya', 'kode' => Str::slug('Gaya')],
        );

        // Pelanggan sendiri — seeder tidak menumpang milik seeder lain. Waktu
        // `UtmSeeder` sempat memakai pelanggan milik `ViscometerSeeder`,
        // alamatnya ketimpa dan sertifikat Viscometer kehilangan 2 px ruang
        // sisa tanpa satu pun error.
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT KONSTRUKSI CONTOH MANDIRI'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Contoh Beton No. 7, Kec. Contoh Selatan, Kab. Contoh 17000',
            ],
        );

        // Standar 3000 kN diseed `UtmSeeder`, jadi seeder ini WAJIB sesudahnya.
        $standar = Standard::where('organization_id', 1)
            ->where('serial_number', 'C-140-BZ/0008')
            ->firstOrFail();

        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => self::SERIAL],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Proving Ring',
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Contoh Instrument',
                'range_min' => 0,
                'range_max' => 500,
                'satuan' => 'kgf',
                // Resolusi ALAT dalam satuan gaya: 500 kgf / (25 mm / 0,002 mm)
                // = 0,04 kgf per divisi. Yang dibaca teknisi tetap divisi;
                // angka ini yang dipakai layar & pembulatan bawaan.
                'resolusi' => 0.04,
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
                // SEBELUM 10 Sep 2026, dan itu bukan angka acak: sertifikat
                // `Load Cell 3000 kN` berlaku sampai tanggal itu. Sesi yang
                // ditanggali sesudahnya ditolak validator dengan benar —
                // standar kedaluwarsa tidak boleh menerbitkan sertifikat.
                //
                // Yang dipilih memundurkan TANGGAL SESI, bukan memperpanjang
                // masa berlaku standarnya: yang kedua berarti mengarang tanggal
                // kalibrasi untuk keping yang nyata, dan angka itu ikut
                // tercetak di kolom ketertelusuran sertifikat.
                'tanggal_kalibrasi' => '2026-09-05',
                'tanggal_terima' => '2026-09-04',
                'lokasi' => 'lab',
                ...self::LINGKUNGAN,
                'alat_merk' => 'Contoh Instrument',
                'alat_serial_number' => self::SERIAL,
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => [
                    'satuan' => 'kgf',
                    'tipe_beban' => M::ARAH_PUSH,
                    'standar' => '3000kN',
                    'suhu_sertifikat_standar' => 28.6,
                    'kapasitas' => 500.0,
                    // Dua angka DIAL — komponen `daya baca alat` di budget lahir
                    // dari sini, bukan dari resolusi gaya.
                    'kapasitas_dial_mm' => 25.0,
                    'resolusi_dial_mm' => 0.002,
                    'resolusi_standar' => 0.1,
                    'kapasitas_standar' => 3000.0,
                    'preload_zero' => [0.0, 0.0, 0.0],
                    'preload_max' => [219.0, 219.0, 219.0],
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

            foreach ([M::PERAN_UP => $t['up'], M::PERAN_DOWN => $t['down']] as $peran => $deret) {
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
                        // DIVISI, bukan kgf. Ditulis terang di baris mentahnya
                        // supaya yang membacanya tidak menyangka gaya.
                        'satuan' => 'Div',
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);

                    $semuaBacaan[] = (float) $nilai;
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

        foreach ($profil->hitungPerGrup($siapHitung, $alat)['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
            ]);
        }
    }
}
