<?php

namespace App\Support;

/**
 * Bakukan satu angka yang diketik dengan KOMA desimal (`19,06`) jadi titik
 * (`19.06`) — dan HANYA bentuk itu.
 *
 * ## Kenapa sesempit ini
 *
 * Keyboard angka HP Indonesia menampilkan koma. HP kita sudah membakukannya
 * sendiri (`parseAngka` di `lembar_kerja_state.dart`), tapi server tidak boleh
 * bertumpu pada satu klien. Tanpa ini, `"19,06"` ditolak aturan `numeric` —
 * kelihatan, tapi teknisi di lokasi tidak tahu kenapa angka yang benar ditolak.
 *
 * Yang SENGAJA tidak ditebak:
 *
 *  - `1.234,5` / `1,234.5` — dua pemisah. Salah satunya pemisah ribuan, dan
 *    menebak yang mana bisa menggeser angka SERIBU kali tanpa error. Dibiarkan
 *    apa adanya supaya `numeric` menolaknya dengan jelas.
 *  - `1,2,3` — lebih dari satu koma. Sama alasannya.
 *
 * Nilai yang bukan string (angka JSON, null, array) dikembalikan tanpa disentuh.
 */
final class AngkaDesimal
{
    public static function bakukan(mixed $nilai): mixed
    {
        if (! is_string($nilai)) {
            return $nilai;
        }

        $bersih = trim($nilai);

        if (preg_match('/^[+-]?\d+,\d+$/', $bersih) === 1) {
            return str_replace(',', '.', $bersih);
        }

        return $nilai;
    }

    /**
     * Terapkan [bakukan] ke setiap daun array (bersarang), kunci dipertahankan.
     */
    public static function bakukanDalam(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return self::bakukan($nilai);
        }

        foreach ($nilai as $kunci => $isi) {
            $nilai[$kunci] = self::bakukanDalam($isi);
        }

        return $nilai;
    }
}
