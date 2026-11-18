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
    /**
     * Pasang aturan ini di SEMUA aturan angka sebuah daftar `rules()`.
     *
     * Dipasang TEPAT SESUDAH `numeric` (atau `integer`) dan didahului `bail`,
     * BUKAN ditaruh di ujung daftar. Semua aturan angka Laravel (`min`, `max`,
     * `between`, `gt`, `gte`, ...) membandingkan lewat `Brick\Math`, dan
     * `BigNumber::of(INF)` melempar `NumberFormatException` — HTTP 500, bukan
     * 422. Dicoba langsung: `min:0`, `gt:0`, `between`, dan `integer`+`min`
     * SEMUANYA melempar pada `1e400`. Kalau aturan ini baru jalan sesudahnya,
     * nilai tak terhingga meledak di aturan batas sebelum sempat ditolak.
     * Dengan urutan ini nilai itu berhenti di sini, dan aturan batas hanya
     * pernah melihat angka terhingga.
     *
     * Dipakai SEMUA pintu yang menambah aturan batas angka (CalibrationRequest,
     * EquipmentRequest, AutoclaveStoreRequest, pengaturan organisasi) — satu
     * tempat, bukan salinan di tiap request. Aturan batas yang membandingkan ke
     * FIELD LAIN (`gte:range_min`) tetap harus dijaga pemanggilnya: `bail`
     * hanya menghentikan aturan di atribut yang sama.
     *
     * @param  array<string, mixed>  $aturan  hasil `rules()`
     * @return array<string, mixed>
     */
    public static function pasangDiSemua(array $aturan): array
    {
        foreach ($aturan as $kolom => $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $posisi = array_search('numeric', $baris, true);
            $posisi = $posisi === false ? array_search('integer', $baris, true) : $posisi;

            if ($posisi === false) {
                continue;
            }

            array_splice($baris, $posisi + 1, 0, [new self]);

            if (! in_array('bail', $baris, true)) {
                array_unshift($baris, 'bail');
            }

            $aturan[$kolom] = $baris;
        }

        return $aturan;
    }

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
