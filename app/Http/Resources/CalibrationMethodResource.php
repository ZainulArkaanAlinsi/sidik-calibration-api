<?php

namespace App\Http\Resources;

use App\Models\CalibrationMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CalibrationMethod
 */
class CalibrationMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'revisi' => $this->revisi,
            // Bentuk siap-cetak: `SIDIK-IK-CAL-0506_Rev.6`. Dikirim jadi field
            // sendiri biar mobile/panel nggak nyusun sendiri & bisa beda format.
            'kode_lengkap' => $this->kodeLengkap(),
            'nama' => $this->nama,
            'kategori' => $this->category ? [
                'id' => $this->category->id,
                'kode' => $this->category->kode,
                'nama' => $this->category->nama,
            ] : null,
            'berlaku_mulai' => $this->berlaku_mulai?->toDateString(),
            'aktif' => $this->aktif,
            'keterangan' => $this->keterangan,
        ];
    }
}
