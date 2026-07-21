<?php

namespace App\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu "folder alat" di dalam folder perusahaan. Isinya berkas kalibrasi
 * (sesi + sertifikat) yang diambil lewat GET /arsip/alat/{equipment}.
 *
 * @mixin Equipment
 */
class ArsipAlatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tipe' => 'folder',
            'id' => $this->id,
            'nama_alat' => $this->nama_alat,
            'merk' => $this->merk,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'no_identifikasi' => $this->no_identifikasi,
            'kategori' => $this->category?->kode,
            // `overdue` turunan dari tanggal jatuh tempo, bukan kolom DB.
            'status' => $this->statusUntukApi(),
            'tanggal_kalibrasi_terakhir' => $this->tanggal_kalibrasi_terakhir?->toIso8601ZuluString(),
            'tanggal_jatuh_tempo' => $this->tanggal_jatuh_tempo?->toIso8601ZuluString(),
            // Jumlah berkas di dalam folder alat ini (di-alias lewat withCount di
            // ArsipController). Org-wide, belum disaring per-teknisi.
            'jumlah_sesi' => (int) ($this->jumlah_sesi ?? 0),
            'jumlah_sertifikat' => (int) ($this->jumlah_sertifikat ?? 0),
        ];
    }
}
