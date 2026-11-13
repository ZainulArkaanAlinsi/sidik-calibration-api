<?php

namespace App\Services\Pencarian;

/**
 * Bentuk pembanding nomor dokumen: huruf besar, tanpa `/`, `-`, dan spasi.
 *
 * Orang mengetik `cal 2026 10 0001`, `CAL-2026-10-0001`, atau menempel
 * `CAL/2026/10/0001` untuk dokumen yang sama. Pembandingnya harus
 * menyamakan ketiganya, di kedua sisi: teks yang dicari DAN kolom di DB.
 *
 * Revisi (`-R1`) tidak diperlakukan khusus. Nomor dasar adalah awalan nomor
 * revisinya, jadi mencari nomor dasar otomatis ikut menemukan revisinya, dan
 * mencari `…-R1` hanya menemukan revisi itu.
 */
final class NormalisasiNomor
{
    /** Karakter yang diabaikan saat membandingkan nomor. */
    private const PEMISAH = ['/', '-', ' '];

    /** Bentuk pembanding dari teks yang diketik pengguna. */
    public static function kunci(string $teks): string
    {
        return strtoupper(str_replace(self::PEMISAH, '', trim($teks)));
    }

    /**
     * Ekspresi SQL yang menghasilkan bentuk pembanding dari sebuah kolom.
     * `REPLACE` ada di MySQL maupun SQLite; huruf besar/kecil diurus oleh
     * `LIKE` / `UPPER` di pemanggil.
     *
     * @param  string  $kolomTerbungkus  nama kolom yang SUDAH lewat `Grammar::wrap()`
     */
    public static function sqlKolom(string $kolomTerbungkus): string
    {
        $ekspresi = $kolomTerbungkus;

        foreach (self::PEMISAH as $pemisah) {
            $ekspresi = "REPLACE({$ekspresi}, '{$pemisah}', '')";
        }

        return $ekspresi;
    }
}
