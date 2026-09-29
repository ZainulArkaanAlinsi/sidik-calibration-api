<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `/perangkat` — daftarkan / cabut HP pelanggan untuk push notification.
 *
 * Tanpa rute ini pengingat jatuh tempo (slice E) cuma mendarat di kotak masuk
 * aplikasi: tidak ada satu pun HP pelanggan yang terdaftar, jadi push-nya
 * tidak pernah berbunyi.
 *
 * Dipasang di grup "cukup token", BUKAN grup "akun terverifikasi": akun yang
 * baru mendaftar perlu sudah terdaftar perangkatnya supaya kabar
 * "akun Anda sudah diverifikasi" sampai ke HP-nya. Rute ini tidak membuka data
 * perusahaan apa pun — yang disimpan cuma token milik pemanggil sendiri.
 */
class PerangkatController extends Controller
{
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
            DeviceToken::APLIKASI_PELANGGAN,
        );

        return response()->json(['data' => ['terdaftar' => true]], 201);
    }

    /** Dipanggil waktu keluar. 200 walau tokennya sudah tidak ada. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['data' => ['terdaftar' => false]]);
    }
}
