<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'role' => $this->role,
            'status' => $this->status,
            'department' => $this->department,
            // Gambar tanda tangan buat sertifikat. null = belum diunggah;
            // sertifikat tetap terbit, cuma nama & jabatan yang tercetak.
            'ttd_url' => $this->ttd_path ? Storage::disk('public')->url($this->ttd_path) : null,
            'organization_id' => $this->organization_id,
        ];
    }
}
