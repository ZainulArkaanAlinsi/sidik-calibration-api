<?php

namespace App\Services\Pencarian;

/**
 * Pola `LIKE` LONGGAR untuk kata cari yang diketik pelanggan.
 *
 * Pelanggan mengetik `CAL-2026-10-0001` untuk sertifikat bernomor
 * `CAL/2026/10/0001`; `SN 123 4` untuk seri `SN-123/4`. Kata cari dipecah di
 * rangkaian pemisah, lalu tiap potongan dirangkai dengan `%`:
 *
 *   `CAL-2026-10-0001`  ->  `%CAL%2026%10%0001%`
 *
 * Potongan harus muncul BERURUTAN di kolom, apa pun pemisah di antaranya.
 * Kata tanpa pemisah menghasilkan `%kata%`, sama dengan perilaku sebelumnya.
 *
 * ## Pemisah
 *
 * Himpunannya DIAMBIL dari `NormalisasiNomor::PEMISAH` (`/ - spasi . _`) supaya
 * aplikasi lab dan aplikasi pelanggan tidak berbeda pendapat, ditambah:
 *  - spasi putih apa pun (tab, baris baru);
 *  - `%`  -- bukan karakter nomor; diperlakukan sebagai celah supaya `100%X`
 *            tetap menemukan `100%X` dan `%` yang diketik tidak jadi pola
 *            "cocokkan semuanya";
 *  - `\`  -- karakter lolos BAWAAN `LIKE` di MySQL (tidak di SQLite). Dibiarkan,
 *            `abc\ def` menjadi `%abc\%def%` dan MySQL membaca `\%` sebagai `%`
 *            harfiah: pencarian diam-diam mencari teks lain. Orang juga
 *            kadang mengetik `\` untuk `/`.
 *
 * Karena `%`, `_`, dan `\` tidak pernah tersisa di potongan, pola ini AMAN
 * dipakai TANPA klausa `ESCAPE` (yang tidak portabel antara SQLite dan MySQL;
 * lihat `PolaLike` untuk jalur lab yang memakainya). Jangan memakai kelas ini
 * untuk teks yang harus dicocokkan harfiah.
 *
 * ## Batas
 *
 * Paling banyak {@see self::MAKS_TOKEN} potongan; sisanya dibuang (hasil lebih
 * longgar, bukan error) -- pola dengan puluhan `%` memperlambat `LIKE` tanpa
 * menambah ketepatan. Kata yang tidak menyisakan satu pun potongan (`%`, `---`,
 * spasi) menghasilkan `null`: TIDAK ada pola, dan pemanggil memutuskan. Jalur
 * pelanggan menjawab daftar KOSONG (bukan daftar utuh) -- `%` yang diketik
 * tidak boleh menampilkan semuanya.
 *
 * Huruf besar/kecil tidak diubah; kolasi DB yang menentukan (`utf8mb4_unicode_ci`
 * di MySQL, ASCII tak-peka-huruf di SQLite). Pemecahan bekerja per byte, dan
 * semua pemisah berupa ASCII, jadi aman untuk UTF-8 (juga untuk byte rusak:
 * tidak ada `preg_split` berflag `u` yang bisa gagal).
 */
final class PolaCari
{
    /** Potongan terbanyak yang dipakai; sisanya dibuang. */
    public const MAKS_TOKEN = 8;

    /** Pemisah di luar `NormalisasiNomor::PEMISAH`: lihat docblock kelas. */
    private const PEMISAH_TAMBAHAN = '%\\\\';

    /**
     * @return string|null `%a%b%c%`, atau null bila kata tak menyisakan potongan
     */
    public static function like(string $q): ?string
    {
        $token = self::token($q);

        if ($token === []) {
            return null;
        }

        return '%'.implode('%', $token).'%';
    }

    /**
     * Potongan kata cari, berurutan, tanpa yang kosong, paling banyak
     * {@see self::MAKS_TOKEN}.
     *
     * @return list<string>
     */
    public static function token(string $q): array
    {
        $kelas = '\s'.self::PEMISAH_TAMBAHAN.preg_quote(implode('', NormalisasiNomor::PEMISAH), '~');

        // Satu karakter pemisah per kecocokan: `PREG_SPLIT_NO_EMPTY` membuang
        // potongan kosong di antara pemisah yang berderet, jadi `+` tak perlu.
        $bagian = preg_split('~['.$kelas.']~', $q, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($bagian === false ? [] : $bagian, 0, self::MAKS_TOKEN);
    }
}
