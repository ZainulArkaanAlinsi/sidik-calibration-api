<?php

namespace App\Services\Pencarian;

/**
 * Pola `LIKE` yang aman dari `%` dan `_` yang diketik pengguna.
 *
 * Karakter lolos-nya `!`, bukan `\`: MySQL membaca `ESCAPE '\\'` sebagai satu
 * garis miring, SQLite membacanya dua karakter dan menolak. `!` berarti sama di
 * keduanya, dan SQLite TIDAK punya karakter lolos bawaan, jadi tanpa klausa
 * `ESCAPE` eksplisit `\%` di SQLite dibaca harfiah.
 */
final class PolaLike
{
    private const LOLOS = '!';

    /** Fragmen SQL: `<ekspresi> LIKE ? ESCAPE '!'`. Satu `?` untuk pola. */
    public static function sql(string $ekspresi): string
    {
        return "{$ekspresi} LIKE ? ESCAPE '".self::LOLOS."'";
    }

    public static function mengandung(string $teks): string
    {
        return '%'.self::lolos($teks).'%';
    }

    public static function awalan(string $teks): string
    {
        return self::lolos($teks).'%';
    }

    private static function lolos(string $teks): string
    {
        return str_replace(
            [self::LOLOS, '%', '_'],
            [self::LOLOS.self::LOLOS, self::LOLOS.'%', self::LOLOS.'_'],
            $teks,
        );
    }
}
