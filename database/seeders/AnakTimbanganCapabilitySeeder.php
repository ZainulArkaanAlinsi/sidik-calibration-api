<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Baris kemampuan Anak Timbangan (alat ke-29), dari
 * `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx`.
 *
 * ## SATU baris, dan CMC-nya NOL
 *
 * Kalibrasi anak timbangan **tidak ada di lampiran akreditasi LK-285-IDN**.
 * Kelompok Massa di `database/data/kemampuan-kalibrasi.json` cuma memuat satu
 * baris: no. 12, "Timbangan (Elektronik, mekanik)" — alat yang menimbang, bukan
 * keping yang ditimbangkan padanya.
 *
 * Masternya sendiri mengakuinya: sel berlabel `CMC PT. SIDIK` di kedua puluh
 * blok budget KOSONG, dan `U95% Sertifikat` selalu sama persis dengan `U = k·Uc`
 * tanpa perbandingan apa pun. Sudah diperiksa satu per satu oleh
 * `AnakTimbanganMasterTest::master_tidak_punya_lantai_cmc_di_satu_pun_blok()`.
 * Jadi nol di sini artinya **belum ada klaim terakreditasi untuk anak
 * timbangan**, bukan "lab bisa nol gram".
 *
 * ## `CMC_AT` yang menggoda dan TIDAK dipungut
 *
 * `DATABASE` memuat tabel sembilan pita berlabel **"Jenis Timbangan"** (A
 * 0,1–100 g = 0,00016 g, B 100–210 g = 0,0004 g, … I 200–2000 kg = 450 g) di
 * bawah defined name `CMC_AT`. Dia TIDAK dipungut, dan tiga sebabnya
 * masing-masing cukup:
 *
 *  1. labelnya sendiri menyebut **Timbangan**, alat yang lain — itu pita CMC
 *     milik alat ke-21, bukan milik lembar ini;
 *  2. namanya menunjuk ke workbook LAIN lewat tautan luar, jadi nilai yang
 *     terbaca sekarang tinggal cache yang bisa basi;
 *  3. anak timbangan memang di luar lingkup, jadi tidak ada pita yang berlaku.
 *
 * Memungutnya berarti mengarang lantai akreditasi untuk lingkup yang tidak
 * diakreditasi — dan lebih buruk lagi, meminjamnya dari alat lain. Pertanyaan
 * lab §15.
 *
 * ## Barisnya tetap harus ADA walau CMC-nya nol
 *
 * Sama persis alasannya dengan `GasDetectorCapabilitySeeder` dan
 * `HeightGaugeCapabilitySeeder`: `GumCalculator` cuma masuk jalur budget penuh
 * kalau ketemu baris kemampuan untuk alat itu. Tanpa baris ini sesinya jatuh ke
 * jalur generik (`hitungDariStandarDanResolusi`) dan kehilangan empat dari enam
 * komponen budget — dan yang terbit bukan error, cuma U95 yang jauh lebih kecil.
 *
 * Baris ini juga yang mengisi kolom `metode` di tiap baris
 * `uncertainty_calculations`, jadi tanpa dia sertifikatnya terbit tanpa nomor
 * Instruksi Kerja.
 *
 * ## Baris ini TIDAK memberi hak klaim akreditasi
 *
 * Yang menentukan boleh-tidaknya sertifikat membawa "Terakreditasi … No.
 * LK-285-IDN" itu `AnakTimbanganProfile::dalamLingkupAkreditasi()`, bukan ada
 * atau tidaknya baris ini. Keduanya sengaja dipisah: baris ini urusan MESIN
 * HITUNG, klaim akreditasi urusan LINGKUP. Menggabungkannya berarti menambah
 * baris kemampuan untuk alat baru diam-diam memberinya klaim akreditasi.
 *
 * `u_temperature` dan ketiga saudaranya sengaja **null**: budget Anak Timbangan
 * tidak membaca satu pun kolom itu. Suhu masuk lewat densitas udara, bukan lewat
 * ketidakpastian sertifikat termometer — lihat
 * `AnakTimbanganCalculator::budget()`. Mengisinya dengan angka apa pun berarti
 * menaruh nilai yang tidak pernah dipakai di tabel master, dan nilai seperti itu
 * selalu berakhir dipakai orang lain yang mengira itu berarti sesuatu.
 *
 * PENTING — urutan run: harus SESUDAH `CalibrationCapabilitySeeder`.
 */
class AnakTimbanganCapabilitySeeder extends Seeder
{
    /**
     * **Massa**, kelompok yang sama dengan Timbangan — bukan kategori baru.
     *
     * Kategori alat itu yang dipungut `GET /api/categories`, dan daftarnya
     * HARUS sepuluh kelompok pengukuran lampiran akreditasi, tidak lebih. Alat
     * ini memang belum diakreditasi, tapi KELOMPOKNYA ada dan alatnya jelas
     * milik kelompok itu. Membuat kategori sendiri melahirkan kategori HANTU:
     * kelompok kesebelas yang di HP tampil sebagai kartu tambahan. Dijaga
     * `KategoriAlatIkutLampiranTest`.
     */
    private const NAMA_KELOMPOK = 'Massa';

    private const NAMA_ALAT = 'Anak Timbangan';

    /**
     * Batas atas rentang = nominal terbesar tabel keping standar lab (20 kg,
     * set `Anak Timbangan F1-20`).
     *
     * Dipakai sebagai rentang PENCOCOKAN, bukan sebagai klaim kemampuan —
     * CMC-nya nol. Batas bawahnya NOL, bukan 0,001: keping terkecil yang
     * dimiliki lab memang 1 mg, tapi rentang yang dimulai persis di situ
     * menyingkirkan sesi yang titiknya kebetulan bernominal lebih kecil dan
     * membuatnya jatuh ke jalur generik tanpa satu pun error.
     */
    private const RANGE_MAX = 20000.0;

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
                'parameter' => 'Massa konvensional',
                'satuan' => 'g',
                // Nol = belum ada klaim terakreditasi. Lihat docblock kelas.
                'ketidakpastian_terbaik' => 0,
                // `g`, BUKAN `mg` — dan ini bukan detail kosmetik. Budget
                // internalnya memang miligram (mengikuti sheet master), tapi
                // yang DISIMPAN ke `uncertainty_calculations` gram, dan
                // pembanding CMC di jejak audit membaca kolom ini. Salah satuan
                // di sini bikin dia membandingkan angka yang berbeda seribu kali.
                'satuan_ketidakpastian' => 'g',
                'faktor_cakupan' => 2,
                'metode' => AnakTimbanganProfile::KODE_METODE,
                // Semuanya null — budget Anak Timbangan nggak baca satu pun.
                'u_temperature' => null,
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ],
        );
    }
}
