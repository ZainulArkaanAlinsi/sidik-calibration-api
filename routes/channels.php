<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels — otorisasi siapa boleh dengerin channel apa
|--------------------------------------------------------------------------
| Dipakai buat realtime sync mobile ↔ desktop. Klien (mobile & panel desktop)
| autentikasi lewat POST /broadcasting/auth (token Sanctum) sebelum subscribe.
*/

// Notifikasi realtime per user — lonceng nyala barengan di HP & desktop.
// Channel bawaan Laravel Notification: App.Models.User.{id}.
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

// Sinkron data per organisasi (sesi kalibrasi, sertifikat, dll). Cuma anggota
// organisasi itu yang boleh dengerin — data lab bersifat privasi internal.
Broadcast::channel('organisasi.{organizationId}', function (User $user, string $organizationId): bool {
    return (int) $user->organization_id === (int) $organizationId;
});
