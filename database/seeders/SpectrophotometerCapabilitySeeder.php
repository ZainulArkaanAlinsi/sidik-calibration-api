<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMC Spectrophotometer — TIGA baris, satu per kelompok filter.
 *
 * Sumbernya dua, dan kali ini dua-duanya TIDAK sepenuhnya cocok. Bacanya
 * pelan-pelan, karena angka ini yang jadi lantai ketidakpastian di sertifikat:
 *
 *   kelompok      rentang         master (DATABASE!S5-S7)   KAN LK-285-IDN no.47
 *   Holmium       283-641 nm      0,40 nm                   0,40 nm   ✓ cocok
 *   Didynium      474-810 nm      0,40 nm                   0,38 nm   ✗ BEDA
 *   %T @ 560 nm   10-30,5 %T      0,50 %T                   0,50 %T   ✓ cocok
 *
 * Yang dipakai: **0,40 nm** (angka master), bukan 0,38.
 *
 *  1. Sel `'PERHITUNGAN U95%'!J37` di workbook baca `DATABASE!S6` = 0,4, dan
 *     `J38 = MAX(J36,J37)` makai itu buat nyetak U95% sertifikat Didynium =
 *     0,4 nm. Itu angka yang BENERAN keluar di sertifikat lab selama ini.
 *  2. 0,40 > 0,38, jadi milih angka master itu arah AMAN — lab ngeklaim
 *     ketidakpastian lebih besar dari yang diakreditasi, bukan lebih kecil.
 *     Kebalikannya (nyeed 0,38 padahal sertifikat lama nyetak 0,4) bakal bikin
 *     hasil backend beda dari dokumen yang udah terbit.
 *
 * Bedanya TETAP perlu diberesin sama lab: entah lampiran akreditasinya
 * diperbarui, atau master Excel-nya diturunin ke 0,38. Selama belum, catatan
 * ini yang jadi jejaknya. Lihat juga `docs/handoff-backend-spectrophotometer.md`.
 *
 * ## Kenapa `range_min != range_max` di sini
 *
 * pH / Turbidimeter / Conductivity nyeed `range_min = range_max = titik` supaya
 * CMC-nya kecocok per titik. Spectrophotometer nggak bisa gitu: CMC-nya
 * dinyatakan per RENTANG panjang gelombang, dan satu rentang nutup 9-10 titik
 * sekaligus. `SpectrophotometerProfile::kemampuanPerGrup()` nyocokin lewat
 * kolom `parameter`, bukan lewat angka — perlu, karena rentang Holmium
 * (283-641) dan Didynium (474-810) tumpang tindih 167 nm dan pencocokan numerik
 * bakal salah pilih.
 *
 * ## Titik di luar rentang CMC
 *
 * Master nyertifikasi titik yang keluar dari rentang CMC-nya sendiri: Holmium
 * 279,6 nm (di bawah 283) dan %T 0 / 9,9 / 100 (di luar 10-30,5). Backend
 * ngikut — tapi nyatet, lewat komponen `titik_luar_rentang_cmc` di jejak audit
 * tiap titik.
 *
 * `u_temperature` & turunannya sengaja NULL: nggak ada satu pun komponen suhu
 * di budget Spectrophotometer. Filter kaca nggak punya kurva suhu.
 *
 * ## Soal ejaan `nama_alat`
 *
 * Di sini `Spectrophotometer` (ejaan master Excel & `DATABASE.csv` baris 8),
 * sementara `kemampuan-kalibrasi.json` nulis `Spektrofotometer`. Tiga baris
 * dari JSON itu tetap ada di tabel dan nggak dipakai jalur ini — parameternya
 * dua-duanya cuma "Panjang Gelombang", jadi Holmium & Didynium nggak bisa
 * dibedain dari situ. Itulah kenapa baris di bawah pakai label parameter
 * sendiri yang eksplisit.
 *
 * PENTING — urutan run: harus ABIS `CalibrationCapabilitySeeder` (yang ngehapus
 * semua kemampuan kategori `instrumen-analitik` sebelum nulis ulang dari JSON),
 * sama kayak seeder kemampuan alat lain.
 */
class SpectrophotometerCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    private const METODE = SpectrophotometerProfile::KODE_DOKUMEN;

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        $rentang = [
            [
                'parameter' => 'panjang gelombang (nm)-Holmium',
                'min' => 283.0,
                'max' => 641.0,
                'satuan' => 'nm',
                'ketidakpastian_terbaik' => 0.4,
            ],
            [
                'parameter' => 'panjang gelombang (nm)-Didynium',
                'min' => 474.0,
                'max' => 810.0,
                'satuan' => 'nm',
                // 0,4 dari master, bukan 0,38 dari lampiran KAN — lihat docblock.
                'ketidakpastian_terbaik' => 0.4,
            ],
            [
                'parameter' => 'akurasi (%T)',
                'min' => 10.0,
                'max' => 30.5,
                'satuan' => '%T',
                'ketidakpastian_terbaik' => 0.5,
            ],
        ];

        foreach ($rentang as $r) {
            CalibrationCapability::updateOrCreate(
                [
                    'equipment_category_id' => $kategori->id,
                    'nama_alat' => 'Spectrophotometer',
                    'parameter' => $r['parameter'],
                ],
                [
                    'range_min' => $r['min'],
                    'range_max' => $r['max'],
                    'satuan' => $r['satuan'],
                    'ketidakpastian_terbaik' => $r['ketidakpastian_terbaik'],
                    'satuan_ketidakpastian' => $r['satuan'],
                    'faktor_cakupan' => 2,
                    'metode' => self::METODE,
                    // Semua null — budget spektro nggak punya komponen suhu.
                    'u_temperature' => null,
                    'ci_suhu' => null,
                    'u_perbedaan_suhu' => null,
                    'ci_perbedaan_suhu' => null,
                ],
            );
        }
    }
}
