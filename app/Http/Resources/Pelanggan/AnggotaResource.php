<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\CustomerMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu anggota perusahaan, seperti yang dilihat anggota lain (REQ-ANG-03).
 *
 * `email` IKUT — staf boleh melihatnya. Itu rekan satu perusahaan yang alamat
 * emailnya memang dipakai buat saling menghubungi soal alat & sertifikat, bukan
 * data pihak ketiga. Yang TIDAK ikut: `role`, `status` akun, dan apa pun dari
 * kosakata internal lab.
 *
 * @mixin CustomerMember
 */
class AnggotaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'peran' => $this->peran,
            'status' => $this->status,
            'bergabung_pada' => $this->created_at?->toIso8601String(),
            'dinonaktifkan_pada' => $this->dinonaktifkan_pada?->toIso8601String(),
            'orang' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama' => $this->user->name,
                'email' => $this->user->email,
                'telepon' => $this->user->telepon,
                'jabatan' => $this->user->jabatan,
            ]),
            // Dijawab SERVER supaya aplikasi tidak menghitung sendiri "ini saya
            // atau bukan" dari id yang dia simpan — nilai yang gampang basi
            // sesudah ganti akun.
            'saya' => $this->user_id === $request->user()?->getKey(),
        ];
    }
}
