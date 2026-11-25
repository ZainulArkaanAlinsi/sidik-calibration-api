<?php

/*
|--------------------------------------------------------------------------
| CORS — hanya origin Studio Web (D6, Studio Tahap 1, 25 Nov 2026)
|--------------------------------------------------------------------------
|
| Sebelum berkas ini ada, bawaan framework berlaku: `allowed_origins => ['*']`
| di `api/*`, artinya halaman web mana pun boleh memanggil API ini dari
| peramban. Satu-satunya klien peramban yang sah adalah Sidik Studio Web (dev
| server Vite); aplikasi mobile dan panel Filament tidak melewati CORS. Maka
| origin dikunci ke dua alamat dev lokal ditambah origin produksi dari env.
|
| `CORS_ALLOWED_ORIGINS`: daftar origin dipisah koma (skema + host + port, tanpa
| garis miring akhir), mis. `https://studio.contoh.id,https://studio-2.contoh.id`.
| Kosong = hanya dua alamat lokal. Env itu diisi pemilik di dashboard hosting;
| kuncinya wajib ikut `render.yaml` kalau sinkron blueprint dipakai (AGENTS.md
| §Alur Kerja butir 9) — sengaja belum ditambahkan oleh pekerjaan ini.
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
        'http://localhost:5173',
        'http://127.0.0.1:5173',
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
