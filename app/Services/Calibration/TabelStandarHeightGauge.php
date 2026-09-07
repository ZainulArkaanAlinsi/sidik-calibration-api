<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar **Height Gauge**, dibaca dari
 * `database/data/tabel-standar-height-gauge.json`.
 *
 * Standarnya BUKAN balok ukur seperti Micrometer, melainkan satu **Caliper
 * Checker** (Metrology CMG-9060C, S/N 800035) yang sertifikatnya memuat sepuluh
 * nominal bertingkat 25..600 mm. Karena itu tidak ada tumpukan keping
 * di-*wringing* di sini: satu titik = satu nominal.
 *
 * ## Nominal itu KUNCI PASTI, bukan pita
 *
 * Master mencari nilai terkoreksi lewat `VLOOKUP(nominal; Nom_Outside; 4; 0)` —
 * argumen terakhir `0` = cocok persis. [nilaiTerkoreksi] meniru sisi
 * pencariannya (balik `null` untuk yang tidak ketemu) tapi TIDAK meniru diamnya:
 * pemanggil wajib mengangkat `null` jadi titik yang diblokir dengan alasan
 * kebaca.
 *
 * ## Tabel Inside ikut disalin, TIDAK disambungkan
 *
 * `Std_CaliperCek!C23:J32` memuat tabel Inside yang lengkap, dan defined name
 * `Nom_Inside` menunjuk ke sana — tapi tidak ada satu pun rumus jalur Height
 * Gauge yang memakainya; kesepuluh `VLOOKUP` titik memakai `Nom_Outside`. Dia
 * disalin supaya pembaca berikutnya tidak mengira lab kehilangan angkanya, dan
 * [inside] sengaja tidak dipanggil mesin hitung mana pun. Lihat
 * `docs/pertanyaan-lab-height-gauge.md` §9.
 *
 * ## Warisan master Jangka Sorong yang TIDAK dipungut
 *
 * Workbook ini turunan master Jangka Sorong dan masih membawa enam defined name
 * yang tidak dipakai jalur Height Gauge sama sekali: `Nom_Inside`, `CMC_UTM`,
 * `Tabel_Standar`, `Standar_Conduct`, `Satuan_Caliper`, dan `Index_rhTH`
 * (kelembapan memang tidak masuk budget). Yang paling menggoda `CMC_UTM`
 * (`DATABASE!S5:T5`, "CMC 0-300mm = 15 µm"): dia TIDAK tersambung ke sheet U95
 * mana pun, dan alat ini 600 mm — di luar pita 0-300. Memungutnya sebagai
 * lantai berarti mengarang klaim akreditasi; lihat
 * [\App\Services\Calibration\HeightGaugeCalculator].
 */
class TabelStandarHeightGauge
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Nilai terkoreksi (mm) Caliper Checker pada `$nominal`, atau `null` kalau
     * nominal itu tidak ada di tabel Outside.
     *
     * Dicocokkan dengan toleransi 1e-9, bukan `===`: nominal datang dari
     * masukan lewat JSON dan pembulatan biner float bikin `550.0` hasil
     * `json_decode` tidak selalu identik bit-per-bit dengan `550.0` di tabel.
     */
    public function nilaiTerkoreksi(float $nominal): ?float
    {
        foreach (self::muat()['outside'] as $baris) {
            if (abs((float) $baris['nominal_mm'] - $nominal) < 1e-9) {
                return (float) $baris['nilai_terkoreksi_mm'];
            }
        }

        return null;
    }

    /**
     * Kesepuluh nominal PRA-CETAK lembar kerja (mm), urut naik.
     *
     * Sumbernya satu dengan tabel standarnya sendiri, dan itu disengaja:
     * kesepuluh titik `INPUT DATA` master sama persis dengan sepuluh baris
     * tabel Outside, dan Instruksi Kerja yang menetapkannya. Menyimpannya dua
     * kali berarti dua daftar yang harus ingat diperbarui bareng — dan yang
     * ketinggalan tidak menerbitkan error, cuma titik yang nominalnya tidak
     * ketemu di tabel.
     *
     * @return list<float>
     */
    public function titikPraCetak(): array
    {
        return array_map(
            static fn (array $b): float => (float) $b['nominal_mm'],
            self::muat()['outside'],
        );
    }

    /**
     * Ketidakpastian baku standar (mm) — `U95 / 2`.
     *
     * Sertifikat Caliper Checker menulis `U95 = 4,1 µm` untuk KESEPULUH baris
     * di KEDUA tabel, jadi dia satu angka tingkat-sesi dan bukan tangga per
     * nominal seperti balok ukur Micrometer. Skrip generatornya memeriksa itu
     * dan berhenti kalau sertifikat berikutnya berbeda per nominal.
     */
    public function ketidakpastianStandarMm(): float
    {
        return (float) self::muat()['standar']['u95_um'] / 1000.0 / 2.0;
    }

    /**
     * Tetapan budget yang di master hidup sebagai angka telanjang di dalam
     * rumus (Δα, geometri, meja granit, koefisien drift, suhu acuan, vi).
     *
     * @return array<string, float|int>
     */
    public function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /**
     * Identitas Caliper Checker — dipakai bagian "Standard used" di sertifikat
     * dan sebagai titik nol umur drift.
     *
     * @return array{nama: string, merk: string, tipe: string, merk_tipe: string, seri: string, tanggal_kalibrasi: string, tertelusur: string, rentang: string, u95_um: float}
     */
    public function standar(): array
    {
        return self::muat()['standar'];
    }

    /** @return list<array{nominal_mm: float, nilai_terkoreksi_mm: float, koreksi_um: float}> */
    public function outside(): array
    {
        return self::muat()['outside'];
    }

    /**
     * Tabel Inside — ADA di master, TIDAK dipakai jalur hitung. Lihat docblock
     * kelas.
     *
     * @return list<array{nominal_mm: float, nilai_terkoreksi_mm: float, koreksi_um: float}>
     */
    public function inside(): array
    {
        return self::muat()['inside'];
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-height-gauge.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar height gauge nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        // `standar.tanggal_kalibrasi` ikut diperiksa: dia titik nol umur drift,
        // dan tanpa penjagaan ini berkas yang kehilangan blok itu meledak jauh
        // dari sini — di tengah perhitungan satu sesi, bukan waktu tabelnya
        // dimuat.
        if (! is_array($isi)
            || ! isset($isi['outside'], $isi['inside'], $isi['konstanta'])
            || ! isset($isi['standar']['tanggal_kalibrasi'], $isi['standar']['u95_um'])) {
            throw new RuntimeException("Tabel standar height gauge rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
