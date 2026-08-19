<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuknya dikunci sama docs/kontrak-api.md di repo mobile — nama field pakai
 * bahasa Indonesia (`nama`, bukan `name`). Jangan diubah tanpa ngabarin mobile.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            // "Technician ID" yang tercetak di lembar kerja & sertifikat —
            // inisial (`JO`), bukan `SDK-0001`. Dikirim dari sini biar yang
            // dilihat teknisi di HP sama persis dengan yang dibekukan di
            // snapshot: dua-duanya lewat `User::kodeTeknisi()`, bukan dihitung
            // ulang di layar.
            'kode_teknisi' => $this->kodeTeknisi(),
            'role' => $this->role,
            'status' => $this->status,
            'department' => $this->department,
            'organization_id' => $this->organization_id,
        ];
    }
}
