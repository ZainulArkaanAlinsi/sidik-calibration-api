<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StandardResource;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Alat standar/acuan milik lab. Dipakai buat dropdown "Standar Acuan" di layar
 * kalibrasi — `standard_id` wajib waktu bikin sesi.
 *
 * Baca: semua role. Nulis belum ada — standar masih diisi lewat seeder.
 */
class StandardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $standards = Standard::query()
            ->where('organization_id', $request->user()->organization_id)
            // Yang kadaluarsa TETAP dikirim, cuma ditandain `masih_berlaku: false`.
            // Kalau disembunyiin, teknisi yang lagi nyari standar yang biasa dia
            // pakai bakal ngira datanya ilang — padahal cuma perlu dikalibrasi ulang.
            ->when($request->boolean('berlaku_saja'), fn ($query) => $query->where(
                fn ($q) => $q->whereNull('berlaku_sampai')->orWhereDate('berlaku_sampai', '>=', now()),
            ))
            ->orderBy('nama')
            ->get();

        return StandardResource::collection($standards);
    }

    public function show(Request $request, Standard $standard): JsonResponse
    {
        // Jaring pengaman multi-tenant: PT lain nggak boleh baca standar kita.
        abort_if($standard->organization_id !== $request->user()->organization_id, 404);

        return response()->json(['data' => new StandardResource($standard)]);
    }
}
