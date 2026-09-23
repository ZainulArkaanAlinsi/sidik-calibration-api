<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\MatriksIzin;
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

        // Akun pelanggan ditolak di pintu internal (REQ-AUTH-07).
        //
        // Diperiksa SESUDAH sandinya cocok, bukan sebelum: kalau dicek duluan,
        // balasan buat email pelanggan jadi beda dari balasan buat email yang
        // nggak terdaftar, dan orang luar bisa memakai perbedaan itu buat
        // menyisir email mana yang punya akun di sini.
        //
        // Pesannya menyebut nama aplikasinya. Tanpa itu, PIC yang kebetulan
        // memasang aplikasi teknisi mentok di layar galat tanpa tahu harus ke
        // mana — dan yang dia lakukan berikutnya menelepon lab.
        //
        // `super_admin` DULU ikut ditolak di sini, dan itu dicabut Fase 2.
        //
        // Penolakannya menyuruh dia "pakai panel admin di peramban" sementara
        // `User::canAccessPanel()` cuma nerima `ROLE_ADMIN` — petunjuk ke pintu
        // yang ikut terkunci. Dua-duanya dibuka bareng: panel lewat
        // `canAccessPanel()`, aplikasi lewat sini.
        //
        // Yang dia dapat cuma BACA: `EnsureUserHasRole::lolosBacaSuperAdmin()`
        // meloloskan GET/HEAD saja, jadi token ini nggak bisa dipakai mengesahkan
        // apa pun selama K4 belum dijawab manajer teknis. APK yang sudah
        // terpasang nggak perlu ikut naik — `lib/models/user.dart` memetakan role
        // asing ke `viewer`, jadi tampilannya read-only dan nggak ada yang crash.
        if ($user->role === User::ROLE_PELANGGAN) {
            return response()->json([
                'kode' => 'bukan_akun_internal',
                'message' => 'Akun ini terdaftar sebagai akun pelanggan. Silakan masuk lewat aplikasi SIDIK Pelanggan.',
            ], 403);
        }

        return response()->json([
            'data' => [
                // Ability `internal` — dibaca middleware `aplikasi:internal`.
                // Sebelum ini tokennya `['*']` (default Sanctum), yang lolos
                // gerbang aplikasi mana pun. Token lama tetap diterima; lihat
                // `PastikanAplikasi` soal kapan itu berhenti.
                'token' => $user->createToken('mobile', ['internal'])->plainTextToken,
                'user' => new UserResource($user),
            ],
        ]);
    }
    /*
     * TIDAK ADA `register()`.
     *
     * Pendaftaran mandiri orang lab dicabut: akun teknisi/admin/viewer dibuat
     * admin lewat panel (Filament `CreateUser`), bukan lewat form publik.
     * Alasannya di AGENTS.md §Akun Lahir dari Undangan.
     *
     * Yang lama tidak pernah memberi akses langsung — statusnya `pending` dan
     * admin tetap harus menyetujui. Yang dicabut bukan lubang aksesnya,
     * melainkan dua hal lain: antrean persetujuan yang bisa dibanjiri siapa pun
     * dari internet, dan pemohon yang mengarang `employee_id` lalu lolos karena
     * admin sedang buru-buru. Keduanya hilang begitu tidak ada pintu publik.
     */

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    /**
     * Izin pemanggil, buat nyembunyiin tombol yang bakal ditolak (fase-2 §1).
     *
     * Dijawab dari middleware `role:` di rute yang beneran terdaftar, BUKAN dari
     * daftar tulis tangan — lihat `MatriksIzin`. Yang bikin bug sebelumnya:
     * aturannya di-hardcode di mobile, jadi tiap backend ganti aturan mobile ikut
     * basi diam-diam.
     *
     * Ini alat buat TAMPILAN, bukan penjagaan. Penjagaannya tetap di middleware;
     * kalau mobile ngeyel manggil, tetap `403`.
     */
    public function permissions(Request $request, MatriksIzin $matriks): JsonResponse
    {
        return response()->json(['data' => $matriks->untuk($request->user())]);
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
