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
 * Data Viscometer dari `Master Olah Data_Viscometer` — tiga larutan standar
 * Paragon Scientific, alat Brookfield DV-11 S/N 8535682, dan satu sesi
 * kalibrasi end-to-end yang U95%-nya BENERAN dihitung `GumCalculator`, bukan
 * angka jadi yang ditempel.
 *
 * Pembacaannya dari `INPUT DATA` blok "Before/After Adjustment Reading".
 *
 * ## Nomor sesinya SENGAJA bukan nomor job
 *
 * `INPUT DATA` Certificate Number & Order Number dua-duanya **kosong**, dan
 * kolom Nama/Alamat Customer juga kosong — workbook-nya lembar kerja trial,
 * bukan job yang pernah terbit. Ngarang nomor job atau nama pelanggan bikin
 * orang ngira ada pekerjaan lab yang nggak pernah ada. Angka kalibrasinya
 * sendiri asli.
 *
 * ## Dua tempat angkanya SENGAJA beda dari master
 *
 * **1. Titik 60000 cP cuma punya EMPAT pembacaan.** Sel pengulangan ke-5 di
 * master isinya teks `631.74.2` — dua titik desimal, jelas salah ketik.
 * Excel melewatkannya waktu AVERAGE & STDEV (makanya rata-ratanya 63151,85 =
 * rata-rata empat angka) **tapi** pembaginya tetap dipatok `√5` dengan
 * `vi = 4`. Dua hal itu nggak bisa dua-duanya benar. Yang diseed empat
 * pembacaan, dan `GumCalculator` ngitung `n = 4` secara konsisten — jadi U95
 * titik ini keluar ~144,16 cP, bukan 142,34 cP kayak master.
 *
 * **2. Faktor cakupan titik 100 cP.** `veff`-nya 5,376 dan master nyetak
 * `k = 2`; mesin GUM bersama ngambil `t(0,975 ; 5) = 2,5706`, jadi U95 titik
 * itu 0,6336 cP bukan 0,49299 cP. Master pH lab sendiri pakai t-student
 * (2,77645 buat veff 4,92), jadi yang nyimpang sel viscometer-nya.
 *
 * Dua-duanya dicatat di `docs/pertanyaan-lab-viscometer.md` dan diassert
 * EKSPLISIT di `ViscometerBudgetTest` — biar kalau lab menjawab, yang berubah
 * ketahuan langsung, bukan lewat test yang toleransinya kelewat longgar.
 *
 * ## Yang cocok persis sama master
 *
 *   titik       Standard Value (interpolasi)   rata-rata UUT   uc                     MPE
 *   100 cP      93,87566510172147              96,72           0,24649576970552553    4,141803174603175
 *   1000 cP     910,2887323943662              917,66          1,356001576327294      22,079825806451613
 *   60000 cP    61898,119999999995             63151,85        (lihat catatan 1)      1921,8410806451611
 *
 * WAJIB jalan SETELAH `ViscometerCapabilitySeeder` (butuh CMC + `u_temperature`)
 * dan `ThermohygroSeeder` (TH-2).
 */
class ViscometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Tiga larutan standar (`DATABASE` R13:Z15) + tabel sertifikat suhunya
     * (`Tabel Pengaruh Temperature`).
     *
     * `ketidakpastian` = U95% pada 25 °C dalam cP, k=2 — hasil `u_persen` baris
     * 25 °C dikali nilainya (0,17 % × 99,65 = 0,169405). Disimpen absolut
     * supaya jalur hitung nggak perlu tau soal persen; `u_persen` tetap ikut
     * di tabel sebagai sumber angkanya.
     *
     * Kolom densitas di sertifikat larutan SENGAJA nggak ikut: nggak ada satu
     * sel pun di jalur hitung master yang makai, dan nyimpen angka yang nggak
     * dipakai bikin pembaca berikutnya nyari-nyari di mana dia kepake.
     *
     * @var list<array{nama: string, merk: string, serial: string, berlaku: string, ketidakpastian: float, tabel: list<array{suhu: float, nilai: float, u_persen: float}>}>
     */
    private const STANDAR = [
        [
            'nama' => 'Viscosity Standard Solution 100 cP',
            'merk' => 'Paragon Scientific/S60',
            'serial' => '1241202088',
            // Kalibrasi 19 Agt 2025, interval 3 tahun.
            'berlaku' => '2028-08-19',
            'ketidakpastian' => 0.169405,
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 134.0, 'u_persen' => 0.17],
                ['suhu' => 25.0, 'nilai' => 99.65, 'u_persen' => 0.17],
                ['suhu' => 37.78, 'nilai' => 51.1, 'u_persen' => 0.15],
                ['suhu' => 40.0, 'nilai' => 45.97, 'u_persen' => 0.15],
                ['suhu' => 50.0, 'nilai' => 29.75, 'u_persen' => 0.13],
                ['suhu' => 60.0, 'nilai' => 20.32, 'u_persen' => 0.13],
                ['suhu' => 80.0, 'nilai' => 10.75, 'u_persen' => 0.13],
                ['suhu' => 98.89, 'nilai' => 6.638, 'u_persen' => 0.08],
                ['suhu' => 100.0, 'nilai' => 6.47, 'u_persen' => 0.08],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 1000 cP',
            'merk' => 'Paragon Scientific/D1000',
            'serial' => '1252905118',
            // Kalibrasi 25 Nov 2025, interval 3 tahun.
            'berlaku' => '2028-11-25',
            'ketidakpastian' => 2.3414,
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 1504.0, 'u_persen' => 0.23],
                ['suhu' => 25.0, 'nilai' => 1018.0, 'u_persen' => 0.23],
                ['suhu' => 37.78, 'nilai' => 419.5, 'u_persen' => 0.19],
                ['suhu' => 40.0, 'nilai' => 364.6, 'u_persen' => 0.19],
                ['suhu' => 50.0, 'nilai' => 203.2, 'u_persen' => 0.19],
                ['suhu' => 60.0, 'nilai' => 121.3, 'u_persen' => 0.17],
                ['suhu' => 80.0, 'nilai' => 51.27, 'u_persen' => 0.15],
                ['suhu' => 98.89, 'nilai' => 26.8, 'u_persen' => 0.13],
                ['suhu' => 100.0, 'nilai' => 25.9, 'u_persen' => 0.13],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 60000 cP',
            'merk' => 'Paragon Scientific/N18000',
            'serial' => '4230901097',
            // Kalibrasi 24 Sep 2025, interval 2 tahun.
            'berlaku' => '2027-09-24',
            'ketidakpastian' => 135.7069,
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 95192.0, 'u_persen' => 0.23],
                ['suhu' => 25.0, 'nilai' => 59003.0, 'u_persen' => 0.23],
                ['suhu' => 37.78, 'nilai' => 19259.0, 'u_persen' => 0.23],
                ['suhu' => 40.0, 'nilai' => 16096.0, 'u_persen' => 0.23],
                ['suhu' => 50.0, 'nilai' => 7489.0, 'u_persen' => 0.22],
                ['suhu' => 60.0, 'nilai' => 3732.0, 'u_persen' => 0.22],
                ['suhu' => 80.0, 'nilai' => 1117.0, 'u_persen' => 0.2],
                ['suhu' => 98.89, 'nilai' => 432.7, 'u_persen' => 0.17],
                ['suhu' => 100.0, 'nilai' => 411.3, 'u_persen' => 0.17],
            ],
        ],
    ];

    /**
     * Titik sesi contoh, urut kayak lembarnya. `pembacaan` & `suhu` sejajar
     * per-index.
     *
     * Titik ketiga sengaja EMPAT pembacaan — lihat docblock kelas.
     *
     * @var list<array{standar: int, spindle: string, rpm: float, pembacaan: list<float>, suhu: list<float>}>
     */
    private const TITIK = [
        [
            'standar' => 0,
            'spindle' => 'HA1',
            'rpm' => 63.0,
            'pembacaan' => [97.3, 96.9, 96.8, 95.9, 96.7],
            'suhu' => [26.6, 26.5, 26.5, 26.6, 26.4],
        ],
        [
            'standar' => 1,
            'spindle' => 'HA2',
            'rpm' => 62.0,
            'pembacaan' => [919.6, 918.7, 917.4, 916.3, 916.3],
            'suhu' => [27.3, 27.4, 27.2, 27.3, 27.3],
        ],
        [
            'standar' => 2,
            'spindle' => 'HA7',
            'rpm' => 62.0,
            'pembacaan' => [63181.3, 63079.8, 63172.1, 63174.2],
            'suhu' => [24.6, 24.6, 24.6, 24.6],
        ],
    ];

    /** Model badan alat (`INPUT DATA` "Model visco on body") → TK 2. */
    private const MODEL_VISCO = 'DV2THA';

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Pelanggan Demo Viscometer'],
            [
                'organization_id' => 1,
                // Master-nya nggak nyebut pelanggan sama sekali (kolom Nama &
                // Alamat Customer kosong), jadi nggak ada yang bisa disalin ke
                // sini tanpa mengarang.
                'alamat' => '-',
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
                    'tertelusur_ke' => 'Paragon Scientific Limited',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => 'cP',
                    'faktor_cakupan' => 2,
                    // Bentuk TABEL, bukan polinom {a,b,c} kayak buffer pH.
                    // Lihat `Standard::nilaiPadaSuhu()`.
                    'koefisien_suhu' => ['tabel' => $s['tabel']],
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '8535682'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Viscometer',
                // Kunci yang bikin registry milih ViscometerProfile.
                'nama_alat_kemampuan' => 'Viscometer',
                'merk' => 'Brookfield',
                'model' => 'DV-11',
                // INPUT DATA "Rentang Ukur : 100-65000".
                'range_min' => 100,
                'range_max' => 65000,
                'satuan' => 'cP',
                'resolusi' => 0.1,
                // SENGAJA null, dan ini BEDA artinya dari Spectrophotometer.
                // Viscometer punya batas keberterimaan (MPE), tapi batasnya
                // dihitung per titik dari spindle & RPM — bukan satu angka per
                // alat. Lihat ViscometerProfile::toleransiTitik().
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

        $th2 = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => 'DEMO-VISCO-BROOKFIELD'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th2?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_terima' => '2026-07-31',
                'tanggal_kalibrasi' => '2026-07-31',
                'lokasi' => 'onsite',
                // Pembacaan thermohygro apa adanya (INPUT DATA). suhu_ruang &
                // U95-nya JANGAN ditulis di sini — `KondisiLingkungan` yang
                // ngitung, dan hasilnya harus 25,02 °C / 56,5 %RH persis kayak
                // PERHITUNGAN.
                'suhu_awal' => 25.2,
                'suhu_akhir' => 25.3,
                'kelembaban_awal' => 57.0,
                'kelembaban_akhir' => 58.0,
                // Nentuin TK lewat Tabel D-2. Dibaca `toleransiTitik()` waktu
                // ngitung MPE, dan dibaca ulang `CalibrationValidator` waktu
                // hitung ulang.
                'spesifikasi_alat' => ['model_visco' => self::MODEL_VISCO],
                'submitted_at' => now(),
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

        $versiRumus = $this->versiRumusUntuk($sesi);
        $gum = app(GumCalculator::class);

        // Suhu ruang MENTAH (awal+akhir)/2 — sama persis kayak yang dipakai
        // `CalibrationController::susunPengukuran()`.
        $suhuRuang = ((float) $sesi->suhu_awal + (float) $sesi->suhu_akhir) / 2.0;

        foreach (self::TITIK as $index => $titik) {
            $std = $standar[$titik['standar']];
            $titikKe = $index + 1;
            $nominal = self::STANDAR[$titik['standar']]['tabel'][1]['nilai'];

            foreach ($titik['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $i + 1,
                    'tahap' => 'sesudah_adjustment',
                    // Nilai NOMINAL botol — yang tertulis di lembar kerja.
                    // Yang kecetak di sertifikat nanti hasil interpolasi pada
                    // suhu terukur, dan itu dihitung `hitungTitik()` di bawah.
                    'titik_ukur' => $nominal,
                    'standard_id' => $std->id,
                    'pembacaan' => $nilai,
                    'suhu' => $titik['suhu'][$i],
                    'satuan' => 'cP',
                    'spindle' => $titik['spindle'],
                    'rpm' => $titik['rpm'],
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $suhuLarutan = array_sum($titik['suhu']) / count($titik['suhu']);

            $hasil = $gum->hitungTitik(
                $titikKe,
                (float) $nominal,
                $titik['pembacaan'],
                $equipment,
                $std,
                $suhuLarutan,
                $suhuRuang,
                [
                    'spindle' => $titik['spindle'],
                    'rpm' => $titik['rpm'],
                    'tk' => self::MODEL_VISCO,
                ],
            );

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }
    }
}
