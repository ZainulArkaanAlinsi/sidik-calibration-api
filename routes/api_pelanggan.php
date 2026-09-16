<?php

use App\Http\Controllers\Pelanggan\AppStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Pelanggan — /api/pelanggan/v1
|--------------------------------------------------------------------------
|
| Berkas TERPISAH dari routes/api.php, dan itu aturan keras (CLAUDE.md §Modul
| Pelanggan poin 1). Begitu rute pelanggan bercampur dengan rute internal,
| gerbang `role:` di sana jadi satu-satunya yang memisahkan dua dunia — dan itu
| penjagaan yang terlalu tipis buat kerahasiaan antar pelanggan.
|
| Prefix `api/pelanggan/v1` dipasang waktu didaftarkan di bootstrap/app.php,
| bukan di sini, supaya nggak bisa keliru diketik ulang per grup.
|
| Perubahan MERUSAK pada v1 dilarang (NFR-12): aplikasi yang sudah terpasang di
| HP orang nggak bisa disuruh ikut berubah hari itu juga. Kalau memang harus,
| buat v2.
|
*/

/*
 * DI LUAR gerbang `fitur.pelanggan` — satu-satunya.
 *
 * Aplikasi memanggil ini tiap kali dibuka. Kalau dia ikut 503 waktu fiturnya
 * mati, aplikasi nggak punya cara tahu dirinya usang atau server sedang
 * maintenance — dua layar yang justru paling dibutuhkan saat itu.
 */
Route::get('/app/status', AppStatusController::class)->name('pelanggan.app.status');

Route::middleware('fitur.pelanggan')->group(function () {
    // Rute pelanggan yang butuh flag menyala mendarat di sini mulai Fase 4:
    // auth/daftar, auth/masuk, saya, alat, sertifikat, permintaan, dst.
});
