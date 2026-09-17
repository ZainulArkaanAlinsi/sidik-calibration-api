<?php

/*
 * Modul pelanggan — sakelar & nilai yang dibaca aplikasi pelanggan.
 *
 * ## Kenapa lewat config, bukan `env()` langsung
 *
 * Alasannya sama persis dengan yang sudah ditulis di config/deploy.php dan
 * config/seeding.php: docker/entrypoint.sh memanggil `php artisan config:cache`
 * sebelum server nyala, dan sesudah config kecache berkas `.env` TIDAK dibaca
 * lagi. `env()` di luar berkas config jadi null tanpa satu pun error.
 *
 * Yang bikin itu mahal khusus di sini: `fitur` adalah sakelar yang menutup
 * SELURUH API pelanggan. `env('FITUR_PELANGGAN')` yang jatuh ke null di
 * container yang sudah di-cache membaca seperti "mati" — seluruh aplikasi
 * pelanggan membalas 503 di produksi, dan dari sisi log nggak ada yang salah.
 */

return [

    /*
     * Sakelar utama. Mati → seluruh `routes/api_pelanggan.php` membalas 503
     * `belum_tersedia`, kecuali `GET /app/status`.
     *
     * Default FALSE, dan itu disengaja (03-SDD §10): produksi tetap mati sampai
     * rilis M7. Lingkungan yang memang mau menyalakannya harus menyatakannya.
     */
    'fitur' => (bool) env('FITUR_PELANGGAN', false),

    /*
     * Versi aplikasi pelanggan.
     *
     * `versi_minimum` dinaikkan HANYA sesudah versi baru mencapai 100% rollout
     * dan stabil (07-Runbook §7) — menaikkannya lebih awal mengunci pengguna
     * yang belum kebagian update dari Play Store.
     */
    'versi_minimum' => (string) env('PELANGGAN_VERSI_MINIMUM', '0.0.0'),
    'versi_terbaru' => (string) env('PELANGGAN_VERSI_TERBARU', '0.0.0'),

    /*
     * Maintenance khusus modul pelanggan — TIDAK menyentuh app internal.
     *
     * Dipisah dari `php artisan down` bawaan Laravel justru karena itu: teknisi
     * di lapangan tidak boleh ikut berhenti waktu sisi pelanggan sedang
     * diperbaiki.
     */
    'maintenance' => (bool) env('PELANGGAN_MAINTENANCE', false),
    'pesan_maintenance' => (string) env(
        'PELANGGAN_PESAN_MAINTENANCE',
        'Aplikasi sedang dalam perbaikan. Silakan coba lagi sebentar lagi.',
    ),

    /*
     * Batas akhir masa transisi token internal tanpa ability (03-SDD §3.1).
     *
     * Token internal yang sudah beredar di HP teknisi dibuat sebelum ability
     * `internal` ada. Middleware `aplikasi:internal` (Fase 4) menerima token
     * tanpa ability sampai tanggal ini, supaya teknisi yang sedang login tidak
     * tiba-tiba ter-logout di tengah pekerjaan.
     *
     * Kosong = tidak ada batas. Diisi tanggal `Y-m-d` waktu deploy Fase 4.
     */
    'cutoff_token_lama' => env('PELANGGAN_CUTOFF_TOKEN_LAMA'),

];
