<?php

namespace App\Casts;

use App\Support\PresisiPenuh;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast `uncertainty_calculations.type_b_components`: JSON seperti cast `array`,
 * tetapi kunci beku milik server (`PresisiPenuh::KUNCI`) TIDAK ikut terbaca.
 *
 * ## Kenapa di sini, bukan di tiap pembaca
 *
 * Kolom ini dibaca puluhan tempat (profil, validator, snapshot, resource, test)
 * yang semuanya menganggap isinya DAFTAR komponen/jejak. Kunci `presisi_penuh`
 * bukan komponen budget; kalau ia kelihatan, setiap pembaca yang menghitung
 * `count()`, menjumlah, atau meneruskan isi kolom ke klien harus ingat
 * menyaringnya — dan yang lupa tidak menghasilkan error, cuma satu komponen
 * budget "tambahan" (`FlowmeterSesiTest`: 9, bukan 8). Jadi hanya satu pintu
 * yang boleh melihatnya: `PresisiPenuh` lewat nilai mentah atribut
 * ([PresisiPenuh::petaBeku]).
 *
 * ## Simpan ulang tidak menghapus kunci
 *
 * Penulis yang membaca daftar bersih, mengubahnya, lalu menyimpan balik tidak
 * tahu kunci itu ada. [set] mengambilnya dari nilai mentah yang sedang melekat
 * di model dan menyisipkannya lagi, kecuali penulis menyerahkan kuncinya
 * sendiri (jalur simpan sesi, yang membekukan float SEBELUM dibulatkan) atau
 * menulis `null`.
 *
 * Selain itu bentuknya sama dengan cast `array` bawaan: nilai yang bukan array
 * (string, angka) dienkode apa adanya, dan nilai yang tidak bisa dienkode
 * (INF/NaN) melempar `JsonEncodingException` yang sama.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class JejakTypeB implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $isi = is_string($value) ? Json::decode($value) : $value;

        return is_array($isi) ? PresisiPenuh::tanpaKunci($isi) : $isi;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && ! array_key_exists(PresisiPenuh::KUNCI, $value)) {
            $beku = PresisiPenuh::petaBeku($attributes[$key] ?? null);

            if ($beku !== null) {
                $value[PresisiPenuh::KUNCI] = $beku;
            }
        }

        $json = Json::encode($value);

        if ($json === false) {
            throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
        }

        return $json;
    }
}
