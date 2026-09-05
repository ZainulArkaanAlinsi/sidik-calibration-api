<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Tolak angka yang BUKAN bilangan terhingga — `INF`, `-INF`, dan `NAN`.
 *
 * ## Kegagalan yang ditutup aturan ini
 *
 * Aturan `numeric` bawaan Laravel memakai `is_numeric()`, dan
 * `is_numeric(INF)` bernilai **true**. JSON pun bisa mengangkutnya tanpa
 * sintaks khusus: `1e400` melampaui jangkauan float, jadi `json_decode()`
 * memulangkan `INF` begitu saja.
 *
 * Akibatnya satu pembacaan `1e400` lolos validasi, ikut dirata-rata, dan
 * `INF`/`NAN` menjalar ke seluruh budget ketidakpastian. Yang terbit **HTTP
 * 500** berikut jejak tumpukan — dua-duanya buruk sekaligus: teknisi di lokasi
 * cuma melihat "server error" tanpa tahu kotak mana yang salah, dan balasannya
 * membocorkan path internal berikut nama kelas ke pemanggil.
 *
 * Diadu ke sistem yang berjalan sebelum aturan ini ada: `POST
 * /api/calibrations/preview` dengan `pembacaan: [1e400, 4.02, 4.03]` memulangkan
 *
 *     500 — "Unable to encode attribute [type_b_components] ... Inf and NaN
 *            cannot be JSON encoded."
 *
 * dan hal yang sama terjadi di jalur `standar`/`uut` maupun jalur datar. Jadi
 * ini bukan cacat satu alat: SEMUA jenis alat lewat lubang yang sama.
 *
 * ## Kenapa aturan sendiri, bukan `between:` atau `max:`
 *
 * Batas atas yang masuk akal beda-beda per alat — 30000 rpm buat Tachometer,
 * 3600 detik buat Stopwatch, 2000 kg buat Timbangan — dan menaruh satu angka
 * ajaib di validator berarti menebak batas alat yang belum ada. Yang ditolak di
 * sini cuma nilai yang **tidak punya arti numerik sama sekali**; batas fisiknya
 * tetap urusan profil masing-masing alat.
 *
 * String numerik ikut diperiksa (`'1e400'` sama berbahayanya dengan `1e400`),
 * dan nilai non-numerik dilewatkan — itu tugas aturan `numeric` yang berjalan
 * bersamanya, bukan tugas aturan ini.
 */
class AngkaTerhingga implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        if (is_finite((float) $value)) {
            return;
        }

        $fail('Kolom :attribute berisi angka yang nggak terhingga (INF/NAN). '
            .'Periksa lagi angkanya — kemungkinan besar salah ketik atau kelebihan digit.');
    }
}
