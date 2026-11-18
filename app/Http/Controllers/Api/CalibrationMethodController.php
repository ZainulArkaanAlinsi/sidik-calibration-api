<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalibrationMethodResource;
use App\Models\CalibrationMethod;
use App\Services\KonflikDataTerhapus;
use App\Services\PenjagaOrganisasi;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $data = $this->validasi($request);

        try {
            $metode = CalibrationMethod::create([
                ...$data,
                'organization_id' => $request->user()->organization_id,
            ]);
        } catch (UniqueConstraintViolationException $galat) {
            // Balapan: indeks unik yang memutuskan → 422, bukan 500.
            KonflikDataTerhapus::untukMetodeIk()->terjemahkan(
                $galat,
                $request->user()->organization_id,
                ['kode' => $data['kode'] ?? null, 'revisi' => $data['revisi'] ?? null],
            );
        }

        return response()->json([
            'data' => new CalibrationMethodResource($metode->load('category')),
        ], 201);
    }

    public function update(Request $request, CalibrationMethod $calibrationMethod): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibrationMethod);

        $data = $this->validasi($request, $calibrationMethod);

        try {
            $calibrationMethod->update($data);
        } catch (UniqueConstraintViolationException $galat) {
            KonflikDataTerhapus::untukMetodeIk()->terjemahkan(
                $galat,
                $request->user()->organization_id,
                [
                    'kode' => $data['kode'] ?? $calibrationMethod->kode,
                    'revisi' => array_key_exists('revisi', $data) ? $data['revisi'] : $calibrationMethod->revisi,
                ],
            );
        }

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

        $data = $request->validate([
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

        // Rule::unique di atas cuma melihat baris HIDUP, sedangkan indeks unik di
        // database juga melihat yang terhapus — tanpa ini kode+revisi yang
        // pernah dihapus lolos validasi lalu INSERT-nya 500 (BUG-5, 18 Nov 2026).
        // Hanya dicek kalau kode/revisi ikut dikirim: PUT yang tidak menyentuh
        // keduanya tidak boleh ditolak gara-gara baris lain.
        if (array_key_exists('kode', $data) || array_key_exists('revisi', $data)) {
            KonflikDataTerhapus::untukMetodeIk()->periksa(
                $organizationId,
                [
                    'kode' => $data['kode'] ?? $metode?->kode,
                    'revisi' => array_key_exists('revisi', $data) ? $data['revisi'] : $metode?->revisi,
                ],
                $metode?->id,
            );
        }

        return $data;
    }

    /** Jaring pengaman multi-tenant. */
    private function pastikanSatuOrganisasi(Request $request, CalibrationMethod $metode): void
    {
        // Diteruskan ke PenjagaOrganisasi: satu tempat yang memutuskan dan
        // satu tempat yang MENCATAT percobaannya. Perilaku dari luar identik
        // (404 yang sama); yang ditambahkan cuma jejaknya di audit_logs.
        PenjagaOrganisasi::pastikanSatu($request, $metode);
    }
}
