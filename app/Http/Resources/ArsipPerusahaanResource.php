<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Satu "folder perusahaan" di Arsip. Pelanggan yang belum punya alat pun tetap
 * muncul (folder kosong, `jumlah_alat` = 0) — sesuai idenya: folder dibikin
 * dulu, isinya nyusul.
 *
 * @mixin Customer
 */
class ArsipPerusahaanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // withMax nggak nge-cast ke Carbon, jadi nilainya string tanggal mentah.
        $terakhir = $this->calibration_sessions_max_tanggal_kalibrasi;

        return [
            'tipe' => 'folder',
            'id' => $this->id,
            'nama' => $this->nama,
            'alamat' => $this->alamat,
            'contact_person' => $this->contact_person,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'jumlah_alat' => $this->whenCounted('equipments'),
            // Jumlah sertifikat TERBIT dari semua alat pelanggan ini (di-alias
            // `jumlah_sertifikat` lewat withCount di ArsipController). Org-wide,
            // belum disaring per-teknisi (lihat catatan authz di controller).
            'jumlah_sertifikat' => (int) ($this->jumlah_sertifikat ?? 0),
            'terakhir_kalibrasi' => $terakhir ? Carbon::parse($terakhir)->toIso8601ZuluString() : null,
        ];
    }
}
