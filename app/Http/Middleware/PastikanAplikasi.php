<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token dari aplikasi mana? — `aplikasi:internal` atau `aplikasi:pelanggan`.
 *
 * Dua aplikasi Android memakai satu backend. Tanpa gerbang ini, token yang
 * dikeluarkan buat satu aplikasi bisa dipakai menembak API aplikasi yang lain:
 * `role:` menahan sebagian, tapi role bukan jawaban buat "token ini
 * dikeluarkan buat siapa".
 *
 * ## Kenapa token yang TIDAK ADA diloloskan
 *
 * `$request->user()->currentAccessToken()` bisa memulangkan tiga hal, dan
 * ketiganya harus ditangani sadar:
 *
 * 1. **`PersonalAccessToken`** — Bearer token sungguhan. Ini yang dijaga.
 * 2. **`TransientToken`** — Sanctum membungkusnya begitu user datang dari
 *    guard `web` (lihat `Guard::__invoke`). Di produksi itu artinya sesi
 *    Filament, dan `User::canAccessPanel()` sudah menuntut admin aktif.
 * 3. **`null`** — user dipasang langsung ke guard `sanctum` tanpa lewat
 *    Sanctum. Di repo ini cuma terjadi di test (`actingAs($u, 'sanctum')`,
 *    41 pemanggilan di 11 berkas).
 *
 * Nomor 2 & 3 diloloskan, dan itu BUKAN lubang: di produksi `api/*` cuma bisa
 * dicapai lewat Bearer token atau sesi Filament, dan pelanggan nggak punya
 * jalan mendapat sesi web sama sekali. Yang benar-benar dijaga gerbang ini
 * Bearer token dengan ability yang salah — dan itu yang diuji.
 *
 * ## Token lama `['*']`, bukan "tanpa ability"
 *
 * 03-SDD §3.1 menulis token internal lama "nggak punya ability". Kenyataannya
 * punya: `HasApiTokens::createToken()` default-nya `['*']`, dan
 * `PersonalAccessToken::can()` meloloskan wildcard apa pun. Jadi token teknisi
 * yang sudah beredar lolos `aplikasi:internal` otomatis — nggak ada yang perlu
 * ditolong masa transisi.
 *
 * Yang justru jadi soal: token `['*']` juga lolos `aplikasi:pelanggan`, jadi
 * lingkupnya kelewat lebar. Di situlah `pelanggan.cutoff_token_lama` dipakai —
 * sesudah tanggal itu token wildcard DITOLAK di sini, memaksa semua orang
 * pindah ke token yang lingkupnya tepat. Kosong = tanpa batas.
 */
class PastikanAplikasi
{
    /** Ability yang sah buat tiap aplikasi. */
    private const ABILITY = [
        'internal' => ['internal'],
        // Token akun yang belum diverifikasi admin tetap lolos gerbang ini —
        // yang membatasi dia ke `/saya` + keluar gerbang terpisah. Kalau
        // ditolak di sini, layar "menunggu verifikasi" nggak punya cara
        // membaca statusnya sendiri.
        'pelanggan' => ['pelanggan', 'pelanggan:menunggu'],
    ];

    /**
     * Idle yang bikin token pelanggan mati (REQ-AUTH-08).
     *
     * SENGAJA nggak berlaku buat internal: 03-SDD §3.1 minta teknisi di
     * lapangan nggak tiba-tiba ter-logout, dan `sanctum.expiration` global
     * dibiarkan `null` dengan alasan yang sama.
     */
    private const IDLE_HARI_PELANGGAN = 30;

    public function handle(Request $request, Closure $next, string $aplikasi): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || $token instanceof TransientToken) {
            return $next($request);
        }

        if (! $this->abilityCocok($token, $aplikasi)) {
            return $this->tolak(
                'bukan_aplikasi_ini',
                $aplikasi === 'internal'
                    ? 'Token ini bukan buat aplikasi internal. Masuk lewat aplikasi SIDIK Pelanggan.'
                    : 'Token ini bukan buat aplikasi SIDIK Pelanggan.',
                403,
            );
        }

        if ($this->wildcard($token) && $this->lewatCutoff()) {
            return $this->tolak(
                'token_lama',
                'Sesi lama sudah tidak berlaku. Silakan masuk lagi.',
                401,
            );
        }

        if ($aplikasi === 'pelanggan' && $this->terlaluLamaMenganggur($token)) {
            return $this->tolak(
                'sesi_berakhir',
                'Sesi Anda berakhir. Silakan masuk lagi. Draf Anda tetap tersimpan.',
                401,
            );
        }

        return $next($request);
    }

    private function abilityCocok(PersonalAccessToken $token, string $aplikasi): bool
    {
        foreach (self::ABILITY[$aplikasi] ?? [] as $ability) {
            if ($token->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Token berlingkup `['*']` — sah, tapi kelewat lebar.
     *
     * Diperiksa ke daftar abilitynya LANGSUNG, bukan lewat `can()`: `can()`
     * meloloskan wildcard, jadi memakainya di sini bikin pemeriksaannya selalu
     * memulangkan hal yang sama dengan pemeriksaan di atas.
     */
    private function wildcard(PersonalAccessToken $token): bool
    {
        return in_array('*', (array) $token->abilities, true);
    }

    private function lewatCutoff(): bool
    {
        $cutoff = config('pelanggan.cutoff_token_lama');

        if (blank($cutoff)) {
            return false;
        }

        return Carbon::parse((string) $cutoff)->endOfDay()->isPast();
    }

    /**
     * `last_used_at` null = token belum pernah dipakai sejak dibuat, jadi yang
     * dihitung umurnya sejak `created_at`. Tanpa cadangan itu, token yang
     * dibuat setahun lalu dan belum pernah dipakai dianggap segar.
     */
    private function terlaluLamaMenganggur(PersonalAccessToken $token): bool
    {
        $terakhir = $token->last_used_at ?? $token->created_at;

        return $terakhir !== null
            && $terakhir->lt(now()->subDays(self::IDLE_HARI_PELANGGAN));
    }

    private function tolak(string $kode, string $pesan, int $status): Response
    {
        return response()->json(['kode' => $kode, 'message' => $pesan], $status);
    }
}
