<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pendaftaran perangkat buat push notification.
 *
 * Push cuma menutup SATU celah: HP dengan aplikasi ketutup total. Selama
 * aplikasinya jalan, kabar sudah sampai lewat channel privat Reverb, dan
 * halaman notifikasi tetap sumber kebenarannya.
 *
 * Token selalu diikat ke pengguna yang login — nggak ada parameter buat
 * mendaftarkan token atas nama orang lain. Kalau ada, siapa pun yang punya
 * akun bisa mengarahkan notifikasi orang lain ke HP-nya sendiri.
 */
class DeviceTokenController extends Controller
{
    /** Daftar/perbarui token perangkat ini. Idempoten. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(DeviceToken::PLATFORM)],
            'versi_app' => ['nullable', 'string', 'max:30'],
        ]);

        DeviceToken::catat(
            $request->user(),
            $data['token'],
            $data['platform'],
            $data['versi_app'] ?? null,
        );

        return response()->json(['data' => ['terdaftar' => true]], 201);
    }

    /**
     * Cabut token — dipanggil aplikasi waktu LOGOUT.
     *
     * Ini bukan kebersihan, ini kebocoran kalau dilewat: HP yang dipakai
     * gantian bakal terus menerima notifikasi kerja orang yang sudah logout,
     * lengkap dengan nomor sesi dan nama alat pelanggan di layar kunci.
     *
     * Yang dihapus dicocokkan ke pemiliknya juga, bukan cuma ke tokennya —
     * token orang lain nggak boleh bisa dicabut dengan menebak isinya.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        // 200, bukan 404, kalau tokennya memang sudah nggak ada: yang penting
        // buat pemanggil cuma "sesudah ini dia nggak terdaftar", dan logout
        // nggak boleh gagal gara-gara token yang sudah kecabut duluan.
        return response()->json(['data' => ['terdaftar' => false]]);
    }
}
