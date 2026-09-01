<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Satu pembacaan di kolom `standar`/`uut`: **angka biasa** — plus **objek empat
 * kotak** penunjukan stopwatch, kalau lembarnya memang dibaca per blok waktu.
 *
 * ## Kegagalan yang ditutup aturan ini
 *
 * Kolom `measurements.*.standar.*` dan `.uut.*` dipakai dua bentuk lembar yang
 * berbeda:
 *
 *  - **Ketiga alat suhu berpasangan** (Thermocouple, Termometer Gelas,
 *    Thermohygro) mengirim angka biasa — satu pembacaan, satu float.
 *  - **Timer/Stopwatch** mengirim `{jam, menit, detik, milidetik}`, karena
 *    begitulah stopwatch menampilkan waktu dan begitu pula empat kotak yang
 *    tercetak di lembar masternya (`J | M | S | 0.001S`).
 *
 * Aturannya dulu `numeric` saja. Objek empat kotak bukan angka, jadi bentuk
 * kedua **selalu ditolak 422** — kontrak yang ditulis sendiri di
 * `docs/perintah-frontend-waktu-frekuensi.md` §5 tidak pernah bisa menyimpan
 * satu lembar pun, dan jalur array di `CalibrationController::waktuKeMilidetik()`
 * jadi kode mati yang tidak pernah dijalankan siapa pun.
 *
 * Yang bikin ini mahal: gejalanya BUKAN error di server. Teknisi mengisi lembar
 * penuh di lokasi, menekan kirim, dan mendapat 422 tanpa tahu kotak mana yang
 * salah — karena tidak ada satu pun kotak yang salah.
 *
 * ## Yang diperiksa
 *
 * Bentuk angka: harus terhingga (`INF`/`NAN` ditolak — lihat [AngkaTerhingga]).
 *
 * Bentuk objek: kuncinya harus di dalam `jam`/`menit`/`detik`/`milidetik`,
 * minimal satu terisi, dan tiap yang terisi harus angka terhingga yang tidak
 * negatif. Kunci asing DITOLAK, bukan diabaikan: kunci salah ketik yang
 * dilewatkan diam-diam berarti satu kotak yang diisi teknisi hilang tanpa jejak,
 * dan waktunya meleset sebesar kotak itu.
 *
 * Batas atas tiap kotak sengaja TIDAK dipatok. Yang ditolak di sini cuma nilai
 * yang tidak punya arti numerik; batas fisik alatnya urusan profil
 * masing-masing — alasan yang sama dengan yang ditulis di [AngkaTerhingga].
 *
 * ## Kenapa bentuk objek harus DIIZINKAN, bukan selalu terbuka
 *
 * Kolom ini dipakai bersama tiga alat suhu berpasangan. Dibuka buat semuanya,
 * lembar Thermocouple yang mengirim `{jam, menit, detik, milidetik}` — entah
 * salah alat, entah bug klien — diterima **200** lalu pembacaannya dibuang
 * diam-diam: `titik` pulang kosong dan teknisi kehilangan seluruh lembarnya
 * tanpa satu pun pesan. Dulu bentuk itu ditolak 422 dengan alasan yang benar.
 *
 * Jadi `$bolehObjek` default `false`: yang membukanya cuma
 * [\App\Http\Requests\CalibrationRequest] setelah memastikan profil alatnya
 * memang `butuhBlokWaktu()`.
 */
class PenunjukanWaktu implements ValidationRule
{
    /** Keempat kotak seperti tercetak di lembar master (`J | M | S | 0.001S`). */
    public const KOTAK = ['jam', 'menit', 'detik', 'milidetik'];

    /**
     * @param  bool  $bolehObjek  lembar ini dibaca per blok waktu (Timer/Stopwatch)
     */
    public function __construct(private readonly bool $bolehObjek = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_array($value)) {
            if (! $this->bolehObjek) {
                $fail('Kolom :attribute harus angka. Bentuk objek {jam, menit, detik, milidetik} '
                    .'cuma dipakai lembar Timer/Stopwatch.');

                return;
            }

            $this->periksaObjek($attribute, $value, $fail);

            return;
        }

        if (! is_numeric($value)) {
            $fail('Kolom :attribute harus angka, atau objek {jam, menit, detik, milidetik}.');

            return;
        }

        if (! is_finite((float) $value)) {
            $fail('Kolom :attribute berisi angka yang nggak terhingga (INF/NAN).');
        }
    }

    /**
     * @param  array<mixed, mixed>  $nilai
     */
    private function periksaObjek(string $attribute, array $nilai, Closure $fail): void
    {
        $asing = array_diff(array_keys($nilai), self::KOTAK);

        if ($asing !== []) {
            $fail(sprintf(
                'Kolom :attribute punya kotak yang nggak dikenal: %s. Yang dikenal cuma %s.',
                implode(', ', array_map('strval', $asing)),
                implode(', ', self::KOTAK),
            ));

            return;
        }

        $terisi = 0;

        foreach (self::KOTAK as $kotak) {
            $isi = $nilai[$kotak] ?? null;

            if ($isi === null || $isi === '') {
                continue;
            }

            if (! is_numeric($isi) || ! is_finite((float) $isi) || (float) $isi < 0) {
                $fail(sprintf(
                    'Kotak `%s` di :attribute harus angka terhingga yang nggak negatif.',
                    $kotak,
                ));

                return;
            }

            $terisi++;
        }

        if ($terisi === 0) {
            $fail('Kolom :attribute berbentuk objek tapi keempat kotaknya kosong.');
        }
    }
}
