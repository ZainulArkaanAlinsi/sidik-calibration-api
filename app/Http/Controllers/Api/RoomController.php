<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Master data ruangan lab — nulisnya admin doang (dijaga `role:admin` di routes). */
class RoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $rooms = Room::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('search'), function ($query) use ($request) {
                $cari = '%'.$request->string('search').'%';

                // Dicari pakai nama ATAU kode: orang lab hafalnya "R-01",
                // sementara di layar yang kebaca "Ruang Kalibrasi Massa".
                $query->where(fn ($q) => $q->where('nama', 'like', $cari)->orWhere('kode', 'like', $cari));
            })
            // Dropdown "pilih ruangan" di mobile cuma boleh nawarin yang aktif;
            // layar master data tetap butuh lihat yang nonaktif juga.
            ->when($request->boolean('hanya_aktif'), fn ($query) => $query->aktif())
            ->orderBy('kode')
            ->paginate(15)
            ->withQueryString();

        return RoomResource::collection($rooms);
    }

    public function store(RoomRequest $request): JsonResponse
    {
        $room = Room::create([
            ...$request->validated(),
            'organization_id' => $request->user()->organization_id,
        ]);

        // `aktif` nilainya dari default kolom DB, bukan dari payload — tanpa
        // refresh, model yang baru dibikin balikin null dan mobile ngira
        // ruangannya nonaktif begitu selesai disimpan.
        return response()->json(['data' => new RoomResource($room->refresh())], 201);
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $room);

        return response()->json(['data' => new RoomResource($room)]);
    }

    public function update(RoomRequest $request, Room $room): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $room);

        $room->update($request->validated());

        return response()->json(['data' => new RoomResource($room->fresh())]);
    }

    public function destroy(Request $request, Room $room): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $room);

        $room->delete();

        return response()->json(['message' => 'Ruangan dihapus.']);
    }

    private function pastikanSatuOrganisasi(Request $request, Room $room): void
    {
        abort_if($room->organization_id !== $request->user()->organization_id, 404);
    }
}
