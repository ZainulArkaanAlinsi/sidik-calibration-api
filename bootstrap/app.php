<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\FiturPelanggan;
use App\Http\Middleware\KonteksPerusahaan;
use App\Http\Middleware\PastikanAplikasi;
use App\Http\Middleware\PelangganAktif;
use App\Http\Middleware\PeranAnggota;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Rute pelanggan didaftarkan TERPISAH, bukan menumpang `api:` di atas.
        //
        // Dua hal yang dibeli dengan ini. Pertama, prefix `api/pelanggan/v1`
        // dipasang di SATU tempat — nggak bisa keliru diketik ulang per grup,
        // dan nggak bisa sebagian rute pelanggan mendarat di luar v1. Kedua,
        // berkas rutenya tetap terpisah secara fisik dari `routes/api.php`,
        // yang jadi aturan keras modul ini (AGENTS.md §Modul Pelanggan poin 1):
        // begitu rute pelanggan bercampur dengan rute internal, gerbang `role:`
        // di sana jadi satu-satunya yang memisahkan dua dunia.
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/pelanggan/v1')
                ->group(base_path('routes/api_pelanggan.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Di produksi, request nyampe ke Laravel lewat proxy (Render/Cloudflare)
        // yang udah ngelepas TLS-nya duluan. Tanpa baris ini Laravel cuma lihat
        // sambungan HTTP polos dari proxy ke container, jadi url()/asset()
        // nyetak `http://` — dan browser nolak aset campur itu di halaman HTTPS,
        // yang munculnya sebagai panel Filament tampil tanpa CSS sama sekali.
        //
        // `at: '*'` aman di sini karena container-nya nggak pernah kena internet
        // langsung: satu-satunya yang bisa nyampe ke dia ya proxy itu sendiri.
        $middleware->trustProxies(at: '*');

        // Backend ini API-only (nggak ada halaman login), jadi guest jangan di-redirect
        // ke route 'login' yang nggak ada — biar langsung jadi 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'fitur.pelanggan' => FiturPelanggan::class,
            'aplikasi' => PastikanAplikasi::class,
            'pelanggan.aktif' => PelangganAktif::class,
            'perusahaan' => KonteksPerusahaan::class,
            'peran' => PeranAnggota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
