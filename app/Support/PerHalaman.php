<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * `per_page` untuk daftar ber-paginasi (BUG-16, QA ronde 2-B).
 *
 * Enam daftar (`/equipments`, `/customers`, `/certificates`, `/orders`,
 * `/users`, `/rooms`) dulu memanggil `paginate(15)` mentah dan MENGABAIKAN `per_page`
 * tanpa kabar -- klien yang minta 50 baris menerima 15 dan menyangka daftarnya
 * habis. Sekarang `per_page` dihormati dengan batas yang sama di semua daftar:
 * bilangan bulat 1..100, bawaan tetap 15 (klien yang tidak mengirimnya tidak
 * berubah). Di luar batas dijawab `422` pada `per_page`, tidak dijepit diam-diam
 * -- pola `GET /calibrations` (`DaftarSesiRequest`).
 *
 * Bentuk respons (`data`, `links`, `meta`) tidak berubah; `meta.per_page`
 * melaporkan angka yang dipakai, dan `withQueryString()` di pemanggil membawanya
 * ke tautan halaman berikutnya.
 */
final class PerHalaman
{
    public const BAWAAN = 15;

    public const MIN = 1;

    public const MAKS = 100;

    /** @return list<string> */
    public static function aturan(): array
    {
        return ['sometimes', 'nullable', 'integer', 'min:'.self::MIN, 'max:'.self::MAKS];
    }

    /** Validasi `per_page` lalu kembalikan jumlah baris per halaman (bawaan 15). */
    public static function dari(Request $request): int
    {
        $data = $request->validate(['per_page' => self::aturan()]);

        return (int) (($data['per_page'] ?? null) ?: self::BAWAAN);
    }
}
