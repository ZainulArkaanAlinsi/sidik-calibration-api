<?php

namespace App\Services\Studio;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * K-50-12 — Sidik Studio dipakai SATU akun (`config('studio.akun')`, env
 * `STUDIO_AKUN`). Satu-satunya tempat yang menjawab "siapa itu akun Studio" dan
 * "token ini token Studio?", dipakai login, gerbang rute, dan aturan sel
 * terkunci — supaya tiga tempat itu tidak bisa berbeda pendapat.
 */
final class AkunStudio
{
    public const ABILITY = 'studio';

    /** Email akun Studio (huruf kecil), atau null bila belum disetel = Studio tertutup. */
    public static function email(): ?string
    {
        $email = strtolower(trim((string) config('studio.akun', '')));

        return $email === '' ? null : $email;
    }

    /**
     * Akun ini BOLEH memegang token Studio? Email cocok dengan `STUDIO_AKUN`,
     * akun aktif, dan orang lab (bukan pelanggan). `STUDIO_AKUN` kosong → tidak
     * seorang pun.
     */
    public static function cocok(?User $user): bool
    {
        $email = self::email();

        return $email !== null
            && $user !== null
            && $user->status === User::STATUS_AKTIF
            && in_array($user->role, User::rolesInternal(), true)
            && strtolower((string) $user->email) === $email;
    }

    /**
     * Token Sanctum yang SUNGGUHAN memuat ability `studio`.
     *
     * Wildcard `['*']` (token lab lama) dan `TransientToken` (sesi panel)
     * sengaja TIDAK dihitung walau `can('studio')` meloloskan keduanya — token
     * lab tidak pernah boleh membuka Studio. Pembedanya: token Studio TIDAK
     * memuat `internal` (wildcard memuat semuanya), sehingga token yang
     * dikeluarkan login Studio tidak pernah ikut membuka rute lab.
     */
    public static function tokenStudio(mixed $token): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return $token->can(self::ABILITY) && ! $token->can('internal');
    }

    /** Akun Studio yang sedang memegang token Studio — dasar "hak penuh" (sel terkunci, dst.). */
    public static function hakPenuh(?User $user): bool
    {
        return $user !== null
            && self::cocok($user)
            && self::tokenStudio($user->currentAccessToken());
    }
}
