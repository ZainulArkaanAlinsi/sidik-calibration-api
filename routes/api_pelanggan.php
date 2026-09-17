<?php

use App\Http\Controllers\Pelanggan\AnggotaController;
use App\Http\Controllers\Pelanggan\AppStatusController;
use App\Http\Controllers\Pelanggan\AuthPelangganController;
use App\Http\Controllers\Pelanggan\SayaController;
use App\Models\CustomerMember;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Pelanggan — /api/pelanggan/v1
|--------------------------------------------------------------------------
|
| Berkas TERPISAH dari routes/api.php, dan itu aturan keras (AGENTS.md §Modul
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
| ## Tiga lapis gerbang, dan masing-masing menjawab hal yang berbeda
|
| 1. `fitur.pelanggan` — modulnya menyala atau tidak (M1-02).
| 2. `aplikasi:pelanggan` — token ini dikeluarkan buat aplikasi pelanggan, bukan
|    buat aplikasi teknisi (M1-03).
| 3. `pelanggan.aktif` — pemilik tokennya sudah ditautkan admin ke sebuah
|    perusahaan (REQ-AUTH-03).
|
| Yang ketiga SENGAJA tidak dipasang di grup "butuh token": `GET /saya` harus
| tetap kebuka buat akun yang menunggu verifikasi, kalau tidak layar S06 tidak
| punya cara membaca statusnya sendiri.
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

    /*
     * --- Tanpa token -----------------------------------------------------
     *
     * Tiap rute punya throttle SENDIRI, bukan satu ember bersama. Ember
     * bersama bikin banjir di `daftar` ikut mengunci `masuk` — jadi serangan
     * ke pintu pendaftaran menutup pintu masuk buat pelanggan yang sah.
     *
     * Jalur OTP pun dipecah dua: MEMERIKSA kode (`pelanggan-otp-periksa`) dan
     * MENGIRIM kode (`pelanggan-otp-kirim`). Satu ember bersama bikin penyerang
     * yang menebak kode menghabiskan jatah "kirim ulang" milik korban, dan
     * sebaliknya. Alasan lengkapnya di `AppServiceProvider::rateLimiters()`.
     */
    Route::prefix('auth')->name('pelanggan.auth.')->group(function () {
        Route::post('/daftar', [AuthPelangganController::class, 'daftar'])
            ->middleware('throttle:pelanggan-daftar')
            ->name('daftar');

        Route::post('/verifikasi-email', [AuthPelangganController::class, 'verifikasiEmail'])
            ->middleware('throttle:pelanggan-otp-periksa')
            ->name('verifikasi-email');

        Route::post('/kirim-ulang-otp', [AuthPelangganController::class, 'kirimUlangOtp'])
            ->middleware('throttle:pelanggan-otp-kirim')
            ->name('kirim-ulang-otp');

        Route::post('/masuk', [AuthPelangganController::class, 'masuk'])
            ->middleware('throttle:pelanggan-masuk')
            ->name('masuk');

        Route::post('/terima-undangan', [AuthPelangganController::class, 'terimaUndangan'])
            ->middleware('throttle:pelanggan-undangan-tukar')
            ->name('terima-undangan');

        Route::post('/lupa-sandi', [AuthPelangganController::class, 'lupaSandi'])
            ->middleware('throttle:pelanggan-otp-kirim')
            ->name('lupa-sandi');

        Route::post('/atur-ulang-sandi', [AuthPelangganController::class, 'aturUlangSandi'])
            ->middleware('throttle:pelanggan-otp-periksa')
            ->name('atur-ulang-sandi');
    });

    /*
     * --- Butuh token, TERMASUK akun yang menunggu verifikasi --------------
     *
     * Isinya sengaja sempit: cuma profil sendiri dan keluar. Tidak ada data
     * perusahaan di sini sama sekali, jadi akun `pending_verifikasi` yang lolos
     * ke grup ini tidak bisa melihat apa pun milik pelanggan lain.
     */
    Route::middleware(['auth:sanctum', 'aplikasi:pelanggan'])->group(function () {
        Route::post('/auth/keluar', [AuthPelangganController::class, 'keluar'])
            ->name('pelanggan.auth.keluar');

        Route::post('/auth/keluar-semua', [AuthPelangganController::class, 'keluarSemua'])
            ->name('pelanggan.auth.keluar-semua');

        Route::get('/saya', [SayaController::class, 'tampil'])->name('pelanggan.saya.tampil');
        Route::patch('/saya', [SayaController::class, 'perbarui'])->name('pelanggan.saya.perbarui');

        // REQ-AUTH-11. Di grup yang SAMA dengan `/saya`, bukan di grup
        // `pelanggan.aktif`: akun yang masih menunggu verifikasi juga berhak
        // menghapus akunnya — menahannya sampai disetujui admin berarti orang
        // yang ditolak terjebak dengan data pribadi yang tidak bisa dia cabut.
        Route::delete('/saya', [SayaController::class, 'hapus'])
            ->middleware('throttle:pelanggan-sandi')
            ->name('pelanggan.saya.hapus');

        Route::post('/saya/ganti-sandi', [SayaController::class, 'gantiSandi'])
            ->middleware('throttle:pelanggan-sandi')
            ->name('pelanggan.saya.ganti-sandi');
    });

    /*
     * --- Butuh token DAN akun yang sudah diverifikasi ---------------------
     *
     * Kosong sampai Fase 5: `/beranda`, `/alat`, `/sertifikat`, `/permintaan`,
     * `/anggota` semuanya mendarat di sini. Grupnya sudah berdiri sekarang
     * supaya rute data yang ditambahkan nanti mewarisi `pelanggan.aktif`
     * otomatis — mendaftarkannya di grup atas tanpa sadar itu persis kelas
     * kelalaian yang bikin REQ-AUTH-03 bocor tanpa satu pun error.
     */
    Route::middleware(['auth:sanctum', 'aplikasi:pelanggan', 'pelanggan.aktif', 'perusahaan'])->group(function () {
        /*
         * --- Anggota perusahaan (REQ-ANG-01..03) -------------------------
         *
         * `perusahaan` di grup, `peran:pic_utama` per rute. Yang MELIHAT boleh
         * semua peran (REQ-ANG-03); yang MENGUBAH keanggotaan cuma PIC utama.
         *
         * Dipasang sebagai middleware, bukan `if` di controller, supaya
         * aturannya terbaca dari daftar rute — bisa disapu test, dan rute baru
         * yang lupa dipagari kelihatan tanpa harus membaca badan controller.
         */
        Route::get('/anggota', [AnggotaController::class, 'index'])->name('pelanggan.anggota.index');

        Route::middleware('peran:'.CustomerMember::PERAN_PIC_UTAMA)->group(function () {
            Route::post('/anggota/undangan', [AnggotaController::class, 'undang'])
                ->middleware('throttle:pelanggan-undang-anggota')
                ->name('pelanggan.anggota.undang');

            Route::delete('/anggota/undangan/{undangan}', [AnggotaController::class, 'batalkanUndangan'])
                ->name('pelanggan.anggota.batal-undangan');

            Route::post('/anggota/{anggota}/nonaktifkan', [AnggotaController::class, 'nonaktifkan'])
                ->name('pelanggan.anggota.nonaktifkan');
        });
    });
});
