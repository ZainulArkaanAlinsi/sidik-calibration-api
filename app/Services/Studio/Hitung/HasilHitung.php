<?php

namespace App\Services\Studio\Hitung;

/**
 * Hasil satu kali `MesinHitung::hitung()`.
 *
 *  - `nilai`: kunci sel (`Judul!A1`) → nilai hasil (float/int/string/bool/
 *    kode galat Excel sebagai string/null) untuk setiap rumus yang dihitung.
 *  - `galat`: kunci sel → pesan, untuk sel yang tidak bisa dihitung mesin
 *    (nilainya = nilai cache Excel) dan sel yang dihitung dari nilai itu.
 *  - `kotor`: rumus yang dihitung, urutan evaluasi.
 */
final class HasilHitung
{
    /**
     * @param  array<string, mixed>  $nilai
     * @param  array<string, string>  $galat
     * @param  list<string>  $kotor
     */
    public function __construct(
        public readonly array $nilai,
        public readonly array $galat,
        public readonly array $kotor,
    ) {}
}
