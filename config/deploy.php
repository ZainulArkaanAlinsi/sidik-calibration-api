<?php

use App\Support\SaklarBoot;

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
     * Render menyuntik RENDER_GIT_COMMIT sendiri, jadi di sana nol setelan.
     *
     * APP_COMMIT jalur cadangan buat pemasangan yang BUKAN Render — VPS,
     * Docker sendiri, mesin lab. Diisi skrip deploy:
     *
     *     APP_COMMIT=$(git rev-parse HEAD)
     *
     * Ditambah karena "di luar Render nilainya kosong dan itu wajar" berhenti
     * wajar begitu produksinya pindah: endpoint ini ADA supaya "build-nya udah
     * naik belum?" tidak perlu ditebak, dan di VPS dia justru selalu `null` —
     * pertanyaannya balik tak terjawab persis di tempat yang paling sulit
     * diperiksa, karena di sana tidak ada dashboard yang bisa dibuka.
     *
     * Urutannya Render DULU: di Render kedua variabel bisa sama-sama terisi
     * (mis. APP_COMMIT ikut tersalin waktu blueprint dicontek), dan yang benar
     * selalu yang disuntik platform — APP_COMMIT bisa basi kalau skrip
     * deploy-nya gagal memperbaruinya.
     *
     * Tetap `null` kalau dua-duanya kosong, dan itu disengaja: server yang
     * TIDAK TAHU harus bilang tidak tahu. Sengaja tidak jatuh ke `git
     * rev-parse` saat runtime — di produksi `.git` sering tidak ikut, dan
     * kalau ikut pun isinya bisa lebih baru daripada kode yang benar-benar
     * jalan. Tebakan yang kelihatan seperti fakta lebih buruk daripada kosong.
     *
     * SHA-nya bukan rahasia. Yang dibeli: "build-nya udah naik belum?" dijawab
     * satu `curl`, bukan dengan membuka dashboard dan mencocokkan pakai mata.
     */
    'versi' => env('RENDER_GIT_COMMIT') ?: (env('APP_COMMIT') ?: null),

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
    /*
     * DIBACA LEWAT [SaklarBoot], bukan `filter_var(..., FILTER_VALIDATE_BOOL)`.
     *
     * Gerbang di entrypoint cuma mengenal string `true` huruf kecil. Filter
     * bawaan jauh lebih murah hati (`1`, `yes`, `on`), dan `env()` meng-cast
     * `TRUE` jadi boolean lebih dulu — empat nilai yang bikin health melapor
     * nyala sementara tidak ada yang jalan.
     *
     * `seed_saat_boot` ikut diperbaiki walau bukan lahir di perubahan ini:
     * cacatnya sama persis, di berkas yang sama, dan membiarkannya berarti
     * endpoint yang tujuannya "jangan ada yang perlu menebak" tetap berbohong
     * di separuh isinya.
     */
    'seed_saat_boot' => SaklarBoot::nyala('SEED_ON_BOOT'),

    /*
     * Apakah snapshot & PDF sertifikat dibangun ulang tiap container nyala.
     *
     * Saudara kembarnya `seed_saat_boot`: sama-sama `sync: false` di
     * render.yaml, sama-sama disetel lewat dashboard, sama-sama kerja berat di
     * boot. Bedanya cuma satu, dan itu yang bikin dia perlu ikut dilaporkan:
     * dia dinyalakan JAUH lebih sering — tiap deploy yang mengubah bentuk
     * snapshot — jadi peluang lupa mematikannya jauh lebih besar.
     *
     * `docs/deploy-gratis-render.md` menyuruh tiga langkah: nyalakan, baca
     * hasilnya di deploy log, lalu balikin ke `false`. Langkah ketiga tidak
     * menerbitkan error kalau terlupa; yang terjadi cuma tiap deploy
     * berikutnya membangun ulang SELURUH sertifikat yang sudah terbit lagi,
     * memakan menit dari jendela health check Render yang cuma 15 menit.
     *
     * Sama seperti `seed_saat_boot`: saklarnya dibaca docker/entrypoint.sh
     * langsung dari shell; yang di sini cuma buat MELAPORKAN, bukan buat
     * menyalakan.
     */
    'bangun_ulang_saat_boot' => SaklarBoot::nyala('BANGUN_ULANG_ON_BOOT'),

];
