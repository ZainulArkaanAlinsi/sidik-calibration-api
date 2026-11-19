<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\KonflikDataTerhapus;
use App\Services\PenjagaOrganisasi;
use App\Support\PerHalaman;
use Illuminate\Database\UniqueConstraintViolationException;
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
            // `per_page` 1..100 (bawaan 15), 422 di luar batas -- sama dengan lima daftar
            // lain (`PerHalaman`). Dulu diabaikan (dan klien lama menulis begitu di komentarnya).
            ->paginate(PerHalaman::dari($request))
            ->withQueryString();

        return RoomResource::collection($rooms);
    }

    public function store(RoomRequest $request): JsonResponse
    {
        try {
            $room = Room::create([
                ...$request->validated(),
                'organization_id' => $request->user()->organization_id,
            ]);
        } catch (UniqueConstraintViolationException $galat) {
            // Balapan: dua POST dengan kode sama lolos validasi bersamaan, dan
            // indeks unik yang memutuskan. 422 yang sama dengan validasi.
            KonflikDataTerhapus::untukRuangan()->terjemahkan(
                $galat,
                $request->user()->organization_id,
                ['kode' => $request->input('kode')],
            );
        }

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

        try {
            $room->update($request->validated());
        } catch (UniqueConstraintViolationException $galat) {
            KonflikDataTerhapus::untukRuangan()->terjemahkan(
                $galat,
                $request->user()->organization_id,
                ['kode' => $request->input('kode')],
            );
        }

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
        // Diteruskan ke PenjagaOrganisasi: satu tempat yang memutuskan dan
        // satu tempat yang MENCATAT percobaannya. Perilaku dari luar identik
        // (404 yang sama); yang ditambahkan cuma jejaknya di audit_logs.
        PenjagaOrganisasi::pastikanSatu($request, $room);
    }
}
