<?php

/*
 * Setelan seeder yang cuma berlaku di SERVER.
 *
 * Isinya satu perkara: sandi awal buat akun yang dibentuk seeder. Di laptop,
 * sandinya `rahasia123` dan itu memang disengaja — dokumentasi, skrip e2e di
 * docs/skrip/, dan test semuanya bersandar ke situ.
 *
 * Di server ceritanya lain. Repo ini PUBLIK, jadi `rahasia123` tertulis terbuka
 * di docs/kontrak-api.md, docs/BACA-DULU-BACKEND.md, dan di seeder-nya sendiri.
 * Nama service-nya juga publik di render.yaml, jadi alamat servernya tinggal
 * ditebak sekali. Dua hal itu digabung = panel admin di /admin kebuka buat siapa
 * pun yang sempat baca repo — dan panel itu bisa menerbitkan, mengubah, serta
 * menghapus sertifikat kalibrasi.
 *
 * Nilainya lewat environment, BUKAN ditulis di sini: berkas ini masuk git, dan
 * apa pun yang ditulis di sini ikut terbit bersamanya.
 *
 * Kenapa harus mampir ke config dan nggak `env()` langsung dari seeder:
 * docker/entrypoint.sh manggil `php artisan config:cache` sebelum server nyala,
 * dan sesudah config kecache `env()` di luar berkas config balikin null. Seeder
 * yang baca env langsung bakal diam-diam dapat null di produksi — persis di
 * lingkungan yang paling butuh nilainya kebaca benar.
 */

return [

    /*
     * Sandi buat akun yang BARU dibentuk seeder di luar lingkungan lokal.
     *
     * Dikosongin juga aman: seeder bikin sandi acak 32 karakter dan nyetak
     * sekali ke log deploy buat disalin. Yang nggak aman cuma satu — nulis
     * sandi beneran di berkas ini.
     *
     * Sandi ini cuma dipakai waktu barisnya baru kebentuk. Seeder nggak pernah
     * nyetel ulang sandi akun yang sudah ada, jadi nilai di sini nggak bakal
     * nimpa sandi yang sudah diganti admin lewat panel. Lihat trait
     * Database\Seeders\Concerns\MenyetelSandiAwal.
     */
    'sandi_awal' => env('SEED_ADMIN_PASSWORD'),

];
