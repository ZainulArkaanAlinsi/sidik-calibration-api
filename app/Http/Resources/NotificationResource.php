<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Bentuk notifikasi buat halaman notifikasi di mobile (spesifikasi poin 4 & 6).
 *
 * Baris di DB nyimpen payload gaya Filament (title/body/icon/iconColor) supaya
 * lonceng panel admin ikut kebaca dari baris yang SAMA. Penerjemahan ke nama
 * field yang dipakai mobile dilakuin di sini, bukan di database — biar tampilan
 * lama & baru nggak saling ngerusak.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'kategori' => $data['kategori'] ?? 'umum',
            'judul' => $data['title'] ?? null,
            'isi' => $data['body'] ?? null,
            'ikon' => $data['icon'] ?? null,
            'warna' => $data['iconColor'] ?? 'info',
            // Nunjuk layar yang harus kebuka waktu notifikasinya diketuk,
            // mis. `{"tipe": "calibration", "id": 12}`.
            'tautan' => $data['tautan'] ?? null,
            'dibaca' => $this->read_at !== null,
            'dibaca_pada' => $this->read_at?->toIso8601ZuluString(),
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
