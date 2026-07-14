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
            'role' => $this->role,
            'organization_id' => $this->organization_id,
        ];
    }
}
