<?php

namespace App\Http\Resources;

use App\Models\FotoPelanggan;
use App\Models\KoreksiPelanggan;
use App\Services\Pelanggan\FotoPelangganLayanan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Koreksi data dari pelanggan dilihat ADMIN lab (§42 A4).
 *
 * Beda dari versi pelanggan (`Pelanggan\KoreksiPelangganResource`, yang TIDAK
 * memakai yang ini): di sini ada perusahaan, pengaju, dan siapa peninjaunya.
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
            'pelanggan' => $k->customer === null ? null : ['id' => $k->customer->id, 'nama' => $k->customer->nama],
            'diajukan_oleh' => $k->pengaju === null ? null : ['id' => $k->pengaju->id, 'nama' => $k->pengaju->name],
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
            'perubahan' => array_values((array) $k->perubahan),
            'catatan' => $k->catatan,
            'foto' => $k->relationLoaded('foto')
                ? $k->foto->map(fn (FotoPelanggan $f) => FotoPelangganLayanan::bentukLab($f))->values()->all()
                : [],
            'tanggapan' => $k->tanggapan,
            'ditinjau_oleh' => $k->peninjau === null ? null : ['id' => $k->peninjau->id, 'nama' => $k->peninjau->name],
            'ditinjau_pada' => $k->ditinjau_pada?->toIso8601ZuluString(),
            'revisi' => $k->revisi === null ? null : [
                'id' => $k->revisi->id,
                'nomor' => $k->revisi->nomor,
                'status' => $k->revisi->status,
            ],
        ];
    }
}
