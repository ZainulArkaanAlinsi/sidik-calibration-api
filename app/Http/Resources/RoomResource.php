<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Room */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'nama' => $this->nama,
            'lokasi' => $this->lokasi,
            'suhu_min' => $this->suhu_min,
            'suhu_max' => $this->suhu_max,
            'kelembaban_min' => $this->kelembaban_min,
            'kelembaban_max' => $this->kelembaban_max,
            'keterangan' => $this->keterangan,
            'aktif' => $this->aktif,
        ];
    }
}
