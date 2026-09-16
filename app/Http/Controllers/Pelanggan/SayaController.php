<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pelanggan\GantiSandiRequest;
use App\Http\Requests\Pelanggan\PerbaruiProfilRequest;
use App\Http\Resources\Pelanggan\AkunResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Profil pemilik token — `GET/PATCH /saya`, `POST /saya/ganti-sandi`.
 *
 * `GET /saya` juga yang menjawab layar S06 ("menunggu verifikasi", tarik untuk
 * refresh), jadi dia SENGAJA kebuka buat token `pelanggan:menunggu`. Kalau
 * ditutup, layar itu tidak punya cara membaca statusnya sendiri dan satu-satunya
 * jalan tahu pengajuannya sudah disetujui adalah keluar lalu masuk lagi.
 */
class SayaController extends Controller
{
    public function tampil(Request $request): JsonResponse
    {
        return response()->json(['data' => new AkunResource($this->segar($request->user()))]);
    }

    public function perbarui(PerbaruiProfilRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Dipetakan satu per satu, BUKAN `$user->update($data)`: nama kunci di
        // API (`nama`) beda dari nama kolom (`name`), dan kunci yang tidak
        // dikirim tidak boleh menimpa nilai yang ada dengan null.
        $isi = array_filter([
            'name' => $data['nama'] ?? null,
            'telepon' => $data['telepon'] ?? null,
        ], fn ($v) => $v !== null);

        // `jabatan` ditangani terpisah karena dia BOLEH dikosongkan — kalau
        // ikut `array_filter` di atas, mengosongkannya jadi mustahil.
        if (array_key_exists('jabatan', $data)) {
            $isi['jabatan'] = $data['jabatan'];
        }

        if ($isi !== []) {
            $user->update($isi);
        }

        return response()->json(['data' => new AkunResource($this->segar($user))]);
    }

    /**
     * Ganti sandi — REQ-AUTH-09: semua sesi LAIN dicabut.
     *
     * Token yang sedang dipakai sengaja dipertahankan. Mencabutnya juga
     * membuat aplikasi langsung 401 di layar berikutnya, dan orang yang baru
     * saja mengganti sandinya dengan benar diusir keluar — yang terbaca seperti
     * kegagalan, bukan keberhasilan.
     */
    public function gantiSandi(GantiSandiRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (! Hash::check($data['sandi_lama'], (string) $user->password)) {
            return response()->json([
                'kode' => 'sandi_lama_salah',
                'message' => 'Sandi lama tidak cocok.',
            ], 422);
        }

        $sekarang = $user->currentAccessToken();
        $idSekarang = $sekarang instanceof PersonalAccessToken ? $sekarang->getKey() : null;

        $dicabut = DB::transaction(function () use ($user, $data, $idSekarang): int {
            $user->forceFill(['password' => Hash::make($data['sandi'])])->save();

            return $user->tokens()
                ->when($idSekarang !== null, fn ($kueri) => $kueri->whereKeyNot($idSekarang))
                ->delete();
        });

        return response()->json([
            'message' => 'Sandi berhasil diganti.',
            'data' => ['sesi_dicabut' => $dicabut],
        ]);
    }

    /**
     * Relasi dimuat ulang tiap kali, bukan sekali di middleware.
     *
     * `PATCH` mengubah barisnya, dan resource yang dibangun dari instance lama
     * memulangkan nilai SEBELUM perubahan — aplikasi lalu menampilkan nama lama
     * sesudah simpan berhasil, dan orangnya menekan simpan lagi.
     */
    private function segar(User $user): User
    {
        return $user->load(['keanggotaan.customer', 'pengajuanAkun']);
    }
}
