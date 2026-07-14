<?php

use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
| Project ini mobile-only — NGGAK ada panel admin web (lihat vault:
| "01 - Ringkasan Project"). Yang ada di sini cuma dua halaman publik, karena
| QR di sertifikat discan orang luar pakai kamera HP biasa: yang kebuka browser,
| bukan aplikasi kita.
*/

Route::get('/', [VerificationController::class, 'beranda']);

// Tanpa auth — memang buat orang luar. Dibatesin 30/menit per IP biar nggak
// dipakai nyisir nomor sertifikat.
Route::get('/verify/{qr_token}', [VerificationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('verify');
