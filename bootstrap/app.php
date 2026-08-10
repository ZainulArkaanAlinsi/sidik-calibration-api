<?php

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
