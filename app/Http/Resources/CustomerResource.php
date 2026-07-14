<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
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
            'contact_person' => $this->contact_person,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'jumlah_alat' => $this->whenCounted('equipments'),
        ];
    }
}
