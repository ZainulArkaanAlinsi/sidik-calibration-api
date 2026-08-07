<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Refractometer (4 titik), dari sheet DATABASE `Master Olah
 * Data_Refractometer.xlsm` tabel "Jenis UTM / CMC" — sama persis sama lampiran
 * akreditasi LK-285-IDN no. 45 dan `04 - Referensi Teknis/data-kemampuan-kalibrasi.json`.
 *
 *   1,3366 n20D → 6,2e-05 n20D      2,5 °Brix → 0,019 °Brix
 *   1,3999 n20D → 6,7e-05 n20D      40  °Brix → 0,02  °Brix
 *
 * Pola sama kayak `ChlorineCapabilitySeeder`: `range_min = range_max = titik`
 * biar `GumCalculator::kemampuanUntukTitik()` nyocokin persis, dan
 * `updateOrCreate` nggak numpuk sama baris kategori dari
 * `CalibrationCapabilitySeeder`.
 *
 * ## Kenapa cuma n20D yang dapat `u_temperature`
 *
 * Blok budget °Brix di `PERHITUNGAN U95%.csv` rusak — isinya `#REF!` &
 * `#DIV/0!`, dan komponennya beda total dari n20D (pakai "Berat Sukrosa" &
 * "Berat Molekul Sukrosa"). Jadi CMC °Brix tetap diseed supaya titiknya bisa
 * dikalibrasi & dilaporkan, tapi `u_temperature` sengaja NULL: `komponenBudget`
 * balik null dan titik °Brix jatuh ke jalur CMC lama
 * (`GumCalculator::hitungDariKemampuan`). Ngelaporin CMC apa adanya jauh lebih
 * jujur daripada ngarang lima komponen dari blok yang rusak. Diputusin 6 Agt
 * 2026; begitu lab ngasih export °Brix yang bersih, isi `u_temperature`-nya di
 * sini dan lengkapi `RefractometerProfile::komponenBudget()`.
 *
 * ## PERINGATAN — `u_temperature` di sini SENGAJA beda dari alat lain
 *
 * Angkanya diturunin dari kepala sheet `PERHITUNGAN U95%` refractometer:
 *
 *   √((0,07/2)² + (0,036/2)²) = 0,03935733730830886
 *
 * Turbidimeter & Chlorine pakai 0,36124783736376886, dari √((0,72/2)²+(0,06/2)²).
 * **Termometernya unit FISIK yang sama** (Yokogawa CA 150 Handy Cal, S/N
 * 23P1005 + PT100/SH1) — jadi satu alat standar punya dua nilai di sistem, dan
 * itu nggak mungkin dua-duanya benar.
 *
 * Yang bikin curiga sheet U95% refractometer-nya yang ketinggalan, bukan dua
 * alat lain — sheet DATABASE di workbook refractometer SENDIRI nulis 0,72 &
 * 0,06, dan riwayat revisinya nyebut update itu:
 *
 *   - `FORM VALIDASI` rev.11 (23 Mei 2025): "Update Database U95% 0,036 menjadi
 *     0,06 & Tgl Kalibrasi PT 100"
 *   - `FORM VALIDASI` rev.13 (1 Sep 2025): "Change Standard Constant to Yokogawa"
 *
 * Kelihatannya sel di sheet U95% nggak ikut ke-update waktu dua revisi itu.
 * TAPI yang dipakai di sini tetap 0,07/0,036 — keputusan 6 Agt 2026: tiru master
 * Excel persis, supaya angka yang keluar sama dengan sertifikat refractometer
 * yang sudah pernah terbit. **Jangan "disamain" ke 0,72/0,06 sepihak.** Yang
 * diurus dulu konfirmasi lab; kalau lab mbenerin sheet-nya, ganti satu angka di
 * bawah dan `RefractometerBudgetTest` bakal langsung nunjukin dampaknya.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder`.
 */
class RefractometerCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    private const NAMA_ALAT = 'Refractometer';

    /** U95% termometer & sensor dari kepala sheet `PERHITUNGAN U95%` (k=2). */
    private const U95_TERMOMETER = 0.07;

    private const U95_SENSOR = 0.036;

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt((self::U95_TERMOMETER / 2) ** 2 + (self::U95_SENSOR / 2) ** 2);

        // `u_temperature` null = titik itu jalan lewat jalur CMC lama. Lihat
        // docblock kelas soal kenapa °Brix belum dapat budget penuh.
        $titik = [
            ['titik' => 1.3366, 'satuan' => 'n20D', 'cmc' => 6.2e-05, 'u_temperature' => $uTemperature],
            ['titik' => 1.3999, 'satuan' => 'n20D', 'cmc' => 6.7e-05, 'u_temperature' => $uTemperature],
            ['titik' => 2.5, 'satuan' => '°Brix', 'cmc' => 0.019, 'u_temperature' => null],
            ['titik' => 40.0, 'satuan' => '°Brix', 'cmc' => 0.02, 'u_temperature' => null],
        ];

        foreach ($titik as $t) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => self::NAMA_ALAT,
                    'range_min' => $t['titik'],
                    'range_max' => $t['titik'],
                ],
                [
                    'parameter' => 'Refractive Index',
                    'satuan' => $t['satuan'],
                    'ketidakpastian_terbaik' => $t['cmc'],
                    'satuan_ketidakpastian' => $t['satuan'],
                    'faktor_cakupan' => 2,
                    'metode' => 'SIDIK-IK-CAL-0516',
                    'u_temperature' => $t['u_temperature'],
                    // Sengaja null: `RefractometerProfile` ngitung koefisien
                    // sensitivitas & deviasi suhunya sendiri dari tabel konversi.
                    'ci_suhu' => null,
                    'u_perbedaan_suhu' => null,
                    'ci_perbedaan_suhu' => null,
                ],
            );
        }
    }
}
