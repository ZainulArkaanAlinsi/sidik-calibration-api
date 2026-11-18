<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Paginasi kursor sederhana untuk daftar yang diurut `id` MENURUN
 * (kontrak: `docs/perintah-frontend-super-admin.md` §3.4 dan §3.5).
 *
 * ## Kenapa bukan `cursorPaginate()` bawaan Laravel
 *
 * Kursor bawaannya membawa nama kolom dan nilainya di dalam token, dan
 * `Cursor::parameter()` MELEMPAR kalau kolom yang diminta tidak ada di token.
 * Token yang valid base64-JSON tapi tidak lengkap (dari klien yang salah, atau
 * dibuat tangan) jadi `500`. Di sini token cuma membawa SATU bilangan bulat
 * (id terakhir yang sudah diterima), dibaca dengan pola yang ketat, dan yang
 * tidak cocok dijawab `422 errors.cursor` — tidak pernah `500`, tidak pernah
 * "diam-diam mulai dari awal" (klien yang menggulir sampai `ada_lagi` false
 * akan berputar selamanya kalau kursor rusak dibaca sebagai halaman pertama).
 *
 * Kunci id menurun stabil terhadap baris baru: yang masuk saat orang menggulir
 * punya id lebih besar dan tidak menyusup ke halaman berikutnya.
 */
final class KursorId
{
    public const LIMIT_BAWAAN = 20;

    public const LIMIT_MAKS = 50;

    /**
     * Baca `cursor` & `limit` dari query. Memvalidasi — panggil SESUDAH penjaga
     * kepemilikan baris di path (alasannya sama dengan `AlasanAksi`: validasi yang
     * mendahului 404 membuktikan baris lab lain itu ada).
     *
     * @return array{0: int|null, 1: int} [id terakhir yang sudah diterima, limit]
     */
    public static function baca(Request $request): array
    {
        $data = $request->validate(
            [
                'cursor' => ['sometimes', 'nullable', 'string', 'max:64'],
                'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::LIMIT_MAKS],
            ],
            [
                'limit.integer' => 'Limit harus bilangan bulat.',
                'limit.min' => 'Limit minimal 1.',
                'limit.max' => 'Limit maksimal '.self::LIMIT_MAKS.'.',
            ],
        );

        $setelah = null;

        if (filled($data['cursor'] ?? null)) {
            $setelah = self::decode($data['cursor']);

            if ($setelah === null) {
                throw ValidationException::withMessages([
                    'cursor' => 'Kursor tidak valid. Mulai lagi dari halaman pertama.',
                ]);
            }
        }

        return [$setelah, (int) ($data['limit'] ?? self::LIMIT_BAWAAN)];
    }

    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode('k1:'.$id), '+/', '-_'), '=');
    }

    /** `null` = bukan kursor buatan [encode()]. */
    public static function decode(string $kursor): ?int
    {
        $mentah = base64_decode(strtr($kursor, '-_', '+/'), true);

        if ($mentah === false || preg_match('/^k1:([1-9][0-9]{0,17})$/', $mentah, $cocok) !== 1) {
            return null;
        }

        return (int) $cocok[1];
    }

    /**
     * Ambil satu halaman: baris dengan `$kolom` < kursor, id menurun, `$limit` baris.
     *
     * Mengambil `$limit + 1` untuk tahu "masih ada lagi" tanpa `COUNT(*)`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  sudah disaring ke organisasi & induknya oleh pemanggil
     * @return array{0: Collection<int, TModel>, 1: string|null} [halaman, kursor halaman berikutnya]
     */
    public static function potong(Builder $query, ?int $setelahId, int $limit, string $kolom = 'id'): array
    {
        $baris = $query
            ->when($setelahId !== null, fn (Builder $q) => $q->where($kolom, '<', $setelahId))
            ->orderByDesc($kolom)
            ->limit($limit + 1)
            ->get();

        $adaLagi = $baris->count() > $limit;
        $halaman = $baris->take($limit)->values();

        return [$halaman, $adaLagi ? self::encode((int) $halaman->last()->getAttribute($kolom)) : null];
    }
}
