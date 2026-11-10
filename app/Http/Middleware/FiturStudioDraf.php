<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sakelar simpan/muat ulang draf data acuan dari Studio. Mati -> 503
 * `belum_tersedia` (pola `FiturPelanggan`).
 *
 * Dipasang SESUDAH `auth:sanctum` dan `studio` di grup rute: pemanggil yang
 * bukan akun Studio tetap 401/403 (gerbang akses menang atas sakelar fitur),
 * jadi status 503 tidak membocorkan apa pun ke pihak luar.
 *
 * Sakelarnya dibaca dari `config('studio.draf_acuan_aktif')`, bukan `env()`
 * (aman `config:cache`).
 */
class FiturStudioDraf
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('studio.draf_acuan_aktif') !== true) {
            return response()->json([
                'kode' => 'belum_tersedia',
                'message' => 'Simpan draf data acuan belum tersedia di server ini.',
            ], 503);
        }

        return $next($request);
    }
}
