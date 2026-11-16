<?php

namespace App\Support;

use App\Models\UncertaintyCalculation;

/**
 * Nilai antara BERPRESISI PENUH untuk angka yang DICETAK di tabel hasil.
 *
 * ## Masalah yang ditutup
 *
 * Master lab itu workbook Excel: sel menyimpan double, dan yang tercetak adalah
 * tampilan sel itu (15 angka penting, lalu setengah menjauhi nol — itu yang
 * dikerjakan [Angka::tampilanExcel]). Titik SERI (nilai tepat di tengah dua
 * pembulatan, mis. `x,xx5`) jatuh ke atas atau ke bawah menurut sisa biner
 * double-nya:
 *
 *     94,235 − 93,48 = 0,7549999999999955   → master mencetak 0,75
 *
 * Tetapi kolom `uncertainty_calculations.koreksi` itu `decimal(20,8)`, dan
 * nilainya sudah dibulatkan ke 8 desimal SEBELUM sampai ke sana
 * (`CalibrationController::bulatkanHitungan`, supaya pratinjau = tersimpan; di
 * jalur lain PDO mengikat float lewat teks 14 angka penting). Yang tersimpan
 * `0,755` — sisa biner yang menentukan arah seri sudah hilang, dan sertifikat
 * mencetak `0,76`. Aturan cetak [Angka::hasil] sendiri benar; yang sampai
 * kepadanya sudah bukan nilai yang dilihat Excel.
 *
 * ## Yang dilakukan di sini
 *
 * Kolom tidak diubah (tanpa migrasi, nilai tersimpan tetap yang dibulatkan).
 * Float aslinya ikut dibekukan di jejak audit baris itu
 * (`type_b_components[presisi_penuh]`), dalam bentuk TEKS desimal terpendek yang
 * kembali ke float yang sama persis ([AngkaFloatEksak]) — bukan angka JSON,
 * supaya tidak bergantung `serialize_precision` server maupun cara MySQL
 * menuliskan double di kolom JSON. Hanya kolom yang dicetak yang dibekukan, dan
 * hanya bila pembulatan 8 desimal memang membuang sesuatu.
 *
 * Pembaca ([baca]) memakai nilai beku itu HANYA bila dia benar-benar pra-bayangan
 * dari nilai di kolom (selisih ≤ setengah satuan desimal ke-8). Baris yang lahir
 * sebelum perubahan ini tidak punya kuncinya dan dibaca dari kolom seperti
 * biasa — angka cetak sertifikat lama tidak bergeser sedikit pun.
 *
 * ## Kunci ini tidak terlihat oleh pembaca Eloquent
 *
 * `type_b_components` dibaca puluhan tempat yang menganggap isinya daftar
 * komponen. Cast kolomnya ([\App\Casts\JejakTypeB]) menyembunyikan kunci beku:
 * `$baris->type_b_components` selalu daftar bersih, dan simpan-ulang lewat
 * atribut tidak menghapus kuncinya. Yang melihatnya hanya kelas ini, lewat nilai
 * mentah atribut ([petaBeku], [jejakMentah]). Tanpa pembaca SQL-JSON-path —
 * bentuk tersimpan berubah dari daftar `[…]` menjadi objek `{"0":…,"presisi_penuh":…}`.
 *
 * Batas: untuk |x| ≳ 1e6 teks 14 angka penting PDO menggeser kolom lebih dari
 * toleransi pra-bayangan dari float aslinya, sehingga kuncinya DITOLAK diam-diam
 * dan angka itu dibaca dari kolom seperti sebelum perubahan ini (bukan regresi).
 */
final class PresisiPenuh
{
    /** Kunci di `type_b_components` (bukan komponen budget — bukan juga bagian API; disembunyikan cast). */
    public const KUNCI = 'presisi_penuh';

    /**
     * Kolom `uncertainty_calculations` yang tercetak di tabel hasil
     * (Standard Value, Unit Under Test, Correction, U95%).
     *
     * @var list<string>
     */
    public const KOLOM = ['titik_ukur', 'rata_rata', 'koreksi', 'ketidakpastian_diperluas'];

    /** Presisi kolom `decimal(20, 8)`. */
    private const DESIMAL_KOLOM = 8;

    /** Setengah satuan desimal ke-8 (5e-9) plus kelonggaran derau float. */
    private const TOLERANSI_PRABAYANGAN = 5.5e-9;

    /**
     * Float asli kolom cetak yang akan KEHILANGAN sesuatu bila dibulatkan ke
     * 8 desimal, dalam bentuk teks round-trip.
     *
     * @param  array<string, mixed>  $nilai  atribut/baris hitung, sebelum dibulatkan
     * @return array<string, string>
     */
    public static function kumpulkan(array $nilai): array
    {
        $beku = [];

        foreach (self::KOLOM as $kolom) {
            $asli = $nilai[$kolom] ?? null;

            if (! is_float($asli) || ! is_finite($asli) || round($asli, self::DESIMAL_KOLOM) === $asli) {
                continue;
            }

            $beku[$kolom] = AngkaFloatEksak::kodekan($asli);
        }

        return $beku;
    }

    /**
     * Jejak `type_b_components` + float asli yang dibekukan.
     *
     * Kunci yang sudah ada TIDAK ditimpa: jalur simpan membekukan SEBELUM
     * pembulatan, dan penyimpan model (yang cuma melihat nilai yang sudah
     * dibulatkan) tidak boleh menggantinya. Tanpa apa pun yang perlu dibekukan,
     * jejaknya dipulangkan apa adanya (null tetap null).
     *
     * Dipanggil dari `UncertaintyCalculation::save()`, jadi tidak melempar
     * karena BENTUK jejak: jejak yang bukan daftar/peta (string JSON mentah dari
     * penulis yang mengisi atribut mentah, angka, dsb.) tidak bisa disisipi
     * kunci dan dipulangkan apa adanya — baris itu dibaca dari kolom, seperti
     * dulu. Pengecualian yang disengaja: `AngkaFloatEksak::kodekan` melempar
     * `RuntimeException` bila `serialize_precision` server salah (gagal tertutup).
     *
     * @param  array<string, mixed>  $nilai
     */
    public static function sisipkan(mixed $jejak, array $nilai): mixed
    {
        if ($jejak !== null && ! is_array($jejak)) {
            return $jejak;
        }

        if (isset($jejak[self::KUNCI])) {
            return $jejak;
        }

        $beku = self::kumpulkan($nilai);

        if ($beku === []) {
            return $jejak;
        }

        return [...($jejak ?? []), self::KUNCI => $beku];
    }

    /**
     * Peta float beku dari nilai MENTAH kolom `type_b_components` (teks JSON
     * atau array), atau null bila tidak ada / bentuknya rusak.
     *
     * Satu-satunya cara membaca kunci beku: cast model ([JejakTypeB])
     * menyembunyikannya dari semua pembaca lain.
     */
    public static function petaBeku(mixed $mentah): ?array
    {
        $isi = is_string($mentah) ? json_decode($mentah, true) : $mentah;
        $beku = is_array($isi) ? ($isi[self::KUNCI] ?? null) : null;

        return is_array($beku) ? $beku : null;
    }

    /** [petaBeku] untuk satu baris hitung (nilai mentah atributnya). */
    public static function petaBekuBaris(UncertaintyCalculation $titik): ?array
    {
        return self::petaBeku($titik->getAttributes()['type_b_components'] ?? null);
    }

    /**
     * Isi mentah `type_b_components` suatu baris — SEMUA kunci, termasuk yang
     * beku — didekode satu kali. Bukan array bila atributnya null atau bukan
     * JSON daftar/peta (mis. string yang terenkode dua kali).
     */
    public static function jejakMentah(UncertaintyCalculation $titik): mixed
    {
        $mentah = $titik->getAttributes()['type_b_components'] ?? null;

        return is_string($mentah) ? json_decode($mentah, true) : $mentah;
    }

    /**
     * Nilai kolom cetak untuk satu baris hitung: float asli bila beku dan
     * sepadan dengan kolom, kalau tidak nilai kolom itu sendiri.
     */
    public static function baca(UncertaintyCalculation $titik, string $kolom): float
    {
        $nilai = (float) $titik->{$kolom};
        $beku = self::petaBekuBaris($titik)[$kolom] ?? null;

        if (! is_string($beku) || ! is_numeric($beku)) {
            return $nilai;
        }

        $asli = (float) $beku;

        // Pra-bayangan sah: pembulatan 8 desimalnya kembali ke nilai kolom.
        // Kolom yang sudah diubah sesudah dibekukan (koreksi tangan, migrasi
        // data) tidak lagi sepadan dan dibaca apa adanya.
        return is_finite($asli) && abs($asli - $nilai) <= self::TOLERANSI_PRABAYANGAN ? $asli : $nilai;
    }

    /**
     * Jejak untuk KELUARAN (API): tanpa kunci beku. Bentuk daftar komponen yang
     * dibaca klien tidak berubah — kunci ini milik server, bukan komponen budget.
     *
     * @param  array<array-key, mixed>  $jejak
     * @return array<array-key, mixed>
     */
    public static function tanpaKunci(array $jejak): array
    {
        unset($jejak[self::KUNCI]);

        return $jejak;
    }
}
