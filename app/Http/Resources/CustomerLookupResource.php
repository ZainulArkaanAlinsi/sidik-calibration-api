<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk ringkas pelanggan buat dropdown — dipakai `GET /api/customers/lookup`,
 * yang kebuka semua role.
 *
 * Sengaja BUKAN `CustomerResource`. Yang itu buat layar CRUD admin dan bawa
 * `contact_person`, `telepon`, `email`, `jumlah_alat`. Endpoint lookup dipakai
 * teknisi & viewer — role yang nggak boleh ngelola pelanggan, jadi nggak perlu
 * megang kontaknya juga. Kalau dua-duanya pakai satu resource, nambah satu field
 * buat layar admin diam-diam ngebocorin field itu ke semua role.
 *
 * `alamat` ikut karena blok OWNER di lembar kerja butuh, dan itu udah kekirim ke
 * semua role lewat `EquipmentResource.pelanggan` — jadi bukan data baru.
 *
 * @mixin Customer
 */
class CustomerLookupResource extends JsonResource
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
        ];
    }
}
