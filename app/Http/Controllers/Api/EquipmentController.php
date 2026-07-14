<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Baca: semua role. Tulis: admin & teknisi doang (viewer ditolak 403 lewat
 * middleware `role:admin,teknisi` di routes/api.php).
 */
class EquipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $equipments = Equipment::query()
            ->with(['customer', 'category'])
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('search'), function ($query) use ($request) {
                $keyword = '%'.$request->string('search').'%';

                $query->where(fn ($q) => $q
                    ->where('nama_alat', 'like', $keyword)
                    ->orWhere('serial_number', 'like', $keyword)
                    ->orWhere('merk', 'like', $keyword));
            })
            ->when($request->filled('category'), fn ($query) => $query->whereHas(
                'category',
                fn ($q) => $q->where('kode', $request->string('category')),
            ))
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = (string) $request->string('status');

                // `overdue` bukan nilai di DB — dia turunan tanggal jatuh tempo.
                match ($status) {
                    Equipment::STATUS_OVERDUE => $query->overdue(),
                    Equipment::STATUS_AKTIF => $query->where('status', Equipment::STATUS_AKTIF)
                        ->where(fn ($q) => $q->whereNull('tanggal_jatuh_tempo')
                            ->orWhereDate('tanggal_jatuh_tempo', '>=', now())),
                    default => $query->where('status', $status),
                };
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return EquipmentResource::collection($equipments);
    }

    public function store(EquipmentRequest $request): JsonResponse
    {
        $equipment = Equipment::create($this->payload($request));

        return response()->json(
            ['data' => new EquipmentResource($equipment->load(['customer', 'category']))],
            201,
        );
    }

    public function show(Request $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        return response()->json([
            'data' => new EquipmentResource($equipment->load(['customer', 'category'])),
        ]);
    }

    public function update(EquipmentRequest $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        $equipment->update($this->payload($request));

        return response()->json([
            'data' => new EquipmentResource($equipment->fresh()->load(['customer', 'category'])),
        ]);
    }

    public function destroy(Request $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        // Soft delete — riwayat kalibrasi & sertifikat lama harus tetap bisa ditelusuri.
        $equipment->delete();

        return response()->json(['message' => 'Alat dihapus.']);
    }

    /**
     * Mobile ngirim `kategori` (kode) & `pelanggan_id`; DB nyimpennya sebagai FK.
     *
     * @return array<string, mixed>
     */
    private function payload(EquipmentRequest $request): array
    {
        $data = $request->validated();
        $organizationId = $request->user()->organization_id;

        if (isset($data['kategori'])) {
            $data['equipment_category_id'] = EquipmentCategory::where('organization_id', $organizationId)
                ->where('kode', $data['kategori'])
                ->value('id');
        }

        if (isset($data['pelanggan_id'])) {
            $data['customer_id'] = $data['pelanggan_id'];
        }

        unset($data['kategori'], $data['pelanggan_id']);

        return [...$data, 'organization_id' => $organizationId];
    }

    /** Jaring pengaman multi-tenant: jangan sampai PT lain bisa baca/ubah alat kita. */
    private function pastikanSatuOrganisasi(Request $request, Equipment $equipment): void
    {
        abort_if($equipment->organization_id !== $request->user()->organization_id, 404);
    }
}
