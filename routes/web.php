<?php

use App\Http\Controllers\HapusAkunWebController;
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

/*
 * Halaman hapus akun versi WEB — REQ-PRV-03, syarat listing Google Play.
 *
 * Harus bisa dibuka TANPA memasang aplikasinya: buat orang yang HP-nya hilang,
 * yang sudah mencopot aplikasinya, atau yang berhenti sebelum sempat masuk.
 * Kalau satu-satunya jalan lewat dalam aplikasi, listing-nya ditolak.
 *
 * SENGAJA di luar gerbang `fitur.pelanggan`: tautannya terdaftar di Play Store
 * dan tidak boleh mati waktu modulnya dimatikan sementara.
 */
Route::get('/hapus-akun', [HapusAkunWebController::class, 'tampil'])->name('hapus-akun');
Route::post('/hapus-akun', [HapusAkunWebController::class, 'kirim'])
    ->middleware('throttle:hapus-akun-web')
    ->name('hapus-akun.kirim');

// Tanpa auth — memang buat orang luar. Dibatesin 30/menit per IP biar nggak
// dipakai nyisir nomor sertifikat.
Route::get('/verify/{qr_token}', [VerificationController::class, 'show'])
    ->middleware('throttle:verifikasi-halaman')
    ->name('verify');

// Unduh sertifikat langsung dari hasil scan QR (?format=pdf|xlsx). Jatahnya
// dipisah & lebih sedikit dari halaman verifikasi: yang ini bikin file, bukan
// cuma baca satu baris.
Route::get('/verify/{qr_token}/download', [VerificationController::class, 'download'])
    ->middleware('throttle:verifikasi-unduh')
    ->name('verify.download');
