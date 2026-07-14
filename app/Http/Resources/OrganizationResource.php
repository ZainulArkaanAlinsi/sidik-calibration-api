<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'alamat' => $this->alamat,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'no_akreditasi' => $this->no_akreditasi,
            'standar_akreditasi' => $this->standar_akreditasi,
            'akreditasi_mulai' => $this->akreditasi_mulai?->toIso8601ZuluString(),
            'akreditasi_berakhir' => $this->akreditasi_berakhir?->toIso8601ZuluString(),
            'akreditasi_masih_berlaku' => $this->akreditasiMasihBerlaku(),
            'settings' => $this->settings,
        ];
    }
}
