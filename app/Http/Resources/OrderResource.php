<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomor' => $this->nomor,
            'status' => $this->status,
            'tanggal_masuk' => $this->tanggal_masuk?->toIso8601ZuluString(),
            'tanggal_janji_selesai' => $this->tanggal_janji_selesai?->toIso8601ZuluString(),
            'catatan' => $this->catatan,
            'pelanggan' => [
                'id' => $this->customer?->id,
                'nama' => $this->customer?->nama,
            ],
            'diterima_oleh' => $this->whenLoaded('penerima', fn () => [
                'id' => $this->penerima?->id,
                'nama' => $this->penerima?->name,
            ]),
            // Dipakai daftar order biar nggak perlu muat semua item cuma buat
            // nampilin "5 alat" di kartu.
            'jumlah_alat' => $this->whenCounted('items'),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
