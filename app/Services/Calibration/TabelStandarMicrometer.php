<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar **Micrometer**, dibaca dari
 * `database/data/tabel-standar-micrometer.json`.
 *
 * Empat workbook master turun dari lab — 0-25, 25-50, 50-75, dan 75-100 mm —
 * dan keempatnya memuat tabel balok ukur serta rumus yang IDENTIK baris demi
 * baris. Yang membedakan cuma pita CMC-nya. Jadi satu tabel untuk empat
 * rentang, bukan empat berkas; skrip generatornya mengadu keempat workbook dan
 * menolak menulis kalau ada yang menyimpang.
 *
 * ## Nominal balok ukur itu KUNCI PASTI, bukan pita
 *
 * Master mencari nilai terkoreksi balok ukur lewat
 * `VLOOKUP(nominal; Nominal_mm; 2; 0)` — argumen terakhir `0` = **cocok
 * persis**, dibungkus `IFERROR(...; "")`. Nominal yang tidak ada di tabel
 * memulangkan sel kosong, dan titik itu ikut hilang dari sertifikat tanpa satu
 * pun error. [nilaiTerkoreksi] meniru sisi pencariannya (balik `null` untuk
 * yang tidak ketemu) tapi TIDAK meniru diamnya — pemanggil wajib mengangkat
 * `null` itu jadi titik yang diblokir dengan alasan kebaca.
 *
 * ## Baris kosong di master TIDAK ikut disalin
 *
 * `Nominal_mm` di master mencakup `Standar_GB!Q10:R132` — 123 baris, tapi cuma
 * 32 yang berisi balok ukur nyata. 91 sisanya nominalnya kosong dan nilainya
 * terbaca `0`, karena rumusnya (`=E10`) menunjuk sel yang memang kosong. Angka
 * nol itu bukan panjang balok, dia artefak; ikut disalin, dia jadi balok ukur
 * bernilai nol yang cocok untuk nominal 0 dan diam-diam menggeser total
 * nominal satu titik. Generator membuang baris ber-nominal kosong.
 */
class TabelStandarMicrometer
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Nilai terkoreksi (mm) satu balok ukur pada `$nominal`, atau `null` kalau
     * nominal itu tidak ada di tabel standar.
     *
     * Dicocokkan dengan toleransi 1e-9, bukan `===`: nominal datang dari
     * masukan teknisi lewat JSON dan pembulatan biner float bikin `2.5` hasil
     * `json_decode` tidak selalu identik bit-per-bit dengan `2.5` di tabel.
     */
    public function nilaiTerkoreksi(float $nominal): ?float
    {
        foreach (self::muat()['balok_ukur'] as $kunci => $nilai) {
            if (abs((float) $kunci - $nominal) < 1e-9) {
                return (float) $nilai;
            }
        }

        return null;
    }

    /**
     * Ketidakpastian baku satu balok ukur (µm) pada `$nominal`, meniru tangga
     * `PERHITUNGAN!H23` master: dibaca dari atas, yang pertama cocok menang.
     *
     * Slot balok ukur yang KOSONG memulangkan `0.0` — bukan `0.12`. Di master
     * itu dijaga kebetulan: sel kosong berisi string `""`, dan Excel menilai
     * teks selalu LEBIH BESAR dari angka berapa pun, jadi `"" <= 10` bernilai
     * salah dan tangganya jatuh ke cabang terakhir. Di PHP perbandingan itu
     * tidak berperilaku sama, jadi slot kosong disaring pemanggil sebelum
     * sampai ke sini — lihat [MicrometerCalculator::ketidakpastianTumpukan].
     */
    public function ketidakpastianBalok(float $nominal): float
    {
        $tabel = self::muat()['ketidakpastian_balok_um'];

        foreach ($tabel['persis'] as $kunci => $nilai) {
            if (abs((float) $kunci - $nominal) < 1e-9) {
                return (float) $nilai;
            }
        }

        foreach ($tabel['aturan'] as $aturan) {
            if ($nominal <= (float) $aturan['maks']) {
                return (float) $aturan['u'];
            }
        }

        return (float) $tabel['lainnya'];
    }

    /**
     * Pita CMC yang memuat `$kapasitasMm`, atau `null` kalau kapasitas alat ada
     * di luar keempat pita terakreditasi.
     *
     * Urutannya meniru `INPUT DATA!F5` master persis: dibaca dari atas dengan
     * `<=`, yang pertama cocok menang (<=25 → A, <=50 → B, <=75 → C,
     * <=100 → D).
     *
     * ## Dua hal yang SENGAJA tidak ditiru
     *
     * **Kapasitas di luar pita.** Master memulangkan teks `"cek range"`, lalu
     * `MAX()` di sel U95-sertifikat mengabaikan teks itu dan angka terbit tanpa
     * lantai CMC. Di workbook 0-25 mm itu nyata terjadi — satuannya tersetel
     * `inch`, kapasitas 25 dikali 25,4 jadi 635 mm, jatuh di luar keempat pita,
     * dan yang tercetak 0,735 µm padahal pita terakreditasinya 0,83 µm. U yang
     * terbit LEBIH KECIL dari yang diakreditasi, tanpa satu pun error.
     *
     * **Kapasitas kosong.** Sel kapasitas yang belum diisi terbaca `0` di
     * master, dan `0 <= 25` bernilai benar — jadi alat tanpa kapasitas mendarat
     * diam-diam di pita A dan memungut lantai 0,83 µm yang bukan haknya. Di
     * sini kapasitas nol/negatif memulangkan `null`.
     *
     * `null` BUKAN "pakai angka generik": pemanggil wajib memblokir titiknya
     * dengan alasan kebaca, bukan meneruskan.
     *
     * @return array{kode: string, label: string, kapasitas_min_mm: float, kapasitas_maks_mm: float, u95_um: float}|null
     */
    public function pitaCmc(float $kapasitasMm): ?array
    {
        if ($kapasitasMm <= 0.0) {
            return null;
        }

        foreach (self::muat()['cmc'] as $pita) {
            if ($kapasitasMm <= (float) $pita['kapasitas_maks_mm']) {
                return $pita;
            }
        }

        return null;
    }

    /**
     * Tetapan budget yang di master hidup sebagai angka telanjang di dalam
     * rumus (Δα, wringing, geometri, koefisien drift, suhu acuan, vi Type B).
     *
     * @return array<string, float|int>
     */
    public function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /**
     * Identitas keping standar balok ukur — dipakai bagian "Standard used" di
     * sertifikat dan sebagai titik nol umur drift.
     *
     * @return array{nama: string, merk_tipe: string, seri: string, traceability: string, tanggal_kalibrasi: string}
     */
    public function standar(): array
    {
        return self::muat()['standar'];
    }

    /** @return array<string, float> nominal (mm) => nilai terkoreksi (mm) */
    public function balokUkur(): array
    {
        return self::muat()['balok_ukur'];
    }

    /** @return list<array{kode: string, label: string, kapasitas_min_mm: float, kapasitas_maks_mm: float, u95_um: float}> */
    public function semuaPitaCmc(): array
    {
        return self::muat()['cmc'];
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-micrometer.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar micrometer nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        // `standar` ikut diperiksa: [umurStandarHari] membaca
        // `standar.tanggal_kalibrasi` sebagai titik nol umur drift, dan tanpa
        // penjagaan ini berkas yang kehilangan blok itu meledak jauh dari
        // sini — di tengah perhitungan satu sesi, bukan waktu tabelnya dimuat.
        if (! is_array($isi)
            || ! isset($isi['balok_ukur'], $isi['cmc'], $isi['konstanta'])
            || ! isset($isi['standar']['tanggal_kalibrasi'])) {
            throw new RuntimeException("Tabel standar micrometer rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
