<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            // Logo kop sertifikat. Disimpen di disk `public`, jadi URL-nya bisa
            // dibuka langsung tanpa token — nggak ada yang rahasia di logo lab.
            // null = belum pernah diunggah; PDF sertifikat jatuh ke logo bawaan.
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'settings' => $this->settings,
        ];
    }
}
