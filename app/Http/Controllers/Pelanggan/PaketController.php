<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\Order;
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $paket = (new LingkupData($konteks))->paket()
            // Bawaan: yang masih berjalan. Paket selesai tetap bisa dibuka lewat
            // `?selesai=1` — riwayatnya berguna, tapi bukan yang dicari tiap hari.
            ->when(
                $request->boolean('selesai'),
                fn ($q) => $q->where('status', Order::STATUS_SELESAI),
                fn ($q) => $q->where('status', '!=', Order::STATUS_SELESAI),
            )
            ->with($this->relasi())
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
        $baris = (new LingkupData($konteks))->paket()
            ->whereKey($paket)
            ->with($this->relasi())
            ->firstOrFail();

        return response()->json([
            'data' => (new PaketPelangganResource($baris))->resolve($request),
            'garis_waktu' => $this->tahap->garisWaktu(
                $this->tahap->untukPaket($baris->items),
                untukPelanggan: true,
            ),
        ]);
    }

    /**
     * Relasi yang dibutuhkan `TahapPaket` + resource pelanggan. Sengaja TANPA
     * `items.teknisi`: nama teknisi bukan urusan pelanggan, dan yang tidak
     * dimuat tidak mungkin ikut bocor lewat resource yang nanti diubah orang.
     *
     * @return array<int|string, mixed>
     */
    private function relasi(): array
    {
        return [
            'items' => fn ($q) => $q->select(['id', 'order_id', 'equipment_id', 'tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada']),
            'items.equipment' => fn ($q) => $q->withTrashed()->select(['id', 'nama_alat', 'merk', 'serial_number']),
            'items.sesiTerakhir',
            'items.sesiTerakhir.certificate',
        ];
    }
}
