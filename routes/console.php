<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tiap pagi kabarin admin soal alat yang lewat/mendekati jatuh tempo.
// Butuh `php artisan schedule:work` (dev) atau cron ke `schedule:run` (prod).
Schedule::command('alat:cek-jatuh-tempo')->dailyAt('07:00');

// Sertifikat standar acuan yang mendekati/udah habis. Dipisah 5 menit dari yang
// di atas biar dua notifikasi nggak nyampe di detik yang sama dan salah satunya
// ketimbun di lonceng. Pengulangannya ditahan `PenjagaNotifikasiUlang` — isi yang
// sama nggak diulang seminggu, tapi begitu ada standar yang statusnya berubah,
// dikabarin saat itu juga.
Schedule::command('standar:cek-kadaluarsa')->dailyAt('07:05');

// Buang citra pindai lembar kerja yang lewat batas retensi `config/ocr.php`.
// Jam 02:30 karena dia menyentuh disk & menghapus berkas: dijalankan waktu
// tidak ada teknisi yang lagi memindai, dan jauh dari dua pengingat pagi di
// atas supaya kegagalannya tidak tertimbun di antara notifikasi.
//
// `withoutOverlapping`: satu lab bisa punya puluhan ribu pindai, dan jalan
// dua kali berbarengan berarti dua proses menghapus berkas yang sama —
// yang kedua melihat berkas hilang dan melaporkannya sebagai anomali.
Schedule::command('ocr:bersihkan-citra')->dailyAt('02:30')->withoutOverlapping();

// Sertifikat yang tersangkut di `menunggu_generate` tanpa PDF didorong ulang.
//
// Ini penutup satu-satunya untuk kegagalan yang tidak berbunyi: kalau worker
// antrean mati, approve tetap 200 OK tapi sertifikatnya berhenti selamanya —
// dan `CertificateController::retry()` menolak apa pun yang bukan `gagal`, jadi
// admin tidak punya tombol untuk memulihkannya. 21 Sep 2026 ada 9 baris
// produksi dalam keadaan itu, `failed_jobs` nol.
//
// Sepuluh menit, bukan tiap menit: perintahnya sendiri baru menganggap sebuah
// sertifikat tersangkut sesudah diam 15 menit, jadi memeriksa lebih sering cuma
// menambah query tanpa mempercepat pemulihan apa pun.
Schedule::command('sertifikat:sapu-tertunda')->everyTenMinutes()->withoutOverlapping();
