<?php

namespace App\Http\Resources\Admin;

use App\Models\Customer;
use App\Models\PengajuanAkunPelanggan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris antrean pengajuan akun, seperti yang dilihat ADMIN LAB.
 *
 * `nama_perusahaan` di sini sengaja disebut **klaim** di aplikasi: dia yang
 * diketik pendaftar, bukan nama pelanggan yang sudah terverifikasi. Menyajikan
 * keduanya dengan nama field yang sama bikin admin membacanya sebagai fakta —
 * dan menyetujui tanpa memeriksa, yang persis pintu R-D02.
 *
 * @mixin PengajuanAkunPelanggan
 */
class PengajuanAkunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'diajukan_pada' => $this->created_at?->toIso8601String(),
            'klaim' => [
                'nama_perusahaan' => $this->nama_perusahaan,
                'alamat_perusahaan' => $this->alamat_perusahaan,
                'jabatan' => $this->jabatan,
            ],
            'pemohon' => $this->whenLoaded('pemohon', fn () => [
                'id' => $this->pemohon->id,
                'nama' => $this->pemohon->name,
                'email' => $this->pemohon->email,
                'telepon' => $this->pemohon->telepon,
                'status' => $this->pemohon->status,
            ]),
            'keputusan' => $this->status === PengajuanAkunPelanggan::STATUS_MENUNGGU ? null : [
                'oleh' => $this->whenLoaded('pemutus', fn () => $this->pemutus?->name),
                'pada' => $this->diputus_pada?->toIso8601String(),
                'alasan_tolak' => $this->alasan_tolak,
                'customer_id' => $this->customer_id,
            ],
            // Saran, BUKAN pilihan. Disuntikkan controller karena menghitungnya
            // per baris di dalam resource berarti satu query kemiripan per
            // baris antrean.
            'saran_pelanggan' => $this->when(
                isset($this->saranPelanggan),
                fn () => collect($this->saranPelanggan)->map(fn (Customer $pelanggan) => [
                    'id' => $pelanggan->id,
                    'nama' => $pelanggan->nama,
                    'alamat' => $pelanggan->alamat,
                    'sama_persis' => $pelanggan->nama_normal === Customer::normalkanNama((string) $this->nama_perusahaan),
                ])->values(),
            ),
        ];
    }
}
