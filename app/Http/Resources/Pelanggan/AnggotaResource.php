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
 * `telepon` TIDAK sama dengan `email` (12 Nov 2026): nomor HP rekan hanya untuk
 * PIC utama dan untuk pemilik nomornya sendiri. Bagi staf, telepon rekan
 * bernilai `null` — kuncinya tetap ada supaya bentuk JSON tidak berubah buat
 * klien lama.
 *
 * ## Default-nya TERTUTUP
 *
 * Resource ini tidak menebak peran pemintanya dari request. Pemanggil yang
 * tahu pemintanya PIC utama harus menyatakannya lewat [bolehLihatTeleponRekan()];
 * pemakai baru yang lupa memanggilnya mendapat telepon `null` untuk rekan —
 * kegagalan yang kelihatan di layar, bukan nomor HP orang yang bocor diam-diam.
 *
 * @mixin CustomerMember
 */
class AnggotaResource extends JsonResource
{
    private bool $teleponRekanTerbuka = false;

    /**
     * Buka telepon rekan — dipanggil controller hanya dengan nilai dari
     * `Konteks::picUtama()`, tidak pernah dari input request.
     */
    public function bolehLihatTeleponRekan(bool $boleh = true): static
    {
        $this->teleponRekanTerbuka = $boleh;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $milikSendiri = $this->user_id === $request->user()?->getKey();
        $teleponTampil = $this->teleponRekanTerbuka || $milikSendiri;

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
                'telepon' => $teleponTampil ? $this->user->telepon : null,
                'jabatan' => $this->user->jabatan,
            ]),
            // Dijawab SERVER supaya aplikasi tidak menghitung sendiri "ini saya
            // atau bukan" dari id yang dia simpan — nilai yang gampang basi
            // sesudah ganti akun.
            'saya' => $milikSendiri,
        ];
    }
}
