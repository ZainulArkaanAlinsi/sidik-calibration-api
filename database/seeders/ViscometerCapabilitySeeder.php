<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Services\Calibration\Profiles\ViscometerProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Viscometer — TIGA baris, satu per larutan standar.
 *
 * ## Angkanya cocok, satuannya nggak
 *
 *   larutan     KAN LK-285-IDN no. 44   master DATABASE   yang diseed
 *   100 cP      0,2 cP    @ 102 cP      0,2 cP            0,2 cP  @ 51,1-102
 *   1000 cP     2,1 cP    @ 1028 cP     2,1 cP            2,1 cP  @ 419,5-1028
 *
 *   60000 cP    1,4 **P** @ 58021 cP    140 cP            140 cP  @ 19259-58021
 *
 * Baris ketiga lampiran KAN memakai satuan **Poise**, bukan centiPoise.
 * `1 P = 100 cP`, jadi 1,4 P = 140 cP dan dua dokumen itu sebenarnya sepakat.
 * Yang diseed cP, supaya sebanding dengan kolom hasil dan dengan dua baris
 * lainnya. Dibaca mentah sebagai 1,4, lantai CMC titik 60000 cP jadi seratus
 * kali lebih longgar dari yang diakreditasi — dan longgarnya nggak kelihatan
 * salah di mana pun.
 *
 * ## Kenapa RENTANG, bukan titik tunggal
 *
 * Empat alat sebelumnya nyeed CMC sebagai titik tunggal (`range_min ==
 * range_max`) karena nilai acuannya diam: buffer pH 7 selalu ~7. Viscometer
 * nggak. Nilai acuan tiap titik diinterpolasi dari tabel sertifikat larutan
 * pada suhu terukur, dan tabelnya curam:
 *
 *   larutan     20 °C     25 °C     37,78 °C
 *   100 cP      134       99,65     51,1
 *   1000 cP     1504      1018      419,5
 *   60000 cP    95192     59003     19259
 *
 * Sesi master jatuh di 93,88 / 910,29 / 61898,12 cP. Ambang pencocokan titik
 * tunggal di `GumCalculator::kemampuanUntukTitik()` itu `max(0,1 ; 0,5 % dari
 * nilai titik)` — buat titik 102 cP itu ±0,51, sementara jaraknya 8,12. Ketiga
 * titik bakal luput, jatuh ke jalur generik, dan lantai CMC nggak pernah
 * kepasang. Nggak ada error, cuma angka ketidakpastian yang lebih kecil dari
 * yang boleh diklaim lab.
 *
 * ## Batas atasnya IKUT LAMPIRAN, bukan ikut tabel larutan
 *
 * Batas BAWAH rentang diambil dari jangkauan tabel sertifikat larutan pada
 * 37,78 °C (51,1 / 419,5 / 19259 cP). Batas ATAS-nya TIDAK: yang dipakai angka
 * yang benar-benar dinyatakan KAN — 102 / 1028 / 58021 cP. Lab nggak boleh
 * ngeklaim CMC di luar ruang lingkup yang diakreditasi, dan seeder ini yang
 * jadi tempat batas itu berdiri.
 *
 * Konsekuensinya nyata dan disengaja: sesi master jatuh di 93,88 / 910,29 /
 * **61898,12** cP. Dua titik pertama masuk rentangnya, dapat lantai CMC 0,2 dan
 * 2,1 cP. Titik ketiga di ATAS 58021 cP, jadi nggak dapat lantai sama sekali —
 * `U95`-nya dilaporkan apa adanya dari mesin GUM (144,16 cP, bukan 140).
 * Itu perilaku yang benar: titik yang di luar ruang lingkup memang nggak boleh
 * dipoles pakai CMC lab.
 *
 * Kenapa nggak dinaikkan sampai 95192 cP biar titik ketiga kepasang: karena
 * angka itu bukan bunyi lampiran, dan auditor yang mencocokkan sertifikat ke
 * lampiran akreditasi bakal nemu klaim yang nggak ada dasarnya. Kalau lab mau
 * batas yang lebih tinggi, yang diurus dulu ruang lingkupnya di KAN, baru
 * angka di bawah ini. Pertanyaannya dicatat di
 * `docs/pertanyaan-lab-viscometer.md`.
 *
 * ## Baris keempat: di luar lingkup, TAPI tetap dihitung penuh
 *
 * Memotong batas atas di 58021 cP punya efek samping yang nggak kelihatan dan
 * bikin angkanya salah: `GumCalculator::hitungTitik()` cuma manggil
 * `komponenBudget()` KALAU ada baris kemampuan yang cocok. Titik 61898 cP yang
 * nggak ketemu baris apa pun jatuh ke `hitungDariStandarDanResolusi()` —
 * jalur cadangan dua komponen yang **membuang komponen pengaruh suhu**. Bukan
 * cuma lantai CMC-nya yang hilang, `uc`-nya ikut mengecil (72,0053 bukan
 * 72,8580) dan `veff`-nya melonjak (239 bukan 129). Sertifikatnya bakal
 * ngeklaim ketidakpastian LEBIH BAIK dari yang bisa dibuktikan, diam-diam.
 *
 * Jadi ada baris keempat: rentang 58021–95192 cP, `ketidakpastian_terbaik`
 * **0**. Nol di sini artinya "nggak ada CMC terakreditasi buat rentang ini",
 * bukan "CMC-nya nol" — kolomnya `NOT NULL`, jadi nol yang jadi penandanya, dan
 * `hitungDariBudget()` membaca `max(U hitung, 0)` = U hitung. Hasilnya budget
 * empat komponen yang utuh, `U95` dilaporkan apa adanya (144,17 cP), dan nggak
 * ada satu angka pun yang dipoles pakai kemampuan yang nggak diakreditasi.
 *
 * Penjaga `CalibrationValidator::periksaU95MeledakDariCmc()` juga aman: dia
 * `continue` waktu CMC-nya `<= 0`.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder`.
 */
class ViscometerCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    private const NAMA_ALAT = 'Viscometer';

    private const SATUAN = 'cP';

    /**
     * U95% termometer & sensor standar (k=2), dari `DATABASE` baris 16-17:
     * Yokogawa CA 150 Handy Cal S/N 23P1005 dan PT100/SH1.
     *
     * Angka yang sama dipakai Turbidimeter & Chlorine — satu unit fisik, satu
     * nilai. Bandingkan `RefractometerCapabilitySeeder`, yang sengaja memakai
     * angka lain karena sheet U95% refractometer-nya ketinggalan update; di
     * sini nggak ada masalah itu.
     */
    private const U95_TERMOMETER = 0.72;

    private const U95_SENSOR = 0.06;

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $uTemperature = sqrt((self::U95_TERMOMETER / 2) ** 2 + (self::U95_SENSOR / 2) ** 2);

        // `min` = jangkauan bawah tabel sertifikat larutan (37,78 °C).
        // `maks` = batas atas yang DINYATAKAN KAN LK-285-IDN no. 44, bukan
        // jangkauan atas tabel larutan — lihat docblock kelas.
        // `cmc` seluruhnya cP; baris ketiga lampiran nulisnya 1,4 P.
        $baris = [
            ['parameter' => 'viskositas (cP)-Std 100 cP', 'min' => 51.1, 'maks' => 102.0, 'cmc' => 0.2],
            ['parameter' => 'viskositas (cP)-Std 1000 cP', 'min' => 419.5, 'maks' => 1028.0, 'cmc' => 2.1],
            ['parameter' => 'viskositas (cP)-Std 60000 cP', 'min' => 19259.0, 'maks' => 58021.0, 'cmc' => 140.0],
            // Di luar lingkup KAN. `cmc => 0` = nggak ada klaim, bukan klaim
            // nol — lihat docblock kelas. Ditaruh PALING AKHIR biar titik yang
            // pas di 58021 cP nyangkut ke baris terakreditasi di atasnya
            // (`kemampuanUntukTitik()` milih yang ketemu duluan buat rentang
            // kontinyu).
            [
                'parameter' => 'viskositas (cP)-Std 60000 cP (di luar lingkup KAN)',
                'min' => 58021.0,
                'maks' => 95192.0,
                'cmc' => 0.0,
                'keterangan' => 'Di luar ruang lingkup akreditasi LK-285-IDN no. 44 (batas 58021 cP). '
                    .'Budget ketidakpastian tetap dihitung penuh; U95 dilaporkan apa adanya, tanpa lantai CMC.',
            ],
        ];

        foreach ($baris as $b) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => self::NAMA_ALAT,
                    'parameter' => $b['parameter'],
                ],
                [
                    'range_min' => $b['min'],
                    'range_max' => $b['maks'],
                    'satuan' => self::SATUAN,
                    'ketidakpastian_terbaik' => $b['cmc'],
                    'satuan_ketidakpastian' => self::SATUAN,
                    'faktor_cakupan' => 2,
                    'metode' => ViscometerProfile::KODE_METODE,
                    'keterangan' => $b['keterangan'] ?? null,
                    'u_temperature' => $uTemperature,
                    // Sengaja null: `ViscometerProfile::komponenBudget()` ngitung
                    // koefisien sensitivitasnya sendiri dari nilai nominal titik
                    // (`ci = (u_temperature / 400) x nilai@25 °C`), sama polanya
                    // kayak Turbidimeter & Chlorine.
                    'ci_suhu' => null,
                    'u_perbedaan_suhu' => null,
                    'ci_perbedaan_suhu' => null,
                ],
            );
        }
    }
}
