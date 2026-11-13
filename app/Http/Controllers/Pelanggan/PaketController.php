<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\Order;
use App\Services\Pencarian\PolaCari;
use App\Services\TahapPaket;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/paket` — pelacakan order dari sisi pelanggan ("alat saya sudah sampai
 * mana?"). Tahapnya diturunkan oleh `TahapPaket` yang SAMA dengan layar lab;
 * cuma labelnya yang versi pelanggan.
 */
class PaketController extends Controller
{
    public function __construct(private readonly TahapPaket $tahap) {}

    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate([
            'selesai' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $lingkup = new LingkupData($konteks);
        $paket = $lingkup->paket()
            // Bawaan: yang masih berjalan. Paket selesai tetap bisa dibuka lewat
            // `?selesai=1` — riwayatnya berguna, tapi bukan yang dicari tiap hari.
            ->when(
                $request->boolean('selesai'),
                fn ($q) => $q->where('status', Order::STATUS_SELESAI),
                fn ($q) => $q->where('status', '!=', Order::STATUS_SELESAI),
            )
            // `?q=` mencari NOMOR order. Di atas himpunan `LingkupData::paket()`
            // (sudah dikunci ke perusahaan pemanggil), bukan menggantikannya.
            // Longgar soal pemisah (`PolaCari`): `ORD-2026-11-0007` menemukan
            // `ORD/2026/11/0007`. Kata yang cuma tanda baca (`%`, `-`) tidak
            // punya pola, dan jawabannya daftar KOSONG, bukan daftar utuh.
            ->when(filled($data['q'] ?? null), function ($q) use ($data): void {
                $kata = PolaCari::like($data['q']);

                if ($kata === null) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->where('nomor', 'like', $kata);
            })
            ->with($lingkup->relasiPaket())
            ->orderByDesc('tanggal_masuk')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($paket->items())
                ->map(fn (Order $o) => (new PaketPelangganResource($o))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $paket->total(),
                'per_page' => $paket->perPage(),
                'current_page' => $paket->currentPage(),
                'last_page' => $paket->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Konteks $konteks, string $paket): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $baris = $lingkup->paket()
            ->whereKey($paket)
            ->with($lingkup->relasiPaket())
            ->firstOrFail();

        return response()->json([
            'data' => (new PaketPelangganResource($baris))->resolve($request),
            'garis_waktu' => $this->tahap->garisWaktu(
                $this->tahap->untukPaket($baris->items),
                untukPelanggan: true,
            ),
        ]);
    }

    // Relasi eager-load (termasuk penyembunyian item beralat pelanggan lain)
    // ada di `LingkupData::relasiPaket()` -- satu tempat, dipakai juga `/beranda`.
}
