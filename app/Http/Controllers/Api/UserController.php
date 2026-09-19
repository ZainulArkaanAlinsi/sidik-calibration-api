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
            ->where('organization_id', $request->user()->organization_id)
            // Cuma akun INTERNAL. `organization_id` nggak memisahkan apa-apa di
            // sini: pelanggan PT Sidik memang duduk di organisasi PT Sidik, jadi
            // tanpa saringan ini seluruh akun pelanggan bocor ke daftar pengguna
            // internal — dan dari situ `PUT /users/{id}` tinggal satu langkah.
            // Alasan lengkapnya di [pastikanAkunInternal].
            ->whereIn('role', User::roles())
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
        $this->pastikanSatuOrganisasi($request, $user);

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

    public function reject(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        $user->update(['status' => User::STATUS_NONAKTIF]);

        // Token yang mungkin udah kepegang dicabut, biar penolakannya langsung berlaku.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Pendaftaran ditolak.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Admin benerin data akun — terutama EMAIL yang salah ketik waktu daftar.
     *
     * Ini bukan kemewahan: reset password jalannya lewat email, sementara login
     * pakai ID pegawai. Jadi orang yang salah ketik emailnya waktu daftar bakal
     * kekunci selamanya kalau nggak ada yang bisa benerin.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        $validated = $request->validate([
            'nama' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_id' => ['sometimes', 'string', 'max:50', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(User::roles())],
            'status' => ['sometimes', Rule::in([User::STATUS_AKTIF, User::STATUS_PENDING, User::STATUS_NONAKTIF])],
        ], [
            'email.unique' => 'Email ini sudah terdaftar.',
            'employee_id.unique' => 'ID pegawai ini sudah terdaftar.',
        ]);

        if (isset($validated['nama'])) {
            $validated['name'] = $validated['nama'];
            unset($validated['nama']);
        }

        $user->update($validated);

        // Akun yang dinonaktifkan admin harus langsung kehilangan sesinya.
        if (($validated['status'] ?? null) === User::STATUS_NONAKTIF) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Data akun diperbarui.',
            'data' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Admin nyetel ulang password user, buat kasus yang nggak bisa ditolong
     * `/forgot-password`: emailnya salah ketik, atau emailnya udah nggak bisa
     * diakses. Password barunya dikasih tahu admin ke orangnya langsung.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $user->update(['password' => $validated['password']]);

        // Semua sesi lama dicabut — kalau password diganti karena akunnya
        // kebobolan, sesi yang lagi jalan justru yang paling penting dimatiin.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password akun berhasil disetel ulang.']);
    }

    /**
     * Admin cuma boleh ngurus akun di organisasinya sendiri.
     *
     * `role:admin` di routes cuma mastiin yang manggil itu admin — dia nggak
     * peduli admin MANA. Tanpa penjaga ini, admin PT A bisa nyetel ulang
     * password admin PT B cuma modal nebak ID di URL.
     *
     * 404, bukan 403: 403 ngonfirmasi akunnya ada: itu udah bocoran sendiri.
     */
    private function pastikanSatuOrganisasi(Request $request, User $user): void
    {
        abort_if($user->organization_id !== $request->user()->organization_id, 404);

        $this->pastikanAkunInternal($user);
    }

    /**
     * Endpoint `/users` cuma buat akun INTERNAL — bukan pelanggan, bukan
     * super admin.
     *
     * ## Kegagalan yang ditutup
     *
     * `pastikanSatuOrganisasi()` di atas cuma mencocokkan `organization_id`,
     * dan itu tidak memisahkan apa pun: akun pelanggan PT Sidik memang duduk di
     * organisasi PT Sidik. `Rule::in(User::roles())` di [update] & [approve]
     * juga tidak menolong — dia menjaga nilai role yang MASUK, bukan akun yang
     * DITUJU. Jadi sebelum penjaga ini:
     *
     *     PUT  /api/users/{id_pelanggan}          {"role":"admin"}  -> 200
     *     POST /api/users/{id_pelanggan}/approve  {"role":"admin"}  -> 200
     *
     * dua-duanya mengubah akun pelanggan jadi akun lab dengan akses ke seluruh
     * data seluruh pelanggan. Itu risiko R-D02 di docblock `User`, dan jalannya
     * satu request.
     *
     * Sisi panel Filament sudah ditutup lebih dulu lewat
     * `UserResource::getEloquentQuery()`; sisi API-nya belum ikut, dan celah itu
     * yang ditambal di sini. Kedua sisi sekarang memakai daftar yang sama
     * (`User::roles()`), jadi menambah role internal baru cukup di satu tempat.
     *
     * `super_admin` ikut tertolak dengan sendirinya — dia juga bukan anggota
     * `User::roles()`. Perilakunya sejajar dengan panel, yang sudah menolaknya
     * lewat `test_akun_super_admin_juga_tidak_bisa_dibuka`.
     *
     * 404, bukan 403, dengan alasan yang sama seperti penjaga organisasi di
     * atas: 403 mengonfirmasi akunnya ada, dan itu sudah bocoran sendiri.
     */
    private function pastikanAkunInternal(User $user): void
    {
        abort_if(! in_array($user->role, User::roles(), true), 404);
    }
}
