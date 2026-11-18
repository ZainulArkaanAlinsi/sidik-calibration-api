<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $atribut = $this->resource->getAttributes();

        // Hitungan hanya ada kalau querynya lewat `Customer::denganHitungan()`
        // (daftar & detail). `POST`/`PUT` tidak menghitungnya, dan kunci yang tidak
        // dihitung TIDAK dikirim — `0` palsu di sana sama dengan berbohong.
        $berhitung = array_key_exists('sertifikat_count', $atribut);

        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'alamat' => $this->alamat,
            'contact_person' => $this->contact_person,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'jumlah_alat' => $this->whenCounted('equipments'),

            // Aditif (18 Nov 2026, `docs/perintah-frontend-super-admin.md` §3.6).
            // Kunci di atas tidak berubah.
            'alat_count' => $this->when($berhitung, fn () => (int) $this->alat_count),
            'sertifikat_count' => $this->when($berhitung, fn () => (int) $this->sertifikat_count),
            'anggota_aktif_count' => $this->when($berhitung, fn () => (int) $this->anggota_aktif_count),
            'undangan_menunggu_count' => $this->when($berhitung, fn () => (int) $this->undangan_menunggu_count),
            // Punya alat/sertifikat tapi tidak ada satu pun anggota aktif: tidak ada
            // orang di PT itu yang bisa melihat datanya.
            'tanpa_akun' => $this->when($berhitung, fn () => ((int) $this->alat_count > 0 || (int) $this->sertifikat_count > 0)
                && (int) $this->anggota_aktif_count === 0),
            // Penjaga PT kembar membaca kolom ini; kosong = penjaganya tidak bekerja.
            'nama_normal_kosong' => blank($this->nama_normal),
        ];
    }
}
