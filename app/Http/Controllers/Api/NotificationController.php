<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Notifikasi milik pemanggil. Nggak ada endpoint "notifikasi orang lain" —
 * relasi `notifications()` udah kekunci ke user dari token, jadi nggak mungkin
 * bocor lintas akun maupun lintas organisasi.
 *
 * Polling aja cukup (mobile refresh waktu app dibuka) — belum perlu FCM.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifikasi = $request->user()
            ->notifications()
            // `?belum_dibaca=1` buat badge angka di ikon lonceng.
            ->when($request->boolean('belum_dibaca'), fn ($query) => $query->whereNull('read_at'))
            ->paginate(20)
            ->withQueryString();

        return NotificationResource::collection($notifikasi)->additional([
            // Selalu ikut, nggak peduli lagi difilter atau nggak — badge-nya
            // nggak boleh ikut berubah cuma gara-gara halaman yang lagi dibuka.
            'meta_tambahan' => [
                'belum_dibaca' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /** Tandai satu notifikasi kebaca. Idempoten — dipanggil ulang nggak masalah. */
    public function baca(Request $request, string $id): JsonResponse
    {
        $notifikasi = $request->user()->notifications()->find($id);

        // 404, bukan 403: notifikasi orang lain nggak perlu dikonfirmasi ada.
        abort_if($notifikasi === null, 404);

        $notifikasi->markAsRead();

        return response()->json(['data' => new NotificationResource($notifikasi->fresh())]);
    }

    /** Tandai semua kebaca — tombol "bersihkan" di layar notifikasi. */
    public function bacaSemua(Request $request): JsonResponse
    {
        $jumlah = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['ditandai_dibaca' => $jumlah]]);
    }
}
