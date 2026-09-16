<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sakelar modul pelanggan. Mati → 503 `belum_tersedia`.
 *
 * Dipasang di grup, bukan di tiap rute: rute pelanggan baru otomatis ikut
 * terkunci tanpa ada yang perlu mengingat memasangnya. Yang sengaja DI LUAR
 * gerbang ini cuma `GET /app/status` — lihat `routes/api_pelanggan.php`.
 *
 * Kenapa 503, bukan 404: 404 bikin aplikasi mengira dirinya salah alamat dan
 * memunculkan galat teknis. 503 + `kode` yang stabil bisa diterjemahkan jadi
 * layar "belum tersedia" yang benar, dan `kode`-nya tidak berubah walau
 * kalimatnya diperbaiki (03-SDD §7).
 */
class FiturPelanggan
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('pelanggan.fitur') !== true) {
            return response()->json([
                'kode' => 'belum_tersedia',
                'message' => 'Fitur pelanggan belum tersedia di server ini.',
            ], 503);
        }

        return $next($request);
    }
}
