<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Bentuk notifikasi buat mobile (kontrak Fase 2 §2).
 *
 * Tabel `notifications` diisi DUA sumber dengan bentuk payload beda:
 *
 *  - `App\Notifications\NotifikasiApp` (app ini)   → `tipe`/`judul`/`isi`/`tautan`
 *  - Lonceng panel Filament (`sendToDatabase()`)   → `title`/`body`/`icon`/`color`
 *
 * Keduanya diseragamkan di sini, jadi notifikasi yang dulu cuma kebaca di panel
 * (mis. pengingat jatuh tempo dari `alat:cek-jatuh-tempo`) ikut kelihatan di
 * mobile — tanpa perlu ngirim notifikasi dobel atau ngubah command yang udah jalan.
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
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            // Notifikasi Filament nggak punya `tipe` — dikasih `sistem` biar
            // mobile tetap bisa nge-grup tanpa perlu tahu asal-usulnya.
            'tipe' => $data['tipe'] ?? 'sistem',
            'judul' => $data['judul'] ?? $data['title'] ?? '',
            'isi' => $data['isi'] ?? $data['body'] ?? '',
            // Null berarti nggak ada layar tujuan — mobile nampilin teksnya aja,
            // nggak bikin baris itu bisa di-tap.
            'tautan' => $data['tautan'] ?? null,
            'dibaca_pada' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
