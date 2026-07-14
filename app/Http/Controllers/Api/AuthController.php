<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $identifier = $credentials['identifier'];

        // Ada '@' → anggap email, kalau nggak → ID pegawai.
        $field = Str::contains($identifier, '@') ? 'email' : 'employee_id';

        $user = User::where($field, $identifier)->first();

        // Pesan errornya sengaja disamain buat akun nggak ada & password salah,
        // biar orang luar nggak bisa nebak-nebak ID/email mana yang kedaftar.
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'ID pegawai / email atau password salah.'], 401);
        }

        // Benteng aslinya ada di sini, bukan di UI — orang bisa nembak API
        // langsung pakai curl tanpa lewat app.
        if ($user->status === User::STATUS_PENDING) {
            return response()->json([
                'message' => 'Akun kamu belum disetujui admin. Tunggu konfirmasi dulu ya.',
            ], 403);
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return response()->json(['message' => 'Akun ini nonaktif. Hubungi admin.'], 403);
        }

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // role & status di-hardcode, NGGAK diambil dari request.
        User::create([
            // Pendaftar langsung nempel ke organisasi bawaan. Satu instalasi =
            // satu PT, jadi nggak ada yang perlu dipilih — dan kalau dibiarin null,
            // layar profil di mobile bakal nampilin PT kosong.
            'organization_id' => Organization::query()->min('id'),
            'name' => $data['nama'],
            'employee_id' => $data['employee_id'],
            'department' => $data['department'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Pendaftaran terkirim. Akun menunggu persetujuan admin.',
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    /**
     * Cabut SEMUA token, termasuk yang lagi dipakai sekarang.
     *
     * Ini jawabannya kalau HP teknisi ilang: token Sanctum nggak kadaluarsa
     * sendiri, jadi tanpa endpoint ini sesi di HP yang ilang bakal hidup selamanya.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $jumlah = $request->user()->tokens()->count();
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Berhasil keluar dari semua perangkat.',
            'data' => ['sesi_dicabut' => $jumlah],
        ]);
    }
}
