<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalibrationController;
use App\Http\Controllers\Api\CalibrationMethodController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\FolderFileController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StandardController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\WorksheetExtractionController;
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
    Route::post('/logout', [AuthController::class, 'logout']);
    // Token Sanctum nggak kadaluarsa sendiri — ini caranya matiin sesi di HP
    // yang ilang.
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Notifikasi — ikonnya pindah ke atas layar & punya halaman sendiri
    // (spesifikasi poin 4). Semua role: tiap orang cuma dapat notifikasinya sendiri.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Folder Manager (spesifikasi poin 3 & 7). Bacanya semua role — menunya ada
    // di navbar bawah teknisi; isinya disaring per-role di controller.
    Route::get('/folders', [FolderController::class, 'index']);
    Route::get('/folders/{folder}', [FolderController::class, 'show']);
    // Alias `/arsip/*` yang dipanggil mobile (docs/permintaan-backend-2026-07-24.md §2).
    // Folder akar = per-PT, jadi daftar "perusahaan" = index tanpa parent_id.
    Route::get('/arsip/perusahaan', [FolderController::class, 'index']);
    Route::get('/arsip/folders/{folder}', [FolderController::class, 'show']);
    Route::get('/folder-files', [FolderFileController::class, 'index']);
    Route::get('/folder-files/{folderFile}/download', [FolderFileController::class, 'download'])
        ->name('folder-files.download');

    // Metode kalibrasi (IK) — bacanya semua role buat dropdown & tampilan detail.
    Route::get('/calibration-methods', [CalibrationMethodController::class, 'index']);
    Route::get('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'show']);

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

    // Bentuk baku lembar kerja (SIDIK-FM-CAL-0509_Rev.4) buat layar input
    // teknisi. Didaftarin SEBELUM `/calibrations/{calibration}` biar
    // "lembar-kerja" nggak kebaca sebagai id sesi.
    Route::get('/calibrations/lembar-kerja', [CalibrationController::class, 'lembarKerja']);

    // Baca sesi kalibrasi: semua role — tapi teknisi cuma dapat sesi miliknya
    // sendiri. Penyaringnya di controller, bukan di query param dari mobile.
    Route::get('/calibrations', [CalibrationController::class, 'index']);
    Route::get('/calibrations/{calibration}', [CalibrationController::class, 'show']);

    // Sertifikat terbit: semua role bisa lihat & unduh — teknisi cuma miliknya
    // sendiri (scope di controller). PDF-nya di disk privat, cuma bisa lewat sini.
    Route::get('/certificates', [CertificateController::class, 'index']);
    // Rekap banyak sertifikat — didaftarin SEBELUM `/certificates/{certificate}`
    // biar "export" nggak kebaca sebagai id sertifikat.
    Route::get('/certificates/export/excel', [CertificateController::class, 'exportRekap'])
        ->middleware('role:admin');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);
    Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');
    // Export Excel & gambar QR per sertifikat (spesifikasi poin 10 & 13).
    Route::get('/certificates/{certificate}/excel', [CertificateController::class, 'exportExcel'])
        ->name('certificates.excel');
    Route::get('/certificates/{certificate}/qr', [CertificateController::class, 'qr'])
        ->name('certificates.qr');

    // Nulis data alat & sesi kalibrasi: admin & teknisi. Viewer ditolak 403.
    Route::middleware('role:admin,teknisi')->group(function () {
        Route::post('/equipments', [EquipmentController::class, 'store']);
        Route::put('/equipments/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/equipments/{equipment}', [EquipmentController::class, 'destroy']);

        Route::post('/calibrations', [CalibrationController::class, 'store']);
        // Buat ngerjain ulang sesi yang ditolak admin, atau nerusin draft.
        Route::put('/calibrations/{calibration}', [CalibrationController::class, 'update']);

        // Upload foto display alat → balikin photo_path.
        Route::post('/calibrations/photos', [CalibrationController::class, 'uploadPhoto']);

        // AI Vision: foto tabel lembar kerja → { baris: [...] } + skor keyakinan
        // per sel (gantinya OCR di HP). Hasilnya buat dikonfirmasi teknisi, BUKAN
        // langsung disimpen — submit final tetap lewat POST/PUT /calibrations.
        // Path ngikut SPEC-vision-prompt.md §8 (yang dipanggil mobile).
        Route::post('/raw-measurements/extract-from-photo', [WorksheetExtractionController::class, 'extract'])
            ->middleware('throttle:30,1');

        // Konfirmasi pembacaan hasil pindai (is_verified) — syarat sebelum approve.
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

        // Hitung ulang & periksa tanpa nyetujuin (spesifikasi poin 11) — buat
        // tombol "Periksa" sebelum admin mutusin.
        Route::get('/calibrations/{calibration}/validasi', [CalibrationController::class, 'validasi']);

        // Lembar PERHITUNGAN: tampilan admin dari lembar kerja teknisi.
        Route::get('/calibrations/{calibration}/perhitungan', [CalibrationController::class, 'perhitungan']);

        // Field administratif sertifikat (Order Number, IK, thermohygro, dst) —
        // dihapus dari layar teknisi, diisi di sini (spesifikasi poin 1 & 12A).
        Route::patch('/calibrations/{calibration}/admin', [CalibrationController::class, 'updateAdminFields']);

        // Terbitin ulang sertifikat yang generate-nya gagal. Penerbitan = admin,
        // sejalan sama approve. Ini yang nyalain tombol retry di mobile.
        Route::post('/certificates/{certificate}/retry', [CertificateController::class, 'retry']);

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::put('/organization', [OrganizationController::class, 'update']);

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

        // Master data teknisi. Beda sama /users yang ngurusin approval akun:
        // yang ini khusus akun role `teknisi` dan bawa jumlah kalibrasinya,
        // buat layar "Data Teknisi" di mobile.
        Route::apiResource('technicians', TechnicianController::class);

        // Master data Instruksi Kerja — "Calibration Method" di sertifikat.
        Route::post('/calibration-methods', [CalibrationMethodController::class, 'store']);
        Route::put('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'update']);
        Route::delete('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'destroy']);

        // Folder Manager: yang nulis admin doang. Bacanya udah didaftarin di atas.
        Route::post('/folders', [FolderController::class, 'store']);
        Route::put('/folders/{folder}', [FolderController::class, 'update']);
        Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);
        // Alias `/arsip/*` (docs/permintaan-backend-2026-07-24.md §2) — handler sama.
        Route::put('/arsip/folders/{folder}', [FolderController::class, 'update']);
        Route::delete('/arsip/folders/{folder}', [FolderController::class, 'destroy']);
        Route::post('/folder-files', [FolderFileController::class, 'store']);
        Route::put('/folder-files/{folderFile}', [FolderFileController::class, 'update']);
        Route::delete('/folder-files/{folderFile}', [FolderFileController::class, 'destroy']);

        // Import Excel buat masa transisi (spesifikasi poin 12C).
        Route::get('/imports/format', [ImportController::class, 'format']);
        Route::post('/imports/excel', [ImportController::class, 'excel']);

        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/approve', [UserController::class, 'approve']);
        Route::post('/users/{user}/reject', [UserController::class, 'reject']);
        // Buat kasus yang /forgot-password nggak bisa tolong: emailnya salah ketik.
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
});
