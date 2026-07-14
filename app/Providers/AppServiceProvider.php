<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimiters();

        // Bawaan Laravel nunjuk ke route web `password.reset` yang nggak ada di
        // project API-only ini. Diganti deep link ke app Flutter — mobile daftarin
        // scheme `asmo://` biar link di email langsung buka layar reset.
        // Waktu dev MAIL_MAILER=log, jadi linknya nongol di storage/logs/laravel.log.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token) => sprintf(
            '%s?token=%s&email=%s',
            config('app.reset_password_url'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));
    }

    /**
     * Jatah rate limit DIPISAH per endpoint.
     *
     * Kalau cuma pakai `throttle:5,1` bawaan, semua endpoint publik bakal berbagi
     * satu jatah: buat request tanpa login, Laravel bikin kuncinya dari
     * sha1(domain|ip) — nama route-nya nggak ikut. Akibatnya orang yang salah
     * password beberapa kali langsung kena 429 waktu mau minta reset password,
     * padahal dia belum pernah manggil endpoint itu sama sekali.
     *
     * Dengan limiter bernama, tiap endpoint punya ember sendiri.
     */
    private function rateLimiters(): void
    {
        $perMenit = fn (string $nama, int $jumlah) => RateLimiter::for(
            $nama,
            fn (Request $request) => Limit::perMinute($jumlah)
                ->by($nama.'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Kebanyakan percobaan. Tunggu sebentar, terus coba lagi.',
                ], 429)),
        );

        $perMenit('login', 10);
        $perMenit('register', 5);
        $perMenit('password-reset', 5);
    }
}
