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
use App\Services\Calibration\Profiles\LoadCellProfile;
use App\Support\GayaMentah as M;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh Load Cell — angkanya DARI master, bukan dikarang.
 *
 * Sumbernya sesi `085-CAL-124` di `Gaya_Load_Cell/`: alat 100 kN resolusi
 * 0,01 kN, arah tarik, standar Load Cell 100 kN, ruangan 27,7 °C.
 *
 * ## Dua hal yang kelihatan aneh di data ini, dan dua-duanya memang begitu
 *
 * **Urutan titiknya 0, 100, lalu 2..9 kN.** Bukan salah ketik — begitu urutan
 * di lembar masternya, dan sertifikatnya pun mencetak dalam urutan itu. Titik
 * kedua langsung kapasitas penuh, baru turun ke rentang bawah.
 *
 * **Seluruh titik di LUAR rentang tabel standarnya.** Tabel kalibrasi load cell
 * 100 kN mulai di 9,8 kN dan berhenti di 88,3 kN; titik 2..9 kN ada di
 * bawahnya, titik 100 kN di atasnya. Akibatnya koreksi standar `W` konstan
 * `-0,00196133` untuk tujuh titik sekaligus — bukan kebetulan, tapi karena
 * nearest-match selalu jatuh ke baris pertama tabel.
 *
 * Master diam soal itu. Sistem ini menandai tiap titiknya sebagai ekstrapolasi
 * (G10), jadi sesi contoh ini memang membawa peringatan — dan itu benar, bukan
 * gangguan yang perlu dimatikan.
 */
class LoadCellSeeder extends Seeder
{
    private const SERIAL = 'DEMO-LC-001';

    private const LINGKUNGAN = [
        'suhu_awal' => 27.7, 'suhu_akhir' => 27.7,
        'kelembaban_awal' => 70, 'kelembaban_akhir' => 70,
    ];

    /**
     * Sepuluh titik, tiap titik 12 bacaan (4 posisi x 3 replikat).
     *
     * Polanya sembilan bacaan bernilai `a` dan tiga bernilai `b` — satu posisi
     * penuh membaca beda. Itu yang membuat STDEV-nya bukan nol dan yang dipakai
     * test rekonsiliasi.
     *
     * @return list<array{titik_ukur: float, a: float, b: float}>
     */
    private function titik(): array
    {
        return [
            ['titik_ukur' => 0.0, 'a' => 0.0, 'b' => 0.0],
            ['titik_ukur' => 100.0, 'a' => 100.0, 'b' => 100.0],
            ['titik_ukur' => 2.0, 'a' => 2.16, 'b' => 2.14],
            ['titik_ukur' => 3.0, 'a' => 3.48, 'b' => 3.45],
            ['titik_ukur' => 4.0, 'a' => 4.06, 'b' => 4.06],
            ['titik_ukur' => 5.0, 'a' => 5.02, 'b' => 5.03],
            ['titik_ukur' => 6.0, 'a' => 5.95, 'b' => 5.96],
            ['titik_ukur' => 7.0, 'a' => 6.86, 'b' => 6.88],
            ['titik_ukur' => 8.0, 'a' => 7.88, 'b' => 7.85],
            ['titik_ukur' => 9.0, 'a' => 8.91, 'b' => 8.92],
        ];
    }

    /**
     * Sembilan bacaan `a` + tiga bacaan `b`, dibagi ke empat posisi.
     *
     * @return array<string, list<float>>
     */
    private function posisi(float $a, float $b): array
    {
        return [
            'gaya_pos_0' => [$a, $a, $a],
            'gaya_pos_90' => [$a, $a, $a],
            'gaya_pos_180' => [$a, $a, $a],
            'gaya_pos_270' => [$b, $b, $b],
        ];
    }

    public function run(): void
    {
        $profil = new LoadCellProfile;
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
            ['organization_id' => 1, 'nama' => 'PT BAJA CONTOH SEJAHTERA'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Contoh Baja No. 5, Kec. Contoh Utara, Kab. Contoh 16000',
            ],
        );

        $standar = Standard::where('organization_id', 1)
            ->where('serial_number', 'J10CC13283')
            ->firstOrFail();

        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => self::SERIAL],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Load Cell',
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Contoh Instrument',
                'range_min' => 0,
                'range_max' => 100,
                'satuan' => 'kN',
                'resolusi' => 0.01,
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
                'alat_merk' => 'Contoh Instrument',
                'alat_serial_number' => self::SERIAL,
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => [
                    'satuan' => 'kN',
                    'tipe_beban' => M::ARAH_PULL,
                    'standar' => '100kN',
                    'suhu_sertifikat_standar' => 23.45,
                    'kapasitas' => 100.0,
                    'resolusi_uut' => 0.01,
                    'resolusi_standar' => 0.001,
                    'kapasitas_standar' => 100.0,
                    'preload_zero' => [0.0, 0.0, 0.0],
                    'preload_max' => [68.86, 70.89, 71.86],
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

            foreach ($this->posisi($t['a'], $t['b']) as $peran => $deret) {
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
                        'satuan' => 'kN',
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

        foreach ($profil->hitungPerGrup($siapHitung, $alat)['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
            ]);
        }
    }
}
