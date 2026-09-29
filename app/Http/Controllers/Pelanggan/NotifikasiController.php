<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\NotifikasiPelangganResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/notifikasi` — kotak masuk akun pelanggan.
 *
 * Isinya diikat ke PENGGUNA, bukan ke perusahaan: dua PIC satu perusahaan
 * masing-masing punya tanda "sudah dibaca" sendiri. Satu-satunya saringan
 * yang dibutuhkan jadi `$request->user()->notifications()` — tidak ada ID
 * perusahaan yang bisa disebut di sini sama sekali.
 *
 * Controller & resource sendiri, bukan meminjam versi internal: rute
 * pelanggan dan internal sengaja tidak berbagi apa pun yang membentuk respons
 * (AGENTS.md §Modul Pelanggan poin 1–2).
 */
class NotifikasiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'belum_dibaca' => ['sometimes', 'boolean'],
        ]);

        $halaman = $request->user()
            ->notifications()
            ->when($request->boolean('belum_dibaca'), fn ($q) => $q->whereNull('read_at'))
            ->paginate(20);

        return response()->json([
            'data' => NotifikasiPelangganResource::collection($halaman->items())->resolve($request),
            'meta' => [
                'total' => $halaman->total(),
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'belum_dibaca' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function jumlah(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['belum_dibaca' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function dibaca(Request $request, string $notifikasi): JsonResponse
    {
        $baris = $request->user()->notifications()->findOrFail($notifikasi);
        $baris->markAsRead();

        return response()->json(['data' => (new NotifikasiPelangganResource($baris->fresh()))->resolve($request)]);
    }

    public function dibacaSemua(Request $request): JsonResponse
    {
        $jumlah = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['meta' => ['ditandai' => $jumlah, 'belum_dibaca' => 0]]);
    }
}
