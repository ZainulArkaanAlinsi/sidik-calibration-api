<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalibrationMethodResource;
use App\Models\CalibrationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Master data Instruksi Kerja kalibrasi — "Calibration Method" di sertifikat
 * (spesifikasi poin 11 & 12B).
 *
 * Bacanya semua role (admin butuh buat dropdown, viewer buat baca), nulisnya
 * admin doang: salah ketik nomor IK di sini bikin SEMUA sertifikat yang nunjuk
 * ke sana ikut salah.
 *
 * Ganti revisi = BIKIN BARIS BARU, jangan edit kodenya. Sertifikat lama harus
 * tetap nunjuk revisi yang berlaku waktu kalibrasinya dikerjain.
 */
class CalibrationMethodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $metode = CalibrationMethod::query()
            ->with('category')
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->boolean('aktif_saja'), fn ($query) => $query->where('aktif', true))
            ->when(
                $request->filled('equipment_category_id'),
                fn ($query) => $query->where('equipment_category_id', $request->integer('equipment_category_id')),
            )
            ->orderBy('kode')
            ->get();

        return CalibrationMethodResource::collection($metode);
    }

    public function show(Request $request, CalibrationMethod $calibrationMethod): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibrationMethod);

        return response()->json([
            'data' => new CalibrationMethodResource($calibrationMethod->load('category')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $metode = CalibrationMethod::create([
            ...$this->validasi($request),
            'organization_id' => $request->user()->organization_id,
        ]);

        return response()->json([
            'data' => new CalibrationMethodResource($metode->load('category')),
        ], 201);
    }

    public function update(Request $request, CalibrationMethod $calibrationMethod): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibrationMethod);

        $calibrationMethod->update($this->validasi($request, $calibrationMethod));

        return response()->json([
            'data' => new CalibrationMethodResource($calibrationMethod->fresh()->load('category')),
        ]);
    }

    public function destroy(Request $request, CalibrationMethod $calibrationMethod): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibrationMethod);

        // Soft delete — sesi & sertifikat lama tetap bisa nunjuk ke sini lewat
        // relasi withTrashed(). Kalau di-hard delete, "Calibration Method" di
        // sertifikat tahun lalu langsung jadi kosong.
        $calibrationMethod->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validasi(Request $request, ?CalibrationMethod $metode = null): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate([
            'kode' => [
                $metode ? 'sometimes' : 'required',
                'string', 'max:100',
                Rule::unique('calibration_methods', 'kode')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->where('revisi', $request->input('revisi', $metode?->revisi))
                        ->whereNull('deleted_at'))
                    ->ignore($metode?->id),
            ],
            'revisi' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'equipment_category_id' => [
                'sometimes', 'nullable',
                Rule::exists('equipment_categories', 'id')->where('organization_id', $organizationId),
            ],
            'berlaku_mulai' => ['sometimes', 'nullable', 'date'],
            'aktif' => ['sometimes', 'boolean'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'kode.unique' => 'Kode IK dengan revisi ini udah ada. Revisi baru = kode sama, nomor revisi beda.',
        ]);
    }

    /** Jaring pengaman multi-tenant. */
    private function pastikanSatuOrganisasi(Request $request, CalibrationMethod $metode): void
    {
        abort_if($metode->organization_id !== $request->user()->organization_id, 404);
    }
}
