<?php

namespace App\Support;

/**
 * Format angka buat dokumen resmi (sertifikat PDF & Excel).
 *
 * Sertifikatnya dipakai di Indonesia: pemisah desimalnya KOMA (`4,01`), bukan
 * titik. Ini bukan selera — angka `4.01` di dokumen berbahasa Indonesia kebaca
 * "empat ribu sepuluh" sama sebagian orang. Snapshot sertifikat nyimpen angka
 * mentah (float), pemformatannya baru di sini biar satu aturan dipakai semua
 * tampilan.
 */
class Angka
{
    /** Kalau alatnya nggak punya resolusi, jatuh ke sini. */
    public const DESIMAL_DEFAULT = 4;

    /**
     * Jumlah desimal yang wajar buat alat dengan resolusi segini.
     *
     * Resolusi 0,01 → 2 desimal; 0,001 → 3. Nulis lebih banyak desimal daripada
     * yang bisa dibaca alatnya itu ngaku-ngaku presisi yang nggak ada.
     */
    public static function desimalDariResolusi(?float $resolusi): int
    {
        if ($resolusi === null || $resolusi <= 0) {
            return self::DESIMAL_DEFAULT;
        }

        // log10(0.01) = -2 → 2 desimal. Dibatesin 0..6 biar resolusi aneh
        // (0 sekian belas nol) nggak bikin kolom sertifikat kepanjangan.
        $desimal = (int) max(0, ceil(-log10($resolusi)));

        return min($desimal, 6);
    }

    /** `4.005` → `4,01`. null → tanda pisah, bukan `0`. */
    public static function id(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        return number_format($nilai, $desimal, ',', '.');
    }

    /**
     * Angka buat tabel **CALIBRATION REPORT** — beda dari [id()] di dua hal, dan
     * dua-duanya dibaca dari master lab, bukan diturunkan dari konvensi.
     *
     * 1. **Tanpa pemisah ribuan.** Master nulis `1000` & `1001`, bukan `1.000` &
     *    `1.001`. `number_format()` selalu ngelompokin, jadi titik 1.000 NTU
     *    kecetak `1.001` — di dokumen yang komanya dipakai buat desimal, itu
     *    kebaca ambigu.
     * 2. **Tanda minus dipertahankan walau membulat ke nol.** Master nulis
     *    `-0,00` (koreksi -0,004) dan `-0,0` (koreksi -0,02). PHP sendiri yang
     *    ngebuang tandanya: `number_format(-0.004, 2)` balik `0,00`.
     *
     * Poin 2 pernah dipatok terbalik di tes ("yang kecetak `0`, BUKAN `-0`") —
     * ditulis dari nalar, bukan dari kertasnya. Waktu master Turbidimeter
     * `0189-CAL-624` diadu langsung 10 Agt 2026, yang kecetak `-0,00` / `-0,0`.
     * Tandanya bukan hiasan: dia yang bilang alatnya baca DI ATAS standar.
     */
    public static function hasil(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        $teks = number_format($nilai, $desimal, ',', '');

        // `$nilai < 0` sengaja dicek di nilai ASLI, bukan di hasil pembulatan —
        // itu justru bedanya: -0,004 membulat ke nol, tapi tetap negatif.
        if ($nilai < 0 && ! str_starts_with($teks, '-')) {
            $teks = '-'.$teks;
        }

        return $teks;
    }

    /**
     * Kolom **Standard Value** — [hasil()] dengan nol di belakang dibuang.
     *
     * Kolomnya nulis nilai NOMINAL standar yang dipakai, dan master nulisnya apa
     * adanya: Turbidimeter `1` / `100` / `1000` (bukan `1,00` / `100,0`),
     * sementara Chlorine tetap `1,74` & `1,83` dan pH `4,01`. Bedanya bukan
     * aturan per alat — bedanya standar Turbidimeter itu angka bulat, jadi
     * desimalnya nggak membawa informasi apa pun.
     *
     * Batas atasnya tetap desimal barisnya: `4,009244572` di baris ber-2-desimal
     * tetap kecetak `4,01`, nggak balik jadi presisi penuh.
     */
    public static function nilaiStandar(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        $teks = self::hasil($nilai, $desimal);

        if (! str_contains($teks, ',')) {
            return $teks;
        }

        return rtrim(rtrim($teks, '0'), ',');
    }

    /**
     * Ketidakpastian (U95) — dijamin kebaca **2 angka penting**.
     *
     * Kolom hasil lain dicetak sebanyak desimal alatnya (resolusi 0,01 → 2
     * desimal), dan buat pembacaan itu bener: nulis lebih banyak dari yang bisa
     * dibaca alatnya itu ngaku-ngaku presisi.
     *
     * Ketidakpastian beda aturannya. Dia lazim dilaporin 2 angka penting, dan
     * dipaksa ikut desimal alat justru bikin angkanya rusak:
     *
     *     U = 0,02658849   desimal alat 2  ->  "0,03"   (1 angka penting)
     *     U = 0,023432     desimal alat 2  ->  "0,02"   (1 angka penting)
     *
     * "0,02" dan "0,03" itu beda 50% dari nilai aslinya — dan di sertifikat
     * terakreditasi, U yang salah baca langsung ngubah kesimpulan PASS/FAIL
     * waktu pelanggan ngebandingin sama toleransinya.
     *
     * Desimalnya dinaikin cuma kalau perlu; U yang udah besar nggak berubah:
     *
     *     0,2199  ->  "0,22"    (2 desimal udah cukup)
     *     0,02658 ->  "0,027"   (naik ke 3)
     *     0,00234 ->  "0,0023"  (naik ke 4)
     */
    public static function ketidakpastian(
        ?float $nilai,
        int $desimalAlat = self::DESIMAL_DEFAULT,
        string $kosong = '—',
    ): string {
        if ($nilai === null) {
            return $kosong;
        }

        $desimal = $desimalAlat;
        $besar = abs($nilai);

        if ($besar > 0.0) {
            // Posisi angka penting pertama. `0,0265` -> eksponen -2, jadi butuh
            // 3 desimal biar dua angka pentingnya (2 dan 7) kebaca.
            $eksponenPertama = (int) floor(log10($besar));
            $perluDesimal = -$eksponenPertama + 1;

            // Dibatasi 8: kolomnya `decimal(20,8)`, lebih dari itu nggak ada
            // isinya lagi.
            $desimal = min(max($desimalAlat, $perluDesimal), 8);
        }

        return number_format($nilai, $desimal, ',', '.');
    }

    /**
     * Sama kayak `id()`, tapi nol di belakang koma dibuang: `4,0100` → `4,01`.
     * Dipakai buat nilai yang jumlah desimalnya beda-beda (mis. kapasitas alat).
     */
    public static function idRingkas(?float $nilai, int $desimal = self::DESIMAL_DEFAULT, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        $teks = number_format($nilai, $desimal, ',', '.');

        return str_contains($teks, ',') ? rtrim(rtrim($teks, '0'), ',') : $teks;
    }
}
