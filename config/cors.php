<?php

/*
|--------------------------------------------------------------------------
| CORS — hanya klien peramban Sidik (D6, Studio Tahap 1, 25 Nov 2026)
|--------------------------------------------------------------------------
|
| Sebelum berkas ini ada, bawaan framework berlaku: `allowed_origins => ['*']`
| di `api/*`, artinya halaman web mana pun boleh memanggil API ini dari
| peramban. Klien peramban yang sah ada dua, jadi origin dikunci ke keduanya:
|
|  1. Sidik Studio Web — dev server Vite lokal (`localhost:5173` dan
|     `127.0.0.1:5173`). Origin produksi Studio Web belum ada di repo mana pun;
|     masuk lewat `CORS_ALLOWED_ORIGINS` di bawah.
|  2. Bundel web aplikasi Flutter (repo mobile, job `web` di
|     `.github/workflows/rilis-desktop.yml`, `flutter build web
|     --base-href=/app/`). Dia dilayani di subpath `/app` situs Firebase Hosting
|     proyek `sidik-kalibrasi`, yang punya dua domain bersaudara:
|     `https://sidik-kalibrasi.web.app` dan `https://sidik-kalibrasi.firebaseapp.com`.
|     Origin peramban tidak memuat path, jadi cukup dua alamat itu. Sumber
|     (repo mobile, `origin/main` a728f21): `.firebaserc` (`default:
|     sidik-kalibrasi`), `docs/deploy-firebase.md` (kedua domain disebut apa
|     adanya), dan job `web` di workflow di atas. Dua origin ini BAWAAN KODE,
|     bukan env: bundel web itu memanggil API dari peramban, jadi tanpa dua
|     alamat ini panggilannya diblokir CORS begitu `*` dicabut, dan tidak ada
|     galat di sisi server yang memberi tahu.
|
| Aplikasi mobile dan panel Filament tidak melewati CORS.
|
| `CORS_ALLOWED_ORIGINS`: origin TAMBAHAN dipisah koma (skema + host + port,
| tanpa garis miring akhir), mis. `https://studio.contoh.id,https://studio-2.contoh.id`.
| Selalu DITAMBAHKAN ke daftar bawaan di atas, tidak pernah menggantinya —
| mengisi env untuk Studio Web tidak bisa tanpa sengaja memutus bundel web
| Flutter. Mengurangi origin bawaan = ubah berkas ini lewat PR. `*` di env
| dibuang. Env diisi pemilik di dashboard hosting.
|
| Tidak memakai cookie (token `Authorization: Bearer`), jadi
| `supports_credentials` tetap `false`. `allowed_headers` memantulkan header
| yang diminta preflight (`Authorization`, `X-Klien`, `X-Versi-Klien`,
| `Content-Type`, `Idempotency-Key`, dst.), jadi header baru di klien tidak
| diam-diam gagal di preflight; yang menahan penyalahgunaan adalah daftar origin.
|
| Dijaga `CorsStudioTest`.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique([
        // Studio Web, dev server Vite.
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        // Bundel web Flutter di Firebase Hosting (`/app`), dua domain situs yang sama.
        'https://sidik-kalibrasi.web.app',
        'https://sidik-kalibrasi.firebaseapp.com',
        ...array_values(array_filter(
            array_map(
                static fn (string $o): string => rtrim(trim($o), '/'),
                explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
            ),
            static fn (string $o): bool => $o !== '' && $o !== '*',
        )),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Klien Studio membaca jatah dan jeda dari header ini untuk pesan 429.
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,

];
