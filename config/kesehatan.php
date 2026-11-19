<?php

/*
 * Ambang blok `kesehatan` di `GET /api/health` (App\Support\KesehatanLayanan).
 *
 * ## Kenapa angka di berkas config, bukan env
 *
 * Ambang ini bukan setelan per-lingkungan; ini definisi "sakit" yang sama di
 * mana pun, dan ikut direview seperti kode. Env baru berarti kunci baru yang
 * harus didaftarkan di `.env.example` dan `render.yaml` dan bisa digeser dari
 * dashboard tanpa jejak — persis jebakan `ARSIP_DRIVER` (render.yaml). Mengubah
 * angka di sini = satu PR yang kelihatan.
 *
 * Pembacanya: pemantau uptime luar yang memukul endpoint tiap 5 menit. Alert
 * sedikit tetapi penting (docs/operasional/perawatan-setelah-live.md): ambang
 * dipasang cukup longgar supaya satu job yang lambat tidak membangunkan orang.
 */

return [

    /*
     * Job tertua yang BELUM dikerjakan (tidak sedang direservasi worker dan
     * sudah jatuh tempo) lebih tua dari ini = `antrean_macet`, dalam detik.
     *
     * 30 menit: satu render PDF di Render Free ~10-17 detik dan timeout job
     * 10 menit (`GenerateCertificate::$timeout`). Job yang menunggu 30 menit
     * bukan antre — worker-nya mati atau container tertidur.
     */
    'antrean_tertua_detik' => 1800,

    /*
     * Job gagal sebanyak ini atau lebih (di dalam jendela di bawah) =
     * `antrean_macet`. "Atau lebih": batasnya sendiri sudah sakit.
     */
    'gagal_batas' => 10,

    /*
     * Hanya kegagalan dalam N jam terakhir yang dihitung; 0 = semua baris.
     *
     * `failed_jobs` tidak dibersihkan oleh apa pun di repo ini dan Render Free
     * tidak punya shell untuk `queue:flush`. Tanpa jendela, sepuluh kegagalan
     * lama yang sudah diperbaiki membuat status `degraded` menetap selamanya,
     * dan pemantau yang selalu merah berhenti dipercaya.
     */
    'gagal_jendela_jam' => 24,

    /*
     * Sertifikat `menunggu_generate` (tanpa PDF, sesinya disetujui) yang diam
     * lebih lama dari ini = `sertifikat_menggantung`, dalam menit.
     *
     * Penyapu `sertifikat:sapu-tertunda` mendorong ulang yang diam > 15 menit
     * tiap 10 menit. Yang masih menggantung di 60 menit berarti penyapunya
     * pun tidak jalan (container tertidur) atau dorongannya terus gagal.
     */
    'sertifikat_menggantung_menit' => 60,

];
