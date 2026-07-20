<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Teknisi yang DITUGASKAN ngerjain alat ini. null = belum dibagi.
            // Beda sama teknisi di sesi kalibrasi, yang nyatet siapa yang
            // sudah ngerjain dan kepake di sertifikat.
            'teknisi' => $this->whenLoaded('teknisi', fn () => $this->teknisi ? [
                'id' => $this->teknisi->id,
                'nama' => $this->teknisi->name,
            ] : null),
            'kondisi_terima' => $this->kondisi_terima,
            'kelengkapan' => $this->kelengkapan,
            'catatan' => $this->catatan,
            // Alatnya dibawa utuh lewat EquipmentResource — form Order butuh
            // rentang & satuannya, jadi nggak perlu nembak /equipments lagi
            // satu-satu buat tiap baris.
            'alat' => new EquipmentResource($this->whenLoaded('equipment')),
        ];
    }
}
