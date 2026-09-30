<?php

namespace App\Http\Resources\Pelanggan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Satu notifikasi di kotak masuk aplikasi pelanggan.
 *
 * SENGAJA tidak mewarisi `App\Http\Resources\NotificationResource` walau
 * bentuknya mirip (AGENTS.md §Modul Pelanggan poin 2): field yang kelak
 * ditambahkan ke versi internal — mis. id sesi, nama teknisi — tidak boleh
 * ikut mendarat di HP pelanggan tanpa ada yang sengaja menambahkannya.
 *
 * @property DatabaseNotification $resource
 */
class NotifikasiPelangganResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) ($this->resource->data ?? []);

        return [
            'id' => $this->resource->id,
            'kategori' => $data['kategori'] ?? 'umum',
            'judul' => $data['title'] ?? null,
            'isi' => $data['body'] ?? null,
            'tautan' => $data['tautan'] ?? null,
            'dibaca' => $this->resource->read_at !== null,
            'dibuat_pada' => $this->resource->created_at?->toIso8601ZuluString(),
        ];
    }
}
