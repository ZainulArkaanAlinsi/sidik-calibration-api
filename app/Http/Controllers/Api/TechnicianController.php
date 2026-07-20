<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TechnicianRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master data teknisi — admin doang (dijaga `role:admin` di routes).
 *
 * Teknisi BUKAN tabel sendiri, ini `users` yang role-nya `teknisi`. Dibikin
 * tabel terpisah, `calibration_sessions.teknisi_id` bakal nunjuk ke dua tempat
 * dan orang yang sama punya dua identitas: satu buat login, satu buat ditulis
 * di sertifikat.
 *
 * Semua akses di sini dikunci dua lapis: seorganisasi DAN role-nya teknisi.
 * Tanpa lapis kedua, endpoint ini jadi jalan pintas ngedit atau ngehapus akun
 * admin lewat URL yang kedengarannya nggak berbahaya.
 */
class TechnicianController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $teknisi = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('role', User::ROLE_TEKNISI)
            ->withCount('calibrationSessions')
            ->when($request->filled('search'), function ($query) use ($request) {
                $cari = '%'.$request->string('search').'%';

                $query->where(fn ($q) => $q->where('name', 'like', $cari)->orWhere('employee_id', 'like', $cari));
            })
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return TechnicianResource::collection($teknisi);
    }

    public function store(TechnicianRequest $request): JsonResponse
    {
        $data = $request->validated();

        $teknisi = User::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['nama'],
            'employee_id' => $data['employee_id'],
            'email' => $data['email'],
            'department' => $data['department'] ?? null,
            'password' => $data['password'],
            'role' => User::ROLE_TEKNISI,
            // Dibikinin admin, jadi langsung aktif — nggak perlu lewat antrean
            // approval yang gunanya nyaring pendaftar mandiri.
            'status' => $data['status'] ?? User::STATUS_AKTIF,
        ]);

        return response()->json(['data' => new TechnicianResource($teknisi)], 201);
    }

    public function show(Request $request, User $technician): JsonResponse
    {
        $this->pastikanTeknisiSatuOrganisasi($request, $technician);

        return response()->json([
            'data' => new TechnicianResource($technician->loadCount('calibrationSessions')),
        ]);
    }

    public function update(TechnicianRequest $request, User $technician): JsonResponse
    {
        $this->pastikanTeknisiSatuOrganisasi($request, $technician);

        $data = $request->validated();

        if (isset($data['nama'])) {
            $data['name'] = $data['nama'];
            unset($data['nama']);
        }

        $technician->update($data);

        // Teknisi yang dinonaktifin harus langsung kehilangan sesinya, bukan
        // nunggu tokennya kadaluarsa sendiri — token Sanctum nggak kadaluarsa.
        if (($data['status'] ?? null) === User::STATUS_NONAKTIF) {
            $technician->tokens()->delete();
        }

        return response()->json([
            'data' => new TechnicianResource($technician->fresh()->loadCount('calibrationSessions')),
        ]);
    }

    public function destroy(Request $request, User $technician): JsonResponse
    {
        $this->pastikanTeknisiSatuOrganisasi($request, $technician);

        // Teknisi yang pernah ngerjain kalibrasi NGGAK boleh dihapus. Namanya
        // nempel di sertifikat yang udah terbit — hapus dia berarti ngerusak
        // ketertelusuran yang justru dicari asesor waktu audit. Nonaktifin aja:
        // nggak bisa login lagi, tapi riwayatnya utuh.
        if ($technician->calibrationSessions()->exists()) {
            return response()->json([
                'message' => 'Teknisi ini masih punya riwayat kalibrasi. Nonaktifin aja biar riwayat sertifikatnya nggak putus.',
            ], 422);
        }

        $technician->tokens()->delete();
        $technician->delete();

        return response()->json(['message' => 'Teknisi dihapus.']);
    }

    private function pastikanTeknisiSatuOrganisasi(Request $request, User $technician): void
    {
        abort_if($technician->organization_id !== $request->user()->organization_id, 404);
        abort_if($technician->role !== User::ROLE_TEKNISI, 404);
    }
}
