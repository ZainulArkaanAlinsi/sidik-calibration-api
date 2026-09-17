<?php

namespace App\Services\Pelanggan;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Terbitkan token aplikasi pelanggan — ability + masa berlaku (REQ-AUTH-08).
 *
 * Satu tempat, dan itu yang penting: kalau tiap endpoint memanggil
 * `createToken()` sendiri, cukup satu yang lupa `expires_at` buat melahirkan
 * token pelanggan abadi — dan itu tidak memunculkan error di mana pun.
 *
 * ## Kenapa `expires_at` per token, bukan `sanctum.expiration`
 *
 * `config('sanctum.expiration')` berlaku GLOBAL, jadi menyetelnya ikut
 * mematikan token teknisi di lapangan — yang justru 03-SDD §3.1 minta jangan.
 * Sanctum memeriksa `expires_at` per baris di `Guard.php`, jadi dua umur yang
 * berbeda bisa hidup berdampingan di satu tabel.
 *
 * Batas idle 30 hari BUKAN di sini: dia dihitung dari `last_used_at` tiap
 * request, dan tempatnya di middleware `PastikanAplikasi`.
 */
class TokenPelanggan
{
    /** REQ-AUTH-08: umur maksimum satu token pelanggan. */
    public const BERLAKU_HARI = 90;

    /** Akun yang sudah ditautkan admin ke sebuah perusahaan. */
    public const ABILITY_PENUH = 'pelanggan';

    /**
     * Akun yang emailnya sudah terverifikasi tapi belum disetujui admin
     * (REQ-AUTH-03). Boleh masuk, cuma buat membaca status pengajuannya
     * sendiri — endpoint data membalas 403 `akun_belum_diverifikasi`.
     */
    public const ABILITY_MENUNGGU = 'pelanggan:menunggu';

    public function terbitkan(User $user, string $namaPerangkat): NewAccessToken
    {
        return $user->createToken(
            $this->namaAman($namaPerangkat),
            [$this->ability($user)],
            now()->addDays(self::BERLAKU_HARI),
        );
    }

    public function ability(User $user): string
    {
        return $user->status === User::STATUS_AKTIF
            ? self::ABILITY_PENUH
            : self::ABILITY_MENUNGGU;
    }

    /**
     * Nama perangkat dipangkas, dan alasannya bukan kerapian.
     *
     * `personal_access_tokens.name` itu `varchar(255)`. Di MySQL strict mode
     * nama yang lebih panjang bikin INSERT-nya gagal — jadi orang yang HP-nya
     * bernama panjang tidak bisa masuk sama sekali, dan yang terbaca di log
     * cuma galat SQL.
     */
    private function namaAman(string $nama): string
    {
        $nama = trim($nama);

        return $nama === '' ? 'Perangkat pelanggan' : mb_substr($nama, 0, 120);
    }
}
