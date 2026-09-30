<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\FotoPelanggan;
use App\Models\KoreksiPelanggan;
use App\Services\Pelanggan\FotoPelangganLayanan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Koreksi data dilihat pelanggannya (§42 B4).
 *
 * SENGAJA berdiri sendiri, tidak memakai resource internal (aturan Modul
 * Pelanggan butir 2). Yang TIDAK ada di sini: siapa orang lab yang meninjau
 * (cuma "tim lab"), organisasi, dan alasan revisi internal.
 *
 * @property KoreksiPelanggan $resource
 */
class KoreksiPelangganResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KoreksiPelanggan $k */
        $k = $this->resource;

        return [
            'id' => $k->id,
            'jenis' => $k->jenis,
            'status' => $k->status,
            'diajukan_oleh' => ['nama' => $k->pengaju?->name],
            'diajukan_pada' => $k->created_at?->toIso8601ZuluString(),
            'alat' => $k->equipment === null ? null : [
                'id' => $k->equipment->id,
                'nama' => $k->equipment->nama_alat,
                'serial' => $k->equipment->serial_number,
            ],
            'sertifikat' => $k->certificate === null ? null : [
                'id' => $k->certificate->id,
                'nomor' => $k->certificate->nomor,
            ],
            // `diterapkan` (kalau lab membetulkan nilainya) ikut tampil —
            // pelanggan berhak tahu yang dicatat berbeda dari yang dia minta.
            'perubahan' => array_values((array) $k->perubahan),
            'catatan' => $k->catatan,
            'foto' => $k->relationLoaded('foto')
                ? $k->foto->map(fn (FotoPelanggan $f) => FotoPelangganLayanan::bentukPelanggan($f))->values()->all()
                : [],
            'tanggapan' => $k->tanggapan,
            'ditinjau_pada' => $k->ditinjau_pada?->toIso8601ZuluString(),
            'revisi' => $k->revisi === null ? null : [
                'id' => $k->revisi->id,
                'nomor' => $k->revisi->nomor,
            ],
        ];
    }
}
