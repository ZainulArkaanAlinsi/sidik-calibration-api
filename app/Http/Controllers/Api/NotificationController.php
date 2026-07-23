<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Halaman notifikasi (spesifikasi poin 4 & 6).
 *
 * Notifikasi dipindah dari navbar bawah ke ikon di atas layar, dan waktu
 * diketuk buka halaman sendiri — makanya butuh daftar berhalaman + penghitung
 * yang belum dibaca, bukan cuma lonceng.
 *
 * Semua endpoint di sini KETAT ke notifikasi milik pengguna yang login. Nggak
 * ada parameter buat lihat punya orang lain, dan `{notification}` diambil dari
 * relasi user, bukan dari tabel global — jadi tebak-tebakan UUID nggak nemu apa-apa.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifikasi = $request->user()
            ->notifications()
            // `belum_dibaca=true` buat tab "Belum dibaca" di mobile.
            ->when($request->boolean('belum_dibaca'), fn ($query) => $query->whereNull('read_at'))
            ->paginate(20)
            ->withQueryString();

        return NotificationResource::collection($notifikasi)
            ->additional(['meta' => ['belum_dibaca' => $this->jumlahBelumDibaca($request)]]);
    }

    /** Buat badge angka di ikon notifikasi — dipanggil sering, jadi ringan. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['belum_dibaca' => $this->jumlahBelumDibaca($request)]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $baris = $request->user()->notifications()->findOrFail($notification);

        // `markAsRead()` udah aman dipanggil ulang — nggak nimpa waktu baca
        // pertama kalau udah kebaca.
        $baris->markAsRead();

        return response()->json(['data' => new NotificationResource($baris->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $jumlah = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['meta' => ['ditandai' => $jumlah, 'belum_dibaca' => 0]]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->delete();

        return response()->json(['meta' => ['belum_dibaca' => $this->jumlahBelumDibaca($request)]]);
    }

    private function jumlahBelumDibaca(Request $request): int
    {
        return $request->user()->unreadNotifications()->count();
    }
}
