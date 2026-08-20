<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Services\Calibration\Profiles\GasDetectorProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Baris kemampuan Multi Gas Detector (alat ke-10), dari
 * `Gas Detector Uli Skin (std Rigaz).xlsm`.
 *
 * ## SATU baris, dan CMC-nya NOL
 *
 * Kolom "CMC" di `DATABASE!S5:S8` **kosong seluruhnya** — keempat gas
 * (CO/H2S/CH4/O2) punya nomor & nama di kolom sebelahnya tapi tidak satu pun
 * angka CMC. Sel yang membacanya (`PERHITUNGAN U95%!AB19`, `AB37`, `AB55`,
 * `AB73`) karena itu keluar 0, dan `AB20 = MAX(U, 0)` berarti yang dilaporkan
 * selalu U hitung.
 *
 * Nol di sini artinya **belum ada klaim terakreditasi untuk gas detector**,
 * bukan "lab bisa nol ppm". `GumCalculator::hitungDariBudget()` sudah
 * membedakan dua hal itu di jejak auditnya — CMC 0 dicatat sebagai "tanpa
 * lantai CMC (titik di luar rentang terakreditasi)", bukan "vs CMC 0.00000000"
 * yang terbaca seperti klaim sempurna.
 *
 * Barisnya tetap harus ADA walau CMC-nya nol: `GumCalculator` cuma masuk jalur
 * budget penuh kalau ketemu baris kemampuan untuk titiknya. Tanpa baris ini
 * keempat gas jatuh ke jalur generik (`hitungDariStandarDanResolusi`) dan
 * kehilangan tiga dari lima komponen budget.
 *
 * Satu baris, bukan empat: karena CMC-nya sama-sama nol dan
 * `GasDetectorProfile` tidak membaca satu pun kolom kemampuan selain
 * keberadaannya, memecah jadi empat baris cuma menambah baris identik yang
 * saling menutupi di pencocokan rentang (rentang CO 0–1999 mencakup rentang
 * ketiga gas lain). Begitu KAN mengakreditasi gas detector, yang benar memang
 * empat baris — masing-masing dengan CMC-nya sendiri — dan saat itu file ini
 * yang diubah.
 *
 * `u_temperature` sengaja **null**: budget Gas Detector tidak memakainya sama
 * sekali. Komponen suhu & tekanannya lahir dari PERGESERAN ruangan sesi itu
 * (Δ = |akhir − awal|), bukan dari ketidakpastian sertifikat termometer —
 * lihat `GasDetectorProfile::komponenBudget()`. Mengisinya dengan angka apa pun
 * berarti menaruh nilai yang tidak pernah dipakai di tabel master, dan nilai
 * seperti itu selalu berakhir dipakai orang lain yang mengira itu berarti
 * sesuatu.
 *
 * PENTING — urutan run: harus SESUDAH `CalibrationCapabilitySeeder`.
 */
class GasDetectorCapabilitySeeder extends Seeder
{
    private const NAMA_KELOMPOK = 'Instrumen Analitik';

    private const NAMA_ALAT = 'Gas Detector';

    /**
     * Batas atas rentang = rentang ukur gas terbesar di alat contoh (CO 1999
     * ppm, `INPUT DATA!E14`). Dipakai sebagai rentang pencocokan, bukan sebagai
     * klaim kemampuan — CMC-nya nol.
     */
    private const RANGE_MAX = 1999.0;

    public function run(): void
    {
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug(self::NAMA_KELOMPOK)],
            ['nama' => self::NAMA_KELOMPOK],
        );

        CalibrationCapability::updateOrCreate(
            [
                'equipment_category_id' => $kategori->id,
                'nama_alat' => self::NAMA_ALAT,
                'range_min' => 0,
                'range_max' => self::RANGE_MAX,
            ],
            [
                'parameter' => 'Konsentrasi gas (CO, H₂S, CH4, O2)',
                // Satuannya campur (ppm / %LEL / %), jadi kolom yang cuma muat
                // satu diisi yang paling banyak dipakai. Satuan yang BENAR
                // untuk tiap baris sertifikat datang dari
                // `GasDetectorProfile::satuanTitik()`, bukan dari sini.
                'satuan' => 'ppm',
                // Nol = belum ada klaim terakreditasi. Lihat docblock kelas.
                'ketidakpastian_terbaik' => 0,
                'satuan_ketidakpastian' => 'ppm',
                'faktor_cakupan' => 2,
                'metode' => GasDetectorProfile::KODE_METODE,
                // Semuanya null — budget Gas Detector nggak baca satu pun.
                'u_temperature' => null,
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ],
        );
    }
}
