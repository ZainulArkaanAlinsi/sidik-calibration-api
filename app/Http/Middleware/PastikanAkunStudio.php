<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Studio\AkunStudio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang SEMUA rute `/api/studio/*` — alias `studio` (K-50-12).
 *
 * Tiga syarat, semuanya harus lolos, dan diperiksa DI SERVER tiap request:
 *
 *  1. Token Sanctum sungguhan dengan ability `studio` (bukan wildcard, bukan
 *     sesi panel, bukan tanpa token). Token lab (`internal`) → 403.
 *  2. Email pemilik token == `STUDIO_AKUN` sekarang. `STUDIO_AKUN` diganti =
 *     token lama mati di request berikutnya; kosong = Studio tertutup total.
 *  3. Akun aktif (nonaktif → 401 + semua token dicabut, pola `role:`).
 *
 * Sebaliknya token `studio` TIDAK lolos `aplikasi:internal`, jadi tidak bisa
 * dipakai di rute lab. Menyembunyikan tombol di klien bukan kontrol akses;
 * ini kontrolnya.
 */
class PastikanAkunStudio
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->tolak('bukan_akun_studio');
        }

        if ($user->status !== User::STATUS_AKTIF) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Akun kamu tidak aktif. Hubungi admin lab.',
                'kode' => 'akun_nonaktif',
            ], 401);
        }

        if (! AkunStudio::tokenStudio($user->currentAccessToken())) {
            return $this->tolak('bukan_token_studio');
        }

        if (! AkunStudio::cocok($user)) {
            return $this->tolak(AkunStudio::email() === null ? 'studio_tertutup' : 'bukan_akun_studio');
        }

        return $next($request);
    }

    private function tolak(string $kode): Response
    {
        return response()->json([
            'kode' => $kode,
            'message' => 'Sidik Studio hanya untuk akun Studio. Masuk lewat aplikasi Sidik Studio dengan akun itu.',
        ], 403);
    }
}
