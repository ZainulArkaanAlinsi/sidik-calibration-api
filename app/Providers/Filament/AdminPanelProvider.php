<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('SIDIK Kalibrasi')
            // Logo resmi Sidik di layar login & topbar panel. brandName tetap
            // dipertahankan sebagai teks alt/fallback.
            ->brandLogo(asset('images/logo-sidik.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/logo-sidik.png'))
            // Lonceng notifikasi di panel + polling tiap 30 detik, biar pengingat
            // jatuh tempo (command harian) langsung kelihatan.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            /*
             * Tema kustom. Filament NGGAK memakai `resources/css/app.css` —
             * dia punya entry point sendiri, dan sampai tema ini dibuat
             * panelnya jalan sepenuhnya dengan tampilan bawaan.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')
            /*
             * Kobalt #043EA1 — diambil langsung dari perisai
             * `public/images/logo-sidik.png`, bukan dipilih dari daftar warna
             * Tailwind. Sebelumnya `Color::Blue` (biru-600, #2563EB): biru yang
             * jauh lebih terang dan generik.
             *
             * Warna semantiknya SENGAJA dibiarkan bawaan Filament dan terpisah
             * dari aksen. `danger` di panel ini artinya "ada yang salah" —
             * bukan merah KAN. Merah akreditasi (#C8102E) cuma dipakai lewat
             * kelas `.lencana-kan` di tema, dan cuma buat hal yang benar-benar
             * menyangkut akreditasi: lencana LK-285-IDN, lantai CMC, ruang
             * lingkup. Kalau merah yang sama juga jadi warna error, satu-satunya
             * tanda yang berarti "dokumen ini terakreditasi" ikut luntur.
             */
            ->colors([
                'primary' => Color::hex('#043EA1'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            /*
             * `FilamentInfoWidget` DIBUANG, bukan kelupaan.
             *
             * Isinya nomor versi Filament berikut tautan ke dokumentasinya —
             * perancah bawaan `filament:install` yang ikut naik ke produksi.
             * Admin lab nggak punya kepentingan apa pun dengan itu, dan dia
             * memakan separuh baris pertama dashboard yang mestinya buat
             * pekerjaan yang menunggu.
             *
             * `RingkasanStats` ketemu sendiri lewat `discoverWidgets()` di
             * atas, jadi nggak perlu didaftarkan di sini.
             */
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
