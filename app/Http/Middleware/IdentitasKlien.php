<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identitas klien API (ST-T-013, FR-STU-003 `docs/sidik-studio/02-SRS.md`).
 *
 * Sidik Studio mengirim `X-Klien: sidik-studio` dan `X-Versi-Klien: <versi
 * build>` di setiap request. Middleware ini:
 *
 *  1. Mencatat keduanya ke konteks log (`Context`) — tiap baris log selama
 *     request ini membawa `klien` & `versi_klien`. Nilainya dipangkas ke
 *     karakter aman dan 64 huruf: header milik pemanggil, bukan data kita.
 *  2. Menolak Studio yang terlalu lama dengan 426 `klien_kedaluwarsa` bila
 *     `X-Versi-Klien` < `config('studio.versi_minimum')`. HANYA untuk
 *     `X-Klien: sidik-studio`; request tanpa header (aplikasi lapangan, panel,
 *     skrip) tidak pernah kena. Minimum bawaan "0.0.0" = tidak pernah menolak.
 *
 * Bukan kontrol akses: header bisa dipalsukan siapa pun, jadi izin tetap dari
 * `role:` / `MatriksIzin` di server. Yang dibeli di sini cuma satu hal — Studio
 * lama berhenti dengan pesan yang jelas, bukan salah membaca kontrak API baru.
 *
 * Versi dibanding sebagai tiga angka `mayor.minor.patch`; akhiran pra-rilis
 * atau build (`-beta`, `+45`) diabaikan. Studio yang mengaku `sidik-studio`
 * tanpa versi yang terbaca dianggap 0.0.0 — ditolak begitu minimum dinaikkan,
 * karena Studio resmi selalu mengirimnya.
 */
class IdentitasKlien
{
    public const KLIEN_STUDIO = 'sidik-studio';

    public function handle(Request $request, Closure $next): Response
    {
        $klien = self::bersihkan($request->header('X-Klien'));
        $versi = self::bersihkan($request->header('X-Versi-Klien'));

        if ($klien !== null) {
            Context::add('klien', $klien);
        }

        if ($versi !== null) {
            Context::add('versi_klien', $versi);
        }

        if ($klien !== null && strtolower($klien) === self::KLIEN_STUDIO) {
            $minimum = (string) config('studio.versi_minimum', '0.0.0');

            if (self::bandingVersi($versi ?? '', $minimum) < 0) {
                return response()->json([
                    'kode' => 'klien_kedaluwarsa',
                    'message' => 'Versi Sidik Studio ini sudah terlalu lama untuk server. Perbarui aplikasinya, lalu masuk lagi.',
                    'versi_minimum' => $minimum,
                ], 426);
            }
        }

        return $next($request);
    }

    /** <0 bila $a < $b, 0 sama, >0 lebih baru. */
    public static function bandingVersi(string $a, string $b): int
    {
        return self::tigaAngka($a) <=> self::tigaAngka($b);
    }

    /** @return array{int, int, int} */
    private static function tigaAngka(string $versi): array
    {
        if (preg_match('/^\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/i', $versi, $m) !== 1) {
            return [0, 0, 0];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    private static function bersihkan(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $nilai = trim((string) preg_replace('/[^A-Za-z0-9._+\-]/', '', $nilai));

        return $nilai === '' ? null : substr($nilai, 0, 64);
    }
}
