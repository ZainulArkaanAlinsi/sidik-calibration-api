<?php

namespace App\Services\Pencarian;

/**
 * Bentuk pembanding nomor dokumen: huruf besar, tanpa `/`, `-`, dan spasi.
 *
 * Orang mengetik `cal 2026 10 0001`, `CAL-2026-10-0001`, `CAL.2026.10.0001`,
 * atau menempel `CAL/2026/10/0001` untuk dokumen yang sama. Pembandingnya harus
 * menyamakan semuanya, di kedua sisi: teks yang dicari DAN kolom di DB.
 *
 * Revisi (`-R1`) tidak diperlakukan khusus. Nomor dasar adalah awalan nomor
 * revisinya, jadi mencari nomor dasar otomatis ikut menemukan revisinya, dan
 * mencari `…-R1` hanya menemukan revisi itu.
 *
 * Himpunan pemisah ini JUGA dipakai `PolaCari` (jalur pelanggan) untuk memecah
 * kata cari, supaya aplikasi pelanggan dan aplikasi lab tidak berbeda pendapat
 * tentang apa yang dianggap pemisah. `.` dan `_` ditambahkan 13 Nov 2026 untuk
 * itu; sebelumnya hanya `/`, `-`, dan spasi.
 */
final class NormalisasiNomor
{
    /**
     * Karakter yang diabaikan saat membandingkan nomor. Urutannya menentukan
     * susunan `REPLACE` bersarang di `sqlKolom()`; tambahan baru ditaruh di
     * belakang.
     *
     * Backslash SENGAJA tidak termasuk: `'\'` di SQL berarti lain di MySQL dan
     * SQLite, dan tidak ada nomor dokumen yang memakainya. `PolaCari` menanganinya
     * sendiri di sisi teks yang diketik.
     */
    public const PEMISAH = ['/', '-', ' ', '.', '_'];

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
