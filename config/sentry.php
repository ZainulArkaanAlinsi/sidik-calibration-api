<?php

use App\Support\PenyaringSentry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Sentry (pelacak galat API) — paket OPS-KODE butir 2, keputusan pemilik 19 Nov 2026.
 *
 * ## Syarat pemilik: privasi KERAS
 *
 * Yang BOLEH keluar: jenis galat, pesan yang sudah disamarkan, tumpukan panggilan
 * (berkas, baris, kode sumber), rute (templat, bukan alamat), versi rilis,
 * lingkungan. Yang TIDAK BOLEH keluar sama sekali: nama pelanggan, isi lembar
 * kerja, token, citra, badan permintaan, header otorisasi, cookie, data
 * pengguna, tangkapan layar, nilai binding SQL. Tiga lapis, supaya satu lapis
 * yang bocor tidak cukup untuk membocorkan data:
 *
 *   1. Pengaturan di berkas ini (tidak dikumpulkan sejak awal).
 *   2. `PenyaringSentry` (before_send & before_breadcrumb) yang membuang dan
 *      menyamarkan apa pun yang tetap lolos — termasuk yang ditambahkan
 *      integrasi Laravel (konteks `Context`, `exception->context()`).
 *   3. Tes yang memeriksa muatan yang BENAR-BENAR sampai ke transport
 *      (tests/Feature/SentryPrivasiTest.php).
 *
 * ## NONAKTIF kalau DSN kosong
 *
 * `SENTRY_LARAVEL_DSN` tidak diisi = tidak ada klien yang bisa mengirim: SDK
 * melewati pengiriman tanpa membuka sambungan jaringan, dan pendengar peristiwa
 * serta middleware-nya tidak dipasang. Itu keadaan bawaan; menyalakannya = pemilik
 * menyetel satu env di Render (rahasia, `sync: false`). JANGAN menulis DSN ke
 * berkas apa pun di repo ini.
 *
 * Satu kunci env saja yang dibaca (selain yang sudah ada di config/deploy.php).
 * Kunci lain milik paket (SENTRY_SEND_DEFAULT_PII, SENTRY_TRACES_SAMPLE_RATE,
 * dst.) SENGAJA tidak dibaca: pengaturan privasi tidak boleh bisa digeser dari
 * dashboard tanpa jejak — mengubahnya = satu PR yang kelihatan.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    /*
     * Tes tidak pernah boleh mengirim ke Sentry, walau `.env` mesin pengembang
     * kebetulan berisi DSN sungguhan. Tes yang butuh klien berDSN menyetelnya
     * lewat `config()->set()` dan memasang transport palsu.
     */
    'dsn' => env('APP_ENV') === 'testing' ? null : env('SENTRY_LARAVEL_DSN'),

    /*
     * Rilis = commit yang benar-benar jalan (`deploy.versi`, config/deploy.php).
     * Ekspresinya SAMA dengan berkas itu — dibaca dari env, bukan dari
     * `config('deploy')`, karena urutan pemuatan berkas config tidak dijamin.
     * `SentryKonfigurasiTest` mengunci keduanya tetap sama.
     */
    'release' => env('RENDER_GIT_COMMIT') ?: (env('APP_COMMIT') ?: null),

    'environment' => env('APP_ENV', 'production'),

    /*
     * Nama server yang tercantum di peristiwa: tetap, bukan nama host container
     * (berganti tiap deploy dan tidak berguna untuk mengelompokkan galat).
     */
    'server_name' => 'sidik-api',

    // Semua galat dikirim; yang BUKAN gangguan dibuang di `ignore_exceptions`
    // dan `PenyaringSentry`, bukan lewat sampling acak.
    'sample_rate' => 1.0,

    // === Privasi ============================================================

    // Tanpa IP, tanpa data pengguna, tanpa cookie, tanpa header otorisasi.
    'send_default_pii' => false,

    // Badan permintaan TIDAK dikirim: isinya lembar kerja, nama pelanggan,
    // sandi, dan foto.
    'max_request_body_size' => 'none',

    // Tanpa tangkapan layar/lampiran dan tanpa tumpukan untuk pesan non-galat.
    'attach_stacktrace' => false,

    // Kode sumber di sekitar tiap frame TIDAK dikirim (`null` = tanpa). Yang
    // berangkat cukup berkas, baris, dan fungsi; kodenya dibaca dari repo pada
    // commit yang tercantum di `release`. Menjaga baris kode dan literalnya
    // tetap di sisi kita.
    'context_lines' => null,

    // Telemetri tambahan SDK dimatikan: yang dibutuhkan hanya galat.
    'send_client_reports' => false,
    'enable_logs' => false,
    'enable_metrics' => false,

    // Performa & profil MATI. `enable_tracing => false` mematikan seluruh
    // mesin pelacakan (bukan sekadar sampel 0), jadi nol ongkos di container
    // 0,1 CPU.
    'enable_tracing' => false,
    'traces_sample_rate' => 0.0,
    'profiles_sample_rate' => 0.0,

    // Server Sentry yang lambat/mati tidak boleh menahan respons ke teknisi:
    // batas pendek (detik).
    'http_timeout' => 2,
    'http_connect_timeout' => 1,

    // === Penyaring ==========================================================

    // Galat yang BUKAN gangguan sistem: salah isi, belum masuk, tidak berhak,
    // tidak ketemu, kena batas laju. HTTP 4xx lain (403, 405, 409, 419, ...)
    // dibuang oleh `PenyaringSentry::sebelumKirim()` lewat kode statusnya.
    'ignore_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        ThrottleRequestsException::class,
    ],

    // Bukan Closure: `config:cache` (dijalankan entrypoint) tidak bisa
    // menyimpannya. Pemanggilan statis berbentuk larik aman di-cache.
    'before_send' => [PenyaringSentry::class, 'sebelumKirim'],
    'before_breadcrumb' => [PenyaringSentry::class, 'sebelumBreadcrumb'],

    'max_breadcrumbs' => 20,

    'ignore_transactions' => ['/up'],

    'integrations' => [],

    // === Remah (breadcrumb) =================================================
    //
    // Hanya SQL, dan tanpa nilai binding. Log, cache, antrean, perintah,
    // klien HTTP, dan notifikasi bisa memuat nama, alamat surel, atau jalur
    // berkas berisi data — dimatikan. `PenyaringSentry::sebelumBreadcrumb()`
    // menegakkan daftar yang sama kalau ada yang menyalakannya lagi.
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'crons' => [
        'monitor_scheduled_tasks' => false,
        'exclude_scheduled_tasks' => [],
    ],

    // Pelacakan performa mati seluruhnya (lihat `enable_tracing`); semua
    // cabangnya ditulis mati juga supaya tidak ada yang menyala karena bawaan
    // paket berubah di versi berikutnya.
    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'sql_origin_threshold_ms' => 100,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'http_client_requests_origin' => false,
        'http_client_requests_origin_threshold_ms' => 250,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
        'gen_ai' => false,
        'gen_ai_invoke_agent' => false,
        'gen_ai_chat' => false,
        'gen_ai_execute_tool' => false,
        'gen_ai_embeddings' => false,
        'gen_ai_evaluate' => false,
        'default_integrations' => false,
    ],

];
