<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Approval akun. Semua endpoint di sini admin-only (dijaga middleware `role:admin`
 * di routes/api.php) — tanpa ini, register jadi jebakan: orang daftar, statusnya
 * pending selamanya, nggak ada yang bisa nyetujuin.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->latest('id')
            ->paginate(15);

        return UserResource::collection($users);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        // Admin yang nentuin role-nya di sini, bukan si pendaftar.
        $validated = $request->validate(
            ['role' => ['required', Rule::in(User::roles())]],
            [
                'role.required' => 'Role wajib diisi.',
                'role.in' => 'Role harus salah satu dari: admin, teknisi, viewer.',
            ],
        );

        $user->update([
            'role' => $validated['role'],
            'status' => User::STATUS_AKTIF,
        ]);

        return response()->json([
            'message' => 'Akun disetujui.',
            'data' => new UserResource($user),
        ]);
    }

    public function reject(User $user): JsonResponse
    {
        $user->update(['status' => User::STATUS_NONAKTIF]);

        // Token yang mungkin udah kepegang dicabut, biar penolakannya langsung berlaku.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Pendaftaran ditolak.',
            'data' => new UserResource($user),
        ]);
    }
}
