<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\KoreksiPelangganResource;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\KoreksiPelanggan;
use App\Services\Pelanggan\AlurKoreksi;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Minta koreksi data alat & sertifikat, dan pantau hasilnya (§42 B1/B2/B4).
 *
 * ID alat/sertifikat/koreksi dicari DI DALAM perusahaan pemanggil sebelum
 * validasi body — milik perusahaan lain 404, bukan 422 (aturan Modul
 * Pelanggan butir 4). `customer_id` tidak pernah dibaca dari request.
 */
class KoreksiController extends Controller
{
    public function __construct(private readonly AlurKoreksi $alur) {}

    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([...KoreksiPelanggan::daftarStatus(), 'semua'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $status = $data['status'] ?? 'semua';

        $daftar = $this->lingkup($konteks)
            ->when($status !== 'semua', fn (Builder $q) => $q->where('status', $status))
            ->with($this->relasi())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($daftar->items())
                ->map(fn (KoreksiPelanggan $k) => (new KoreksiPelangganResource($k))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $daftar->total(),
                'per_page' => $daftar->perPage(),
                'current_page' => $daftar->currentPage(),
                'last_page' => $daftar->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Konteks $konteks, string $koreksi): JsonResponse
    {
        $baris = $this->lingkup($konteks)->whereKey($koreksi)->with($this->relasi())->firstOrFail();

        return response()->json(['data' => (new KoreksiPelangganResource($baris))->resolve($request)]);
    }

    public function mintaAlat(Request $request, Konteks $konteks, string $alat): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        /** @var Equipment $baris */
        $baris = $lingkup->alat()->whereKey($alat)->firstOrFail();

        $data = $request->validate([
            'perubahan' => ['required', 'array', 'min:1'],
            'perubahan.*' => ['nullable', 'max:255'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        $koreksi = $this->alur->ajukanAlat($lingkup->perusahaan(), $request->user(), $baris, $data['perubahan'], $data['catatan'] ?? null);

        return $this->dibuat($request, $koreksi);
    }

    public function mintaSertifikat(Request $request, Konteks $konteks, string $sertifikat): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        /** @var Certificate $baris */
        $baris = $lingkup->sertifikat()->whereKey($sertifikat)->with('session.equipment')->firstOrFail();

        $data = $request->validate([
            'perubahan' => ['required', 'array', 'min:1'],
            'perubahan.*' => ['nullable', 'string', 'max:500'],
            'perubahan.tanggal_kalibrasi' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        $koreksi = $this->alur->ajukanSertifikat($lingkup->perusahaan(), $request->user(), $baris, $data['perubahan'], $data['catatan'] ?? null);

        return $this->dibuat($request, $koreksi);
    }

    private function dibuat(Request $request, KoreksiPelanggan $koreksi): JsonResponse
    {
        return response()->json([
            'message' => 'Permintaan koreksi terkirim. Tim lab akan meninjaunya.',
            'data' => (new KoreksiPelangganResource($koreksi->fresh($this->relasi())))->resolve($request),
        ], 201);
    }

    /** @return Builder<KoreksiPelanggan> */
    private function lingkup(Konteks $konteks): Builder
    {
        return KoreksiPelanggan::query()
            ->where('customer_id', $konteks->customerId)
            ->where('organization_id', (new LingkupData($konteks))->perusahaan()->organization_id);
    }

    /** @return list<string> */
    private function relasi(): array
    {
        return ['pengaju:id,name', 'equipment:id,nama_alat,serial_number', 'certificate:id,nomor', 'revisi:id,nomor', 'foto'];
    }
}
