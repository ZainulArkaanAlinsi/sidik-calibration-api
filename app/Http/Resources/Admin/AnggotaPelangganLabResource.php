<?php

namespace App\Http\Resources\Admin;

use App\Models\CustomerMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu anggota perusahaan pelanggan, seperti yang dilihat ADMIN LAB.
 *
 * Resource sendiri, sengaja BUKAN `Pelanggan\AnggotaResource`: yang itu bentuk
 * untuk sesama anggota (telepon rekan disembunyikan dari staf, ada `saya`),
 * dan memakai ulang resource lintas dunia adalah cara satu field baru bocor ke
 * pihak yang salah tanpa ada yang sengaja menambahkannya (AGENTS.md §Modul
 * Pelanggan butir 2). Admin lab melihat telepon & jabatan karena dia yang
 * menghubungi orangnya; yang TIDAK ada: `saya`, dan kode undangan apa pun.
 *
 * Dua bendera turunan dihitung SERVER supaya aplikasi tidak menebak aturan:
 *
 * - `pic_utama_terakhir` — dia satu-satunya PIC utama aktif perusahaannya.
 * - `bisa_dicabut` — aktif DAN bukan PIC utama terakhir. Cuma petunjuk untuk
 *   menyalakan tombol; yang menegakkan tetap `Keanggotaan::nonaktifkan()`.
 *
 * @mixin CustomerMember
 */
class AnggotaPelangganLabResource extends JsonResource
{
    /**
     * @param  int  $picUtamaAktif  jumlah PIC utama AKTIF di perusahaan anggota ini
     * @param  array<int, string>  $namaPenonaktif  id akun → nama, untuk `dinonaktifkan_oleh`
     */
    public function __construct(
        CustomerMember $resource,
        private readonly int $picUtamaAktif,
        private readonly array $namaPenonaktif = [],
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $aktif = $this->status === CustomerMember::STATUS_AKTIF;
        $picTerakhir = $aktif && $this->adalahPicUtama() && $this->picUtamaAktif <= 1;
        $penonaktifId = $this->dinonaktifkan_oleh;

        return [
            'id' => $this->id,
            'peran' => $this->peran,
            'status' => $this->status,
            'bergabung_pada' => $this->created_at?->toIso8601String(),
            'dinonaktifkan_pada' => $this->dinonaktifkan_pada?->toIso8601String(),
            'dinonaktifkan_oleh' => $penonaktifId === null
                ? null
                : ['id' => (int) $penonaktifId, 'nama' => $this->namaPenonaktif[(int) $penonaktifId] ?? null],
            'pic_utama_terakhir' => $picTerakhir,
            'bisa_dicabut' => $aktif && ! $picTerakhir,
            // `whenLoaded()` memulangkan `null` sendiri kalau relasinya termuat tapi
            // akunnya sudah tidak ada — kuncinya tetap muncul sebagai `null`.
            'orang' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama' => $this->user->name,
                'email' => $this->user->email,
                'telepon' => $this->user->telepon,
                'jabatan' => $this->user->jabatan,
            ]),
        ];
    }
}
