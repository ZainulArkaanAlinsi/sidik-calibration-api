<?php

/*
 * Tiga hal tentang container yang SEDANG jalan, dibaca lewat GET /api/health.
 *
 * ## Kenapa lewat config, bukan `env()` langsung di route
 *
 * Alasannya sama persis dengan yang sudah ditulis di config/seeding.php, dan
 * berlaku juga di sini: docker/entrypoint.sh memanggil `php artisan
 * config:cache` sebelum server nyala, dan sesudah config kecache berkas `.env`
 * TIDAK dibaca lagi. Nilai yang asalnya dari `.env` jadi null di `env()` mana
 * pun di luar berkas config.
 *
 * Di Render kebetulan dua-duanya variabel environment betulan (disuntik ke
 * proses, bukan dari `.env`), jadi `env()` langsung pun kebaca. Tapi
 * "kebetulan benar di satu lingkungan" itu bukan alasan yang tahan lama:
 * begitu ada yang memindahkannya ke `.env` — di laptop, di CI, di VPS yang
 * pakai berkas `.env` — endpoint-nya berhenti benar tanpa satu pun error.
 *
 * Dan yang bikin itu mahal khusus di sini: endpoint ini ADA supaya nggak ada
 * yang perlu menebak. `seed_saat_boot` yang salah lapor `false` bukan cuma
 * nilai yang keliru — dia mencoret tersangka utama deploy yang timeout dari
 * daftar, lalu orangnya mencari ke tempat yang salah.
 */

return [

    /*
     * Commit yang benar-benar jalan di container ini.
     *
     * Render menyuntik RENDER_GIT_COMMIT sendiri; di luar Render nilainya
     * kosong dan itu wajar — yang dilaporkan `null`, bukan tebakan.
     *
     * Repo ini publik, jadi SHA-nya bukan rahasia. Yang dibeli: "build-nya
     * udah naik belum?" dijawab satu `curl`, bukan dengan membuka dashboard
     * dan mencocokkan SHA pakai mata.
     */
    'versi' => env('RENDER_GIT_COMMIT') ?: null,

    /*
     * Apakah seeder jalan tiap container nyala.
     *
     * `true` artinya DemoDataSeeder menulis ulang seluruh sesi contoh setiap
     * boot. Dokumennya bilang "nyalain sekali pas deploy pertama, terus
     * matiin"; kalau tidak pernah dimatikan, tiap deploy membayar ongkosnya
     * lagi — menit yang diambil dari jendela health check Render yang cuma 15
     * menit, dan itu tersangka utama deploy yang timeout.
     *
     * Saklarnya sendiri dibaca docker/entrypoint.sh langsung dari shell; yang
     * di sini cuma buat MELAPORKAN, bukan buat menyalakan.
     */
    'seed_saat_boot' => filter_var(env('SEED_ON_BOOT', false), FILTER_VALIDATE_BOOL),

];
