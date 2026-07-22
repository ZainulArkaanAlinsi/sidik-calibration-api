<?php

use App\Http\Controllers\Api\ArsipController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalibrationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StandardController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Publik (tanpa auth)
|--------------------------------------------------------------------------
| Semuanya dibatesin per menit per IP — nahan brute force & spam.
*/

// Dipakai mobile buat mastiin sambungan ke API jalan.
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->utc()->toIso8601ZuluString(),
]));

// Limiter-nya BERNAMA (didaftarin di AppServiceProvider), bukan `throttle:5,1`.
// Kalau pakai yang angka, semua endpoint publik berbagi satu jatah per IP —
// salah password beberapa kali bikin /forgot-password ikut kena 429.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

// Verifikasi QR sertifikat — buat orang luar, tanpa auth (versi JSON-nya;
// versi halaman webnya ada di routes/web.php).
Route::get('/verify/{qr_token}', [VerificationController::class, 'show'])->middleware('throttle:30,1');

/*
|--------------------------------------------------------------------------
| Butuh login
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    // Ability pemanggil — dipakai mobile buat nyembunyiin tombol yang bakal
    // ditolak 403. Lihat App\Support\Permissions & MATRIKS-PERAN.md.
    Route::get('/me/permissions', [AuthController::class, 'permissions']);
    // Unggah tanda tangan SENDIRI buat sertifikat (multipart, field `ttd`).
    Route::post('/me/ttd', [AuthController::class, 'uploadTtd']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Token Sanctum nggak kadaluarsa sendiri — ini caranya matiin sesi di HP
    // yang ilang.
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    // Notifikasi milik pemanggil. Polling aja (mobile refresh waktu app dibuka),
    // belum pakai push. `?belum_dibaca=1` buat badge angka di lonceng.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/baca-semua', [NotificationController::class, 'bacaSemua']);
    Route::post('/notifications/{id}/baca', [NotificationController::class, 'baca']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    // Deret waktu masuk-vs-selesai, rentang & granularitas bebas — dipakai
    // grafik di Dashboard sama halaman Perhitungan. Agregasinya di sini biar
    // mobile nggak narik ribuan baris cuma buat gambar belasan titik.
    Route::get('/dashboard/tren', [DashboardController::class, 'tren']);

    // Baca data alat & kategori: semua role, termasuk viewer.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{kode}', [CategoryController::class, 'show']);
    Route::get('/equipments', [EquipmentController::class, 'index']);
    Route::get('/equipments/{equipment}', [EquipmentController::class, 'show']);

    // Standar acuan milik lab — buat dropdown "Standar Acuan" di layar kalibrasi.
    Route::get('/standards', [StandardController::class, 'index']);
    Route::get('/standards/{standard}', [StandardController::class, 'show']);

    // Ruangan lab: bacanya semua role — teknisi butuh buat dropdown "Ruangan"
    // waktu ngisi sesi. Nulisnya admin doang, di blok bawah.
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);

    // Order kalibrasi: bacanya semua role — teknisi butuh lihat alat apa aja
    // yang masuk dan harus dikerjain. Nulisnya admin doang (meja penerimaan),
    // di blok bawah.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // Baca sesi kalibrasi: semua role — tapi teknisi cuma dapat sesi miliknya
    // sendiri. Penyaringnya di controller, bukan di query param dari mobile.
    Route::get('/calibrations', [CalibrationController::class, 'index']);
    Route::get('/calibrations/{calibration}', [CalibrationController::class, 'show']);

    // Sertifikat terbit: semua role bisa lihat & unduh — teknisi cuma miliknya
    // sendiri (scope di controller). PDF-nya di disk privat, cuma bisa lewat sini.
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');

    // Arsip = tampilan "file manager" atas data yang udah ada:
    // perusahaan → alat → berkas (sesi + sertifikat). Baca-baca buat semua role;
    // level berkas disaring per-teknisi di controller. Lihat ArsipController.
    Route::get('/arsip/perusahaan', [ArsipController::class, 'perusahaan']);
    Route::get('/arsip/perusahaan/{customer}', [ArsipController::class, 'alat']);
    Route::get('/arsip/alat/{equipment}', [ArsipController::class, 'berkas']);

    // Folder arsip yang disusun bebas user — pohon beneran, sedalam apa pun.
    // Bacanya semua role (berkas tetap disaring per-teknisi di controller);
    // nyusunnya admin & teknisi, viewer ditolak 403. Lihat FolderController.
    Route::get('/arsip/perusahaan/{customer}/folder', [FolderController::class, 'akar']);
    Route::get('/arsip/folders/{folder}', [FolderController::class, 'show']);

    // Nulis data alat & sesi kalibrasi: admin & teknisi. Viewer ditolak 403.
    Route::middleware('role:admin,teknisi')->group(function () {
        // Nyusun folder arsip. Bikin/rename/pindah/hapus — folder akar
        // perusahaan dikunci sistem, lihat FolderController.
        Route::post('/arsip/folders', [FolderController::class, 'store']);
        Route::put('/arsip/folders/{folder}', [FolderController::class, 'update']);
        Route::put('/arsip/folders/{folder}/pindah', [FolderController::class, 'pindah']);
        Route::put('/arsip/berkas/{calibration}/pindah', [FolderController::class, 'pindahBerkas']);

        Route::post('/equipments', [EquipmentController::class, 'store']);
        Route::put('/equipments/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/equipments/{equipment}', [EquipmentController::class, 'destroy']);

        Route::post('/calibrations', [CalibrationController::class, 'store']);
        // Buat ngerjain ulang sesi yang ditolak admin, atau nerusin draft.
        Route::put('/calibrations/{calibration}', [CalibrationController::class, 'update']);

        // Upload foto display alat buat pembacaan OCR → balikin photo_path.
        Route::post('/calibrations/photos', [CalibrationController::class, 'uploadPhoto']);
        // Konfirmasi pembacaan OCR (is_verified) — syarat sebelum sesi di-approve.
        Route::post(
            '/calibrations/{calibration}/measurements/verify',
            [CalibrationController::class, 'verifyMeasurements'],
        );
    });

    // Approval kalibrasi & master data: admin doang.
    Route::middleware('role:admin')->group(function () {
        // Sesi FAIL tetap boleh di-approve — sertifikatnya terbit dengan hasil
        // "tidak laik pakai". Yang beda keputusannya, bukan boleh/nggaknya terbit.
        Route::post('/calibrations/{calibration}/approve', [CalibrationController::class, 'approve']);
        Route::post('/calibrations/{calibration}/reject', [CalibrationController::class, 'reject']);

        // Terbitin ulang sertifikat yang generate-nya gagal. Penerbitan = admin,
        // sejalan sama approve. Ini yang nyalain tombol retry di mobile.
        Route::post('/certificates/{certificate}/retry', [CertificateController::class, 'retry']);

        // Hapus folder arsip: ADMIN DOANG, bukan teknisi. Teknisi boleh nyusun
        // (bikin/rename/pindah) tapi nggak boleh ngilangin — arsip lab itu
        // barang audit, dan folder bisa berisi sertifikat yang jadi bukti
        // akreditasi KAN. Sengaja lebih ketat dari route arsip yang lain.
        Route::delete('/arsip/folders/{folder}', [FolderController::class, 'destroy']);

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::put('/organization', [OrganizationController::class, 'update']);
        // Logo kop sertifikat (multipart, field `logo`).
        Route::post('/organization/logo', [OrganizationController::class, 'uploadLogo']);

        // Standar acuan: bacanya semua role (di atas), nulisnya admin doang —
        // salah ngetik ketidakpastian di sini bikin SEMUA sertifikat yang pakai
        // standar itu ikut salah.
        Route::post('/standards', [StandardController::class, 'store']);
        Route::put('/standards/{standard}', [StandardController::class, 'update']);
        Route::delete('/standards/{standard}', [StandardController::class, 'destroy']);

        Route::apiResource('customers', CustomerController::class)->except(['show']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);

        // Ruangan: bacanya udah didaftarin di atas buat semua role, di sini
        // tinggal yang nulis.
        Route::apiResource('rooms', RoomController::class)->only(['store', 'update', 'destroy']);

        // Order: bacanya udah didaftarin di atas buat semua role, di sini
        // tinggal yang nulis — pencatatan alat masuk itu kerjaan meja depan.
        Route::apiResource('orders', OrderController::class)->only(['store', 'update', 'destroy']);
        // Bagi-bagi kerjaan ke teknisi tanpa ngirim ulang seluruh order —
        // aksinya sering, payload `PUT` lengkap kemahalan buat ini.
        Route::post('/orders/{order}/penugasan', [OrderController::class, 'penugasan']);

        // Master data teknisi. Beda sama /users yang ngurusin approval akun:
        // yang ini khusus akun role `teknisi` dan bawa jumlah kalibrasinya,
        // buat layar "Data Teknisi" di mobile.
        Route::apiResource('technicians', TechnicianController::class);

        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/approve', [UserController::class, 'approve']);
        Route::post('/users/{user}/reject', [UserController::class, 'reject']);
        // Buat kasus yang /forgot-password nggak bisa tolong: emailnya salah ketik.
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
});
