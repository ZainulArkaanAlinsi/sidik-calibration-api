<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AkunBaruMenunggu;
use App\Services\MatriksIzin;
use App\Services\PenerimaNotifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        if (in_array($user->role, [User::ROLE_PELANGGAN, User::ROLE_SUPER_ADMIN], true)) {
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

    public function register(RegisterRequest $request, PenerimaNotifikasi $penerima): JsonResponse
    {
        $data = $request->validated();

        // role & status di-hardcode, NGGAK diambil dari request.
        $user = User::create([
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

        // Kabarin admin (fase-2 §2). Tanpa ini, register jadi jebakan: orangnya
        // kejebak di layar "belum disetujui" dan nggak ada admin yang tahu, jadi
        // lamanya dia nunggu itu soal keberuntungan — apakah ada admin yang
        // kebetulan buka layar approval.
        //
        // Kegagalan ngirim notifikasi NGGAK boleh ngegagalin pendaftarannya:
        // akunnya udah kesimpen, dan bikin request-nya 500 malah bikin orangnya
        // daftar ulang terus kena "employee_id udah kepakai".
        try {
            $notifikasi = AkunBaruMenunggu::dariUser($user);

            foreach ($penerima->adminAktif((int) $user->organization_id) as $admin) {
                $admin->notify($notifikasi);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal ngabarin admin soal pendaftar baru.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Pendaftaran terkirim. Akun menunggu persetujuan admin.',
        ], 201);
    }

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
