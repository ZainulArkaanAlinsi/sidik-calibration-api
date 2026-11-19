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
     * Nilai mutlak terbesar yang muat di kolom `decimal(20,8)`: 12 digit sebelum
     * koma. Satu angka untuk semua pintu masuk, supaya `1e300` (terhingga, tapi
     * meledak jadi 500 SQLSTATE 22003 di kolom — atau, di kolom JSON, TERSIMPAN
     * dan meracuni hitungan) ditolak 422 di tempat yang sama dengan `1e400`.
     */
    public const BATAS_DECIMAL = 999999999999;

    /**
     * Pasang batas rentang kolom di tiap aturan `numeric` yang belum punya batas
     * sendiri (`between:` / `max:`), lalu biarkan [pasangDiSemua] memasang penjaga
     * tak-terhingga SEBELUM batas itu.
     *
     * Aturan yang sudah punya `between`/`max` dihormati apa adanya — batas fisik
     * per alat (suhu -50..150, kelembaban 0..100) lebih sempit dari kolom dan
     * tetap menang. Yang ditutup di sini hanya angka yang lolos `numeric` TANPA
     * batas apa pun.
     *
     * @param  array<string, mixed>  $aturan  hasil `rules()`
     * @param  string|null  $awalan  hanya kunci berawalan ini (mis. `spesifikasi_alat.`); null = semua
     * @return array<string, mixed>
     */
    public static function batasiRentang(array $aturan, ?string $awalan = null, int|float $batas = self::BATAS_DECIMAL): array
    {
        foreach ($aturan as $kolom => $baris) {
            if (! is_array($baris) || ! in_array('numeric', $baris, true)) {
                continue;
            }

            if ($awalan !== null && ! str_starts_with((string) $kolom, $awalan)) {
                continue;
            }

            foreach ($baris as $satu) {
                if (is_string($satu) && preg_match('/^(between|max|size|in):/', $satu) === 1) {
                    continue 2;
                }
            }

            $baris[] = 'between:-'.$batas.','.$batas;
            $aturan[$kolom] = $baris;
        }

        return $aturan;
    }

    /**
     * Daun mana saja di sebuah pohon masukan (mis. blok `spesifikasi_alat`) yang
     * BUKAN angka yang bisa dipakai hitungan.
     *
     * Dipakai untuk blok yang isinya struktur bebas (keterulangan Timbangan,
     * eksentrisitas, histeresis, ...) sehingga tidak punya aturan `numeric` per
     * kunci. Yang ditolak:
     *
     *  - angka JSON tak terhingga (`1e400` didekode jadi INF) atau di luar kolom;
     *  - angka JSON/teks yang tidak punya arti numerik: `"NaN"`, `"Infinity"`,
     *    `"-INF"` — `(float) "NaN"` bernilai 0, jadi dibiarkan ia jadi NOL diam-diam;
     *  - teks numerik yang tak terhingga (`"1e999"`).
     *
     * Teks numerik yang TERHINGGA tidak diperiksa rentangnya: kunci teks bebas
     * (nama neraca, nomor seri) bisa saja berisi deret angka panjang yang sah.
     * Rentang untuk kunci yang memang angka dijaga aturan `numeric` + [batasiRentang].
     *
     * @return array<string, string> atribut (titik-bersarang) => pesan
     */
    public static function temuanPohon(mixed $pohon, string $akar): array
    {
        $temuan = [];

        $jalan = function (mixed $nilai, string $jalur) use (&$jalan, &$temuan): void {
            if (is_array($nilai)) {
                foreach ($nilai as $kunci => $isi) {
                    $jalan($isi, $jalur.'.'.$kunci);
                }

                return;
            }

            if (is_int($nilai) || is_float($nilai)) {
                if (! is_finite((float) $nilai)) {
                    $temuan[$jalur] = 'Kolom '.$jalur.' berisi angka yang nggak terhingga (INF/NAN). '
                        .'Periksa lagi angkanya — kemungkinan besar salah ketik atau kelebihan digit.';
                } elseif (abs((float) $nilai) > self::BATAS_DECIMAL) {
                    $temuan[$jalur] = 'Kolom '.$jalur.' di luar rentang yang bisa disimpan (maksimal ±'.self::BATAS_DECIMAL.').';
                }

                return;
            }

            if (! is_string($nilai)) {
                return;
            }

            $teks = trim($nilai);

            if (preg_match('/^[+-]?(nan|inf|infinity)$/i', $teks) === 1
                || (is_numeric($teks) && ! is_finite((float) $teks))) {
                $temuan[$jalur] = 'Kolom '.$jalur.' berisi angka yang nggak terhingga (INF/NAN). '
                    .'Periksa lagi angkanya — kemungkinan besar salah ketik atau kelebihan digit.';
            }
        };

        $jalan($pohon, $akar);

        return $temuan;
    }

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
