<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sandi BARU maksimal 72 BYTE.
 *
 * bcrypt hanya memakai 72 byte pertama, jadi dua sandi panjang yang berbeda
 * sesudah byte ke-72 dianggap SAMA dan verifikasinya lolos untuk keduanya (QA
 * ronde 2, R2-11: `POST /users` menerima sandi 70.000 karakter dengan 201).
 * Batasnya dalam BYTE, bukan karakter: `max:72` menghitung karakter, sehingga 37
 * huruf beraksen (74 byte) lolos dan tetap terpotong.
 *
 * Hanya dipasang di jalur yang MENETAPKAN sandi. Sandi yang sudah tersimpan tidak
 * diperiksa saat masuk, jadi akun lama tidak patah.
 */
class SandiMuatBcrypt implements ValidationRule
{
    public const MAKS_BYTE = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAKS_BYTE) {
            $fail('Password maksimal '.self::MAKS_BYTE.' karakter (72 byte). Lebih panjang dari itu dipotong diam-diam '
                .'sehingga dua password yang berbeda dianggap sama.');
        }
    }
}
