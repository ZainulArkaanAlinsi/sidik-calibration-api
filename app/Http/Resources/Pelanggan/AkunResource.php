<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\CustomerMember;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun pelanggan seperti yang dilihat pemiliknya sendiri (§7.2 `GET /saya`).
 *
 * `UserResource` internal SENGAJA tidak dipakai ulang: dia membawa
 * `employee_id`, `department`, `kode_teknisi`, dan `role` — kosakata lab yang
 * tidak berarti apa-apa di aplikasi pelanggan, dan `role` khususnya bocoran
 * struktur internal yang tidak perlu keluar sama sekali.
 *
 * @mixin User
 */
class AkunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'telepon' => $this->telepon,
            'jabatan' => $this->jabatan,
            'status' => $this->status,
            // Apa yang boleh dibuka aplikasi sekarang — dijawab server, bukan
            // ditebak dari `status` di sisi Flutter. Alasannya sama dengan
            // `/me/permissions` di app internal: aturan yang disalin ke klien
            // basi diam-diam begitu server berubah.
            'butuh_verifikasi' => $this->status !== User::STATUS_AKTIF,
            'keanggotaan' => $this->whenLoaded(
                'keanggotaan',
                fn () => $this->keanggotaan
                    ->where('status', CustomerMember::STATUS_AKTIF)
                    ->map(fn (CustomerMember $m) => [
                        'customer_id' => $m->customer_id,
                        'nama_perusahaan' => $m->customer?->nama,
                        'peran' => $m->peran,
                    ])
                    ->values(),
                [],
            ),
            'pengajuan' => $this->whenLoaded(
                'pengajuanAkun',
                fn () => $this->ringkasPengajuan($this->pengajuanAkun),
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function ringkasPengajuan(?PengajuanAkunPelanggan $pengajuan): ?array
    {
        if ($pengajuan === null) {
            return null;
        }

        return [
            'status' => $pengajuan->status,
            'nama_perusahaan' => $pengajuan->nama_perusahaan,
            'diajukan_pada' => $pengajuan->created_at?->toIso8601String(),
            'diputus_pada' => $pengajuan->diputus_pada?->toIso8601String(),
            // Alasan penolakan memang ditampilkan ke pemohon (REQ-AUTH-05) —
            // tanpa itu layar S06 cuma bilang "ditolak" dan yang terjadi
            // berikutnya orangnya menelepon lab.
            'alasan_tolak' => $pengajuan->alasan_tolak,
        ];
    }
}
