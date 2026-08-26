<?php

namespace App\Support;

/**
 * Kode satu SEL tabel di dalam `calibration_sessions.revisi_field`.
 *
 * ## Kenapa ada
 *
 * Sampai sebelum ini `revisi_field` cuma bisa menunjuk KOLOM — `alat_model`,
 * `pemilik_nama`, `suhu_awal`. Kolom identitas memang cukup begitu: satu kotak,
 * satu kode. Tapi yang paling sering minta dibetulkan justru bukan kotak
 * identitas — melainkan SATU ANGKA di dalam tabel pengukuran, di antara puluhan
 * angka lain yang sudah benar.
 *
 * Yang bisa dilakukan admin cuma dua-duanya buruk: menulis prosa ("Repeat 3 di
 * titik 1412 kelihatan komanya kegeser") dan berharap teknisi menemukannya
 * dengan mata, atau menandai seluruh tabelnya. Yang kedua itu yang bikin
 * teknisi mengosongkan tabel lalu mengetik ulang semuanya — termasuk angka yang
 * sudah benar, yang lalu berisiko salah ketik BARU di sesi revisi.
 *
 * Dengan kode sel, penolakan bisa menunjuk tepat satu kotak. Yang lain tetap
 * berdiri.
 *
 * ## Bentuknya
 *
 * ```
 * sel:<tahap>:<titik_ukur>:<kolom>:<pembacaan_ke>
 * sel:sesudah_adjustment:1412:pembacaan:3
 * ```
 *
 * `pembacaan_ke` 1-based, sama seperti `raw_measurements` dan sama seperti
 * nomor Repeat yang tercetak di kertas — bukan index 0-based di layar. Yang
 * mengubahnya jadi index cuma satu tempat, di sisi HP.
 *
 * **Yang dipakai TITIK UKUR-nya, bukan `titik_ke`.** Alasannya sama dengan yang
 * sudah dipegang seluruh alur pemulihan draft: `titik_ke` itu POSISI baris, dan
 * posisinya geser tiap bentuk lembar berubah — baris varian satuan Conductivity
 * menyusut begitu alatnya dipilih. Penanda yang menempel ke posisi bakal
 * berpindah ke angka lain tanpa satu pun error, dan yang disorot merah jadi
 * angka yang justru sudah benar.
 *
 * ## Batas 64 karakter
 *
 * `revisi_field.*` divalidasi `max:64` di `CalibrationController::reject()`.
 * [buat] balik `null` kalau hasilnya lewat, bukan memotongnya: kode yang
 * terpotong masih terlihat seperti kode yang sah tapi menunjuk sel yang beda.
 * Lebih baik satu penanda hilang (catatan prosanya tetap sampai) daripada satu
 * penanda mendarat di kotak yang salah.
 */
final class KodeSelRevisi
{
    public const PREFIKS = 'sel';

    /** Sama dengan `revisi_field.*` di `CalibrationController::reject()`. */
    public const PANJANG_MAKS = 64;

    /**
     * Kode buat satu sel, atau `null` kalau nggak bisa dibikin dengan jujur.
     */
    public static function buat(
        string $tahap,
        float $titikUkur,
        string $kolom,
        int $pembacaanKe,
    ): ?string {
        if ($tahap === '' || $kolom === '' || $pembacaanKe < 1) {
            return null;
        }

        // Titik dua itu pemisahnya — komponen yang mengandungnya bakal terurai
        // jadi sel yang beda.
        if (str_contains($tahap, ':') || str_contains($kolom, ':')) {
            return null;
        }

        $kode = sprintf(
            '%s:%s:%s:%s:%d',
            self::PREFIKS,
            $tahap,
            self::angka($titikUkur),
            $kolom,
            $pembacaanKe,
        );

        return strlen($kode) > self::PANJANG_MAKS ? null : $kode;
    }

    /** Kode ini menunjuk sel, bukan kolom biasa? */
    public static function adalahKodeSel(string $kode): bool
    {
        return str_starts_with($kode, self::PREFIKS.':');
    }

    /**
     * Urai balik. `null` = bukan kode sel, atau bentuknya rusak.
     *
     * @return array{tahap: string, titik_ukur: float, kolom: string, pembacaan_ke: int}|null
     */
    public static function urai(string $kode): ?array
    {
        $bagian = explode(':', $kode);

        if (count($bagian) !== 5 || $bagian[0] !== self::PREFIKS) {
            return null;
        }

        [, $tahap, $titik, $kolom, $ulang] = $bagian;

        if ($tahap === '' || $kolom === '' || ! is_numeric($titik) || ! ctype_digit($ulang)) {
            return null;
        }

        if ((int) $ulang < 1) {
            return null;
        }

        return [
            'tahap' => $tahap,
            'titik_ukur' => (float) $titik,
            'kolom' => $kolom,
            'pembacaan_ke' => (int) $ulang,
        ];
    }

    /**
     * `1412`, `7.01`, `-20` — bukan `1412.00000000`.
     *
     * Kolomnya `decimal(20,8)`, dan bentuk panjangnya makan 11 karakter dari
     * jatah 64 tanpa menambah satu pun informasi. Sisi HP mencocokkannya dengan
     * toleransi relatif (nilai yang sama bisa beda di digit terakhir sesudah
     * bolak-balik lewat JSON), jadi yang penting angkanya kebaca — bukan
     * teksnya sama persis.
     */
    private static function angka(float $nilai): string
    {
        $teks = rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.');

        return $teks === '' || $teks === '-' ? '0' : $teks;
    }
}
