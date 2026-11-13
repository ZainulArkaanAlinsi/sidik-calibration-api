<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu sertifikat di garis waktu PELANGGAN (tingkat hari): persis bentuk baris
 * `GET /pelanggan/v1/sertifikat` (`SertifikatPelangganResource`) ditambah
 * `nomor_order`, supaya model baris di aplikasi bisa dipakai ulang.
 *
 * `nomor_order` dibaca dari SNAPSHOT (yang tercetak di PDF), sama dengan
 * identitas alatnya: layar tidak boleh membantah kertas di tangan pelanggan.
 * Tidak mewarisi resource internal (aturan Modul Pelanggan butir 2).
 *
 * @property Certificate $resource
 */
class SertifikatGarisWaktuResource extends JsonResource
{
    /** Sama dengan daftar sertifikat pelanggan: `revisiTerakhir` untuk status dokumen. */
    public const RELASI = ['session:id,equipment_id', 'revisionOf:id,nomor', 'revisiTerakhir'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Certificate $c */
        $c = $this->resource;

        return [
            ...(new SertifikatPelangganResource($c))->resolve($request),
            'nomor_order' => $c->snapshot['header']['order_number'] ?? null,
        ];
    }
}
