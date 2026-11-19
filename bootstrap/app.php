<?php

use App\Http\Middleware\CatatAksesLintasOrganisasi;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\FiturPelanggan;
use App\Http\Middleware\IdentitasKlien;
use App\Http\Middleware\KonteksPerusahaan;
use App\Http\Middleware\PastikanAkunStudio;
use App\Http\Middleware\PastikanAplikasi;
use App\Http\Middleware\PelangganAktif;
use App\Http\Middleware\PeranAnggota;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'studio' => PastikanAkunStudio::class,
            'pelanggan.aktif' => PelangganAktif::class,
            'perusahaan' => KonteksPerusahaan::class,
            'peran' => PeranAnggota::class,
        ]);

        // Jaring pengaman isolasi antar-lab, dipasang di grup `api` supaya dia
        // menutupi rute yang ditulis besok oleh orang yang belum membaca
        // `PenjagaOrganisasi`. Cuma bekerja pada respons 404 dari pemanggil yang
        // sudah login — biayanya nol untuk request yang berhasil — dan TIDAK
        // pernah mengubah respons (404 tidak dinaikkan jadi 403).
        $middleware->appendToGroup('api', CatatAksesLintasOrganisasi::class);

        // ST-T-013: `X-Klien`/`X-Versi-Klien` ke konteks log, dan 426
        // `klien_kedaluwarsa` untuk Sidik Studio di bawah versi minimum.
        // Request tanpa header tidak tersentuh. Lihat docblock kelasnya.
        $middleware->appendToGroup('api', IdentitasKlien::class);

        // Peran diperiksa SEBELUM route model binding (R2-7, QA ronde 2).
        //
        // `SubstituteBindings` ada di grup `api` dan jalan lebih awal dari
        // `role:` yang menempel di rute. Akibatnya peran tanpa hak mendapat 404
        // untuk ID yang tidak ada (binding gagal duluan) tetapi 403 untuk ID
        // yang ada — termasuk ID milik lab lain — dan selisih itu oracle
        // keberadaan ID. Dengan `role:` di depan binding, peran tanpa hak
        // mendapat 403 yang seragam untuk ID apa pun; peran yang berhak tidak
        // tersentuh (binding & penjaga organisasi jalan sesudahnya, 404 lintas
        // lab tetap 404). Tetap di belakang `auth:sanctum` (AuthenticatesRequests
        // sudah lebih dulu di daftar prioritas), jadi tamu tetap 401.
        //
        // Jangan dipindah ke belakang `SubstituteBindings`: `PeranSebelumBindingTest`
        // menyapu seluruh rute ber-parameter dan ber-`role:` dan akan merah.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureUserHasRole::class,
        );

        // `aplikasi:` tetap di depan `role:` (komentar rute M1-03: token dari
        // aplikasi yang salah ditolak sebelum rolenya diperiksa). Tanpa baris ini
        // memindahkan `role:` ke depan binding membuat `aplikasi:` ikut tertinggal
        // di belakang binding — oracle yang sama, kali ini untuk token aplikasi lain.
        // Urutan deklarasi penting: `role:` harus sudah ada di daftar.
        $middleware->prependToPriorityList(
            before: EnsureUserHasRole::class,
            prepend: PastikanAplikasi::class,
        );

        // Penjaga dunia PELANGGAN dan Studio juga sebelum binding (QA ronde 2-B).
        //
        // Kelas masalahnya sama dengan R2-7, hanya di rute lain: `fitur.pelanggan`,
        // `pelanggan.aktif`, `perusahaan`, `peran:` (pelanggan) dan `studio`
        // dulu jalan SESUDAH `SubstituteBindings`. Anggota non-PIC yang menembak
        // `/anggota/{anggota}/nonaktifkan` atau `/anggota/undangan/{undangan}`
        // mendapat 403 untuk ID yang ADA (milik perusahaan mana pun) dan 404 untuk
        // yang tidak ada — oracle keberadaan ID lintas perusahaan, lawan aturan
        // keras 4 Modul Pelanggan. Akun menunggu verifikasi (`pelanggan.aktif`),
        // pemegang beberapa keanggotaan tanpa `X-Perusahaan-Id` (400 dari
        // `perusahaan`), modul yang dimatikan (503), dan akun lab yang menembak
        // `/studio/workbook/{workbook}` (403 `studio`) membuka selisih yang sama.
        //
        // Urutan akhirnya (sisip tepat di depan `role:`, sesudah `aplikasi:`):
        //   aplikasi → fitur → pelanggan.aktif → perusahaan → peran → [binding]
        // dan untuk Studio: studio → [binding]. Tidak satu rute pun memakai
        // `role:` bersama penjaga-penjaga ini, jadi posisi relatifnya terhadap
        // `role:` tidak punya arti; yang dijaga cuma "semuanya di depan binding".
        // Tamu tetap 401 (`auth:sanctum` tetap paling depan) dan pembatas laju
        // tetap sebelum semuanya. `PeranSebelumBindingPelangganTest` menyapu
        // seluruh rute ber-parameter di kedua dunia.
        foreach ([
            FiturPelanggan::class,
            PelangganAktif::class,
            KonteksPerusahaan::class,
            PeranAnggota::class,
            PastikanAkunStudio::class,
        ] as $penjaga) {
            $middleware->prependToPriorityList(
                before: EnsureUserHasRole::class,
                prepend: $penjaga,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // 404 dari route model binding membawa pesan mentah Laravel
        // `No query results for model [App\Models\CalibrationSession] 25` — nama
        // kelas internal yang keluar ke klien dan tampil apa adanya di antrean draf
        // teknisi (uji kontrak S-7). Diganti kalimat generik; STATUS 404 dan bentuk
        // `{message}` tidak berubah, dan 404 lain (abort(404), rute tak ada) tidak
        // tersentuh.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Data yang dicari nggak ketemu.'], 404);
            }

            return null;
        });
    })->create();
