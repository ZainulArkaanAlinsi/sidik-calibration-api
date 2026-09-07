<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Services\Calibration\Profiles\HeightGaugeProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Baris kemampuan Height Gauge (alat ke-26), dari
 * `Master_olda_Height_Gauge_600_mm_2026.xlsm`.
 *
 * ## SATU baris, dan CMC-nya NOL
 *
 * Height Gauge **tidak ada di lampiran akreditasi LK-285-IDN**. Kelompok
 * Panjang di `database/data/kemampuan-kalibrasi.json` cuma memuat Sieve,
 * Micrometer, Vernier Caliper, dan Dial Indicator.
 *
 * Masternya sendiri mengakuinya: sel lantai CMC (`PERHITUNGAN U95%!AA19`)
 * KOSONG, dan `AA20 = MAX(AA18:AA19)` karena itu selalu memulangkan `U` hitung
 * telanjang. Jadi nol di sini artinya **belum ada klaim terakreditasi untuk
 * height gauge**, bukan "lab bisa nol mm".
 *
 * ## `CMC_UTM` yang menggoda dan TIDAK dipungut
 *
 * `DATABASE!S5:T5` memuat `CMC 0-300mm = 15 µm` di bawah defined name
 * `CMC_UTM` — sisa warisan master Jangka Sorong. Dia TIDAK dipungut, dua
 * sebabnya masing-masing cukup: (a) tidak tersambung ke sheet U95 mana pun di
 * workbook ini, dan (b) alatnya 600 mm, di luar pita 0-300 itu sendiri.
 * Memungutnya berarti mengarang lantai akreditasi untuk lingkup yang tidak
 * diakreditasi.
 *
 * ## Barisnya tetap harus ADA walau CMC-nya nol
 *
 * Sama persis alasannya dengan `GasDetectorCapabilitySeeder`, yang jadi
 * presedennya: `GumCalculator` cuma masuk jalur budget penuh kalau ketemu baris
 * kemampuan untuk alat itu. Tanpa baris ini sesinya jatuh ke jalur generik
 * (`hitungDariStandarDanResolusi`) dan kehilangan tujuh dari sembilan komponen
 * budget — dan yang terbit bukan error, cuma U95 yang jauh lebih kecil.
 *
 * Baris ini juga yang mengisi kolom `metode` di tiap baris
 * `uncertainty_calculations`, jadi tanpa dia sertifikatnya terbit tanpa nomor
 * Instruksi Kerja.
 *
 * ## Baris ini TIDAK memberi hak klaim akreditasi
 *
 * Yang menentukan boleh-tidaknya sertifikat membawa "Terakreditasi … No.
 * LK-285-IDN" itu `HeightGaugeProfile::dalamLingkupAkreditasi()`, bukan ada
 * atau tidaknya baris ini. Keduanya sengaja dipisah: baris ini urusan MESIN
 * HITUNG (tanpa dia budget penuh tidak jalan), klaim akreditasi urusan LINGKUP.
 * Menggabungkannya berarti menambah baris kemampuan untuk alat baru diam-diam
 * memberinya klaim akreditasi.
 *
 * `u_temperature` dan ketiga saudaranya sengaja **null**: budget Height Gauge
 * tidak membaca satu pun kolom itu. Komponen suhunya lahir dari selisih suhu
 * ruangan sesi terhadap 20 °C, bukan dari ketidakpastian sertifikat
 * termometer — lihat `HeightGaugeCalculator::budget()`. Mengisinya dengan angka
 * apa pun berarti menaruh nilai yang tidak pernah dipakai di tabel master, dan
 * nilai seperti itu selalu berakhir dipakai orang lain yang mengira itu berarti
 * sesuatu.
 *
 * PENTING — urutan run: harus SESUDAH `CalibrationCapabilitySeeder`.
 */
class HeightGaugeCapabilitySeeder extends Seeder
{
    /**
     * **Panjang**, bukan kategori baru — dan bedanya bukan selera penamaan.
     *
     * Kategori alat itu yang dipungut `GET /api/categories`, dan daftarnya
     * HARUS sepuluh kelompok pengukuran lampiran akreditasi, tidak lebih. Alat
     * ini memang belum diakreditasi, tapi KELOMPOKNYA ada dan alatnya jelas
     * milik kelompok itu. Membuat kategori sendiri melahirkan kategori HANTU:
     * kelompok kesebelas yang di HP tampil sebagai kartu tambahan. Dijaga
     * `KategoriAlatIkutLampiranTest`.
     */
    private const NAMA_KELOMPOK = 'Panjang';

    private const NAMA_ALAT = 'Height Gauge';

    /**
     * Batas atas rentang = kapasitas alat contoh (600 mm, `INPUT DATA!E15`) dan
     * sekaligus nominal terbesar tabel Caliper Checker.
     *
     * Dipakai sebagai rentang PENCOCOKAN, bukan sebagai klaim kemampuan —
     * CMC-nya nol.
     */
    private const RANGE_MAX = 600.0;

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
                'parameter' => 'Panjang (tinggi)',
                'satuan' => 'mm',
                // Nol = belum ada klaim terakreditasi. Lihat docblock kelas.
                'ketidakpastian_terbaik' => 0,
                // `mm`, BUKAN `µm` — dan ini bukan detail kosmetik. Budget
                // Height Gauge hidup dalam mm (`AF18 = I5 = "mm"`), beda dari
                // Micrometer yang µm. Salah satuan di sini bikin pembanding CMC
                // di jejak audit membandingkan angka yang berbeda seribu kali.
                'satuan_ketidakpastian' => 'mm',
                'faktor_cakupan' => 2,
                'metode' => HeightGaugeProfile::KODE_METODE,
                // Semuanya null — budget Height Gauge nggak baca satu pun.
                'u_temperature' => null,
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ],
        );
    }
}
