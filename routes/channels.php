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
//
// Kepemilikan organisasi saja TIDAK cukup, dan itu celah yang sama dengan rute
// internal di `routes/api.php`: akun pelanggan nanti duduk di organisasi PT
// Sidik juga, jadi `organization_id`-nya cocok. Tanpa gerbang role di bawah,
// pelanggan bisa subscribe ke sini dan mendengarkan tiap sesi kalibrasi dan
// sertifikat yang lewat — milik SEMUA pelanggan lab ini, realtime.
//
// Pelanggan memang nggak memakai Reverb di MVP (03-SDD §3.2), tapi "nggak
// dipakai" bukan penjagaan: yang menahan harus kodenya.
Broadcast::channel('organisasi.{organizationId}', function (User $user, string $organizationId): bool {
    return (int) $user->organization_id === (int) $organizationId
        && in_array($user->role, User::roles(), true);
});
