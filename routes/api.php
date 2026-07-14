<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
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
    Route::post('/logout', [AuthController::class, 'logout']);
    // Token Sanctum nggak kadaluarsa sendiri — ini caranya matiin sesi di HP
    // yang ilang.
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Baca data alat & kategori: semua role, termasuk viewer.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{kode}', [CategoryController::class, 'show']);
    Route::get('/equipments', [EquipmentController::class, 'index']);
    Route::get('/equipments/{equipment}', [EquipmentController::class, 'show']);

    // Nulis data alat: admin & teknisi. Viewer ditolak 403.
    Route::middleware('role:admin,teknisi')->group(function () {
        Route::post('/equipments', [EquipmentController::class, 'store']);
        Route::put('/equipments/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/equipments/{equipment}', [EquipmentController::class, 'destroy']);
    });

    // Master data & approval akun: admin doang.
    Route::middleware('role:admin')->group(function () {
        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::put('/organization', [OrganizationController::class, 'update']);

        Route::apiResource('customers', CustomerController::class)->except(['show']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);

        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/approve', [UserController::class, 'approve']);
        Route::post('/users/{user}/reject', [UserController::class, 'reject']);
        // Buat kasus yang /forgot-password nggak bisa tolong: emailnya salah ketik.
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
});
