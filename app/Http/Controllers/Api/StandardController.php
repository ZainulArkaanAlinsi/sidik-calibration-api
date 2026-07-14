<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StandardRequest;
use App\Http\Resources\StandardResource;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Alat standar/acuan milik lab. Dipakai buat dropdown "Standar Acuan" di layar
 * kalibrasi — `standard_id` wajib waktu bikin sesi.
 *
 * Baca: semua role. Nulis: admin doang (dijaga `role:admin` di routes) — sama
 * kayak master data lain, karena salah ngetik ketidakpastian standar bikin SEMUA
 * sertifikat yang pakai standar itu ikut salah.
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
        $this->pastikanSatuOrganisasi($request, $standard);

        return response()->json(['data' => new StandardResource($standard)]);
    }

    public function store(StandardRequest $request): JsonResponse
    {
        $standard = Standard::create([
            ...$request->validated(),
            'organization_id' => $request->user()->organization_id,
        ]);

        return response()->json(['data' => new StandardResource($standard)], 201);
    }

    public function update(StandardRequest $request, Standard $standard): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $standard);

        // Sengaja NGGAK ngitung ulang sesi kalibrasi yang udah pakai standar ini.
        // Angka ketidakpastian disimpen di `uncertainty_calculations` waktu sesi
        // dibikin, dan itu memang harus beku — sertifikat yang udah dipegang
        // pelanggan nggak boleh berubah cuma gara-gara standarnya dikalibrasi ulang.
        $standard->update($request->validated());

        return response()->json(['data' => new StandardResource($standard->fresh())]);
    }

    public function destroy(Request $request, Standard $standard): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $standard);

        // Soft delete: standar ilang dari dropdown, tapi barisnya tetap ada.
        // Sesi kalibrasi lama masih nunjuk ke sini lewat `standard_id`, dan
        // ketertelusurannya wajib bisa ditelusuri asesor bertahun-tahun ke depan.
        // Relasi `CalibrationSession::standard()` sengaja pakai `withTrashed()`
        // supaya riwayatnya nggak berubah jadi null begitu standarnya dipensiunin.
        $standard->delete();

        return response()->json(['message' => 'Standar acuan dihapus.']);
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh baca/ubah standar kita. */
    private function pastikanSatuOrganisasi(Request $request, Standard $standard): void
    {
        abort_if($standard->organization_id !== $request->user()->organization_id, 404);
    }
}
