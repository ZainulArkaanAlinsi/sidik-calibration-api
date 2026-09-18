<?php

namespace App\Http\Middleware;

use App\Support\Pelanggan\Konteks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `peran:pic_utama` — gerbang aksi yang cuma boleh PIC utama (03-SDD §3.4).
 *
 * Middleware, bukan `if ($konteks->picUtama())` di controller, dengan alasan
 * yang sama seperti `role:` di app internal: aturannya terbaca dari daftar rute,
 * jadi bisa dijawab endpoint izin dan disapu test tanpa ada yang menuliskannya
 * dua kali. Pemeriksaan yang hidup di badan controller tidak bisa disapu, dan
 * yang lupa memasangnya tidak memerahkan apa pun.
 *
 * Bergantung pada `KonteksPerusahaan` yang jalan LEBIH DULU — tanpa konteks,
 * pertanyaan "perannya apa" tidak punya jawaban. Kalau urutannya keliru, yang
 * terjadi 500, bukan gerbang yang diam-diam terbuka.
 */
class PeranAnggota
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $konteks = $request->attributes->get('konteks_pelanggan');

        if (! $konteks instanceof Konteks) {
            abort(500, 'Middleware `peran:` dipasang tanpa `perusahaan` di depannya.');
        }

        if (in_array($konteks->peran, $peran, true)) {
            return $next($request);
        }

        return response()->json([
            'kode' => 'bukan_pic_utama',
            'message' => 'Hanya PIC utama perusahaan yang bisa melakukan ini.',
        ], 403);
    }
}
