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
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Refractometer dari `Master Olah Data_Refractometer.xlsm` (sheet INPUT
 * DATA & DATABASE) — empat larutan standar Bellingham+Stanley, alat Hanna
 * HI 96811, dan satu sesi kalibrasi end-to-end yang ketidakpastiannya BENERAN
 * dihitung `GumCalculator` (bukan angka jadi ditempel), sama polanya kayak
 * `ChlorineSeeder`.
 *
 * Pembacaannya disalin apa adanya dari sheet INPUT DATA, DUA tahap sekaligus —
 * beda dari seeder alat lain yang cuma nyeed "sesudah". Alasannya: di
 * refractometer dua blok itu ngasih hasil BEDA walau angka pembacaannya sama
 * persis, karena suhunya beda (repeat ke-5 blok "after" kecatat 35 °C, bukan 25).
 * Rata-rata suhunya jadi 27 °C vs 25 °C, dan itu yang bikin nilai terkoreksinya
 * 1,33935 bukan 1,33845. Nyeed satu tahap doang bakal ngilangin satu-satunya
 * contoh di repo yang mbuktiin koreksi suhunya kepakai.
 *
 * WAJIB jalan SETELAH `RefractometerCapabilitySeeder` (butuh CMC refractometer
 * biar `GumCalculator` masuk jalur budget penuh) dan `ThermohygroSeeder`.
 */
class RefractometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Empat larutan standar dari sheet DATABASE baris 1–4. U95% & tanggalnya
     * asli, bukan placeholder.
     *
     * **Serial-nya sengaja kembar berpasangan** dan itu bukan salah ketik:
     * satu botol fisik dipakai buat dua satuan sekaligus — `BSAG2.5-0034`
     * dibaca sebagai 2,5 °Brix ATAU 1,33659 n20D, `BSAG40-0071` sebagai
     * 40 °Brix ATAU 1,39986 n20D. Yang mbedain cuma satuan yang dipilih
     * teknisi. Makanya `RefractometerProfile::STANDARD_TERCETAK` nyocokin
     * baris lewat NAMA, bukan serial — nyocokin lewat serial bakal ketuker.
     *
     * @var list<array{nama: string, merk: string, serial: string, ketidakpastian: float, satuan: string, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Refractometer Std Solution 1.33659 n20D',
            'merk' => 'Bellingham+Stanley/AG2.5',
            'serial' => 'BSAG2.5-0034',
            'ketidakpastian' => 2.6e-05,
            'satuan' => 'n20D',
            'berlaku_sampai' => '2027-03-18',
        ],
        [
            'nama' => 'Refractometer Std Solution 1.39986 n20D',
            'merk' => 'Bellingham+Stanley/AG40',
            'serial' => 'BSAG40-0071',
            'ketidakpastian' => 3.7e-05,
            'satuan' => 'n20D',
            'berlaku_sampai' => '2026-12-23',
        ],
        [
            'nama' => 'Refractometer Std Solution 2.5 oBrix',
            'merk' => 'Bellingham+Stanley/AG2.5',
            'serial' => 'BSAG2.5-0034',
            'ketidakpastian' => 0.018,
            'satuan' => '°Brix',
            'berlaku_sampai' => '2027-03-18',
        ],
        [
            'nama' => 'Refractometer Std Solution 40 oBrix',
            'merk' => 'Bellingham+Stanley/AG40',
            'serial' => 'BSAG40-0071',
            'ketidakpastian' => 0.019,
            'satuan' => '°Brix',
            'berlaku_sampai' => '2026-12-23',
        ],
    ];

    /**
     * Pembacaan per titik per tahap, dari sheet INPUT DATA baris 35–48.
     *
     * Titik 1 blok "sesudah" repeat ke-5 suhunya **35 °C**, bukan 25 — itu
     * ada di sheet aslinya dan sengaja dipertahankan. Efeknya rata-rata
     * suhunya 27 °C, dan itu yang bikin `PERHITUNGAN` baris 46 nulis
     * Corrected Value 1,33935 (= 1,3362 + 0,00045 × 7).
     *
     * @var list<array{titik: float, standar: int, tahap: array<string, array{pembacaan: list<float>, suhu: list<float>}>}>
     */
    private const TITIK = [
        [
            'titik' => 1.33659,
            'standar' => 0,
            'tahap' => [
                'sebelum_adjustment' => [
                    'pembacaan' => [1.3362, 1.3362, 1.3362, 1.3362, 1.3362],
                    'suhu' => [25, 25, 25, 25, 25],
                ],
                'sesudah_adjustment' => [
                    'pembacaan' => [1.3362, 1.3362, 1.3362, 1.3362, 1.3362],
                    'suhu' => [25, 25, 25, 25, 35],
                ],
            ],
        ],
        [
            'titik' => 1.39986,
            'standar' => 1,
            'tahap' => [
                'sebelum_adjustment' => [
                    'pembacaan' => [1.3986, 1.3986, 1.3986, 1.3986, 1.3986],
                    'suhu' => [25, 25, 25, 25, 25],
                ],
                'sesudah_adjustment' => [
                    'pembacaan' => [1.3986, 1.3986, 1.3986, 1.3986, 1.3986],
                    'suhu' => [25, 25, 25, 25, 25],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Pusat Kesehatan Angkatan Darat Lembaga Biologi Vaksin'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Gudang Selatan No.26, Merdeka, Kec. Sumur Bandung, '
                    .'Kota Bandung, Jawa Barat 40113',
            ],
        );

        $standar = [];
        foreach (self::STANDAR as $i => $s) {
            $standar[$i] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'],
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => 'B+S',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => $s['satuan'],
                    'faktor_cakupan' => 2,
                    // Larutan refractometer dibaca nominal apa adanya. Yang
                    // digeser suhu itu PEMBACAAN alatnya, bukan nilai acuan —
                    // lihat `RefractometerProfile::rataRataPadaSuhuAcuan()`.
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'C12345'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Refractometer',
                'nama_alat_kemampuan' => 'Refractometer',
                'merk' => 'Hanna Instrument',
                'model' => 'HI 96811',
                'range_min' => 0,
                // Sheet INPUT DATA: "Rentang Ukur : 1.7" & "Kapasitas Max. : 1.7
                // n20D". Kelihatan aneh buat n20D yang praktis selalu 1,33–1,40,
                // tapi itu yang ketulis di master — disalin apa adanya.
                'range_max' => 1.7,
                'satuan' => 'n20D',
                'resolusi' => 0.0001,
                // Toleransi alat ini NGGAK ADA di workbook. 0,005 n20D dipilih
                // supaya guarded-acceptance dua titiknya PASS buat demo:
                // |error| terbesar 0,00276 + U 0,000527 = 0,0033 < 0,005.
                // Kalau lab ngasih angka aslinya, ganti di sini.
                'toleransi' => 0.005,
                'lokasi' => 'Lab PT. Sidik',
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

        // Thermohygro TH-5 (INPUT DATA "Thermohygro Used : 5").
        $th5 = Standard::where('organization_id', 1)->where('nama', 'TH-5')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2211.11.R'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th5?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                // Sheet INPUT DATA nulis 11 Nov 2022 buat terima & kalibrasi.
                // Tanggal itu lebih tua dari set larutan standar yang sekarang
                // (dikalibrasi 2024–2025) — workbook-nya emang template yang
                // sampelnya nggak ikut diperbarui waktu larutannya diganti.
                // Dibiarkan apa adanya: ini catatan sumber, bukan klaim.
                'tanggal_kalibrasi' => '2022-11-11',
                'tanggal_terima' => '2022-11-11',
                'lokasi' => 'lab',
                // Awal & akhir apa adanya dari sheet INPUT DATA. suhu_ruang &
                // U95-nya JANGAN ditulis di sini — biar `KondisiLingkungan`
                // yang ngitung dari awal/akhir + koreksi sertifikat TH-5,
                // persis kayak alur teknisi beneran. Hasil yang diharapkan:
                // average 21,05 °C & 61 %RH, terkoreksi 21,96 °C & 60,41 %RH
                // (sel PERHITUNGAN baris 14–15).
                'suhu_awal' => 20.9,
                'suhu_akhir' => 21.2,
                'kelembaban_awal' => 62,
                'kelembaban_akhir' => 60,
            ],
        );

        app(KondisiLingkungan::class)->terapkan($sesi);

        $this->isiTitikUkur($sesi, $equipment, $standar);
    }

    /**
     * @param  array<int, Standard>  $standar
     */
    private function isiTitikUkur(CalibrationSession $sesi, Equipment $equipment, array $standar): void
    {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $kalkulator = new GumCalculator;
        $keputusanSesi = 'PASS';

        // Suhu ruang MENTAH (awal+akhir)/2 = 21,05 — bukan `suhu_ruang` yang
        // udah dikoreksi TH (21,96). Master Excel-nya VLOOKUP pakai yang mentah,
        // dan bedanya nyata: 21,05 turun ke baris tabel 21,0 (deviasi −0,00045),
        // 21,96 turun ke 21,9 (deviasi −0,000855).
        $suhuRuang = ((float) $sesi->suhu_awal + (float) $sesi->suhu_akhir) / 2;

        // Sekali per sesi, di luar loop — semua titik pakai versi yang sama.
        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach (self::TITIK as $index => $titik) {
            $std = $standar[$titik['standar']];
            $titikKe = $index + 1;

            foreach ($titik['tahap'] as $tahap => $data) {
                foreach ($data['pembacaan'] as $i => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $i + 1,
                        'tahap' => $tahap,
                        'titik_ukur' => $titik['titik'],
                        'pembacaan' => $nilai,
                        'suhu' => $data['suhu'][$i],
                        'satuan' => 'n20D',
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            // Sertifikat selalu dari blok "sesudah adjustment" — blok "sebelum"
            // itu dokumentasi kondisi awal alat.
            $sesudah = $titik['tahap']['sesudah_adjustment'];
            $suhuLarutan = array_sum($sesudah['suhu']) / count($sesudah['suhu']);

            $hasil = $kalkulator->hitungTitik(
                $titikKe,
                $titik['titik'],
                $sesudah['pembacaan'],
                $equipment,
                $std,
                $suhuLarutan,
                $suhuRuang,
            );

            if ($hasil['keputusan'] === 'FAIL') {
                $keputusanSesi = 'FAIL';
            }

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }

        $sesi->update(['keputusan' => $keputusanSesi]);
    }
}
