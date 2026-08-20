<?php

namespace App\Providers;

use App\Services\Push\FcmPengirimPush;
use App\Services\Push\PengirimPush;
use App\Services\Push\PengirimPushMati;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
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
        // Pengirim push. BAWAANNYA yang diam — kredensial FCM cuma ada di
        // server yang beneran, sementara mesin developer, CI, dan test nggak
        // punya. Kalau bawaannya yang asli, tiap notifikasi di lingkungan itu
        // melempar, dan yang gagal bukan push-nya doang tapi seluruh aksi yang
        // memicunya: teknisi nyubmit sesi, admin menyetujui.
        //
        // Firebase di proyek ini statusnya sementara. Menggantinya nanti cukup
        // menukar satu baris ini — `via()`, tabel `device_tokens`, dan endpoint
        // pendaftarannya nggak ikut berubah.
        $this->app->bind(PengirimPush::class, function ($app): PengirimPush {
            $projectId = config('services.fcm.project_id');
            $kredensial = config('services.fcm.credentials');

            // KEDUANYA harus ada DAN berkasnya harus beneran ada. Setengah
            // disetel itu keadaan yang paling berbahaya: pengirim asli
            // terbentuk, tiap kirim gagal, dan yang kelihatan cuma "notifikasi
            // HP nggak masuk" tanpa satu error pun yang nunjuk ke sebabnya.
            if (! $projectId || ! $kredensial || ! is_file($kredensial)) {
                return new PengirimPushMati;
            }

            return new FcmPengirimPush(
                $app->make(HttpFactory::class),
                (string) $projectId,
                (string) $kredensial,
                (int) config('services.fcm.timeout', 10),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimiters();

        // Bawaan Laravel nunjuk ke route web `password.reset` yang nggak ada di
        // project API-only ini. Diganti deep link ke app Flutter — mobile daftarin
        // scheme `sidik://` biar link di email langsung buka layar reset.
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
