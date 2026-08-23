<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Berkas privat yang HARUS awet: PDF sertifikat, gambar tanda tangan,
         * dokumen Folder Manager, dan foto titik ukur.
         *
         * ## Kenapa disk sendiri, bukan `local`
         *
         * Disk `local` isinya campur: ada berkas awet (empat kelompok di atas)
         * dan ada berkas sekali-pakai (unggahan Excel di ImportExcel, yang
         * dibaca lalu dibuang). Dua-duanya nggak boleh pindah bareng — yang
         * sekali-pakai nggak ada gunanya ditaruh di object storage.
         *
         * Disk terpisah bikin kelompok yang awet bisa dipindah sendirian, dan
         * bisa dibalikin sendirian juga kalau ada apa-apa: satu variabel env,
         * satu kelompok berkas.
         *
         * ## Selama ARSIP_DRIVER masih `local`, ini NGGAK mengubah apa pun
         *
         * `root`-nya sama persis dengan disk `local`, jadi berkas yang sudah ada
         * tetap ketemu di tempatnya. Penggantian nama disk di kode itu operasi
         * kosong sampai ada yang menyetel ARSIP_DRIVER=s3.
         */
        'arsip' => [
            'driver' => env('ARSIP_DRIVER', 'local'),

            /*
             * `root` artinya BEDA di dua driver, dan ini bukan detail kosmetik:
             *
             *   local  → folder di filesystem
             *   s3     → PREFIX kunci di bucket. FilesystemManager::createS3Driver
             *            b.252 baca `root` lalu nyerahin ke S3Adapter sebagai
             *            prefix (b.262).
             *
             * Dibiarkan `storage_path()` waktu drivernya s3, tiap objek bakal
             * dapat kunci berawalan '/home/.../storage/app/private/' — dan kunci
             * itu ikut kesimpan di kolom `pdf_path`, `tanda_tangan_path`, `path`.
             * Sekali pindah server, seluruh rujukan berkas di database jadi
             * nunjuk ke tempat yang nggak ada.
             *
             * Prefix-nya default KOSONG, biar kunci di bucket sama persis dengan
             * nilai yang sudah tersimpan di database ('certificates/...',
             * 'tanda-tangan/...', dst). Diisi cuma kalau bucket-nya dipakai
             * bareng hal lain.
             */
            'root' => env('ARSIP_DRIVER', 'local') === 's3'
                ? env('ARSIP_PREFIX', '')
                : storage_path('app/private'),

            /*
             * `serve` SENGAJA nggak dinyalain — beda dari disk `local`.
             *
             * Dua alasan, dan yang kedua yang sebenarnya penting:
             *
             * 1. Dua disk ber-`serve` tanpa `url` sendiri sama-sama kedaftar di
             *    URI /storage, dan Laravel nolak itu waktu boot
             *    (FilesystemServiceProvider b.102).
             *
             * 2. Isi disk ini memang NGGAK BOLEH punya URL sendiri. Semua
             *    aksesnya lewat controller yang meriksa hak dulu — `download()`
             *    di CertificateController & FolderFileController, `response()`
             *    di OrganizationController. Gambar tanda tangan yang bisa
             *    diambil lewat URL berarti siapa pun yang tahu alamatnya bisa
             *    nempelin ke dokumen palsu; docblock `tandaTanganDataUri()` di
             *    DataTampilanSertifikat bersandar pada premis itu.
             */

            // Kepakai cuma kalau ARSIP_DRIVER=s3. Sengaja pakai variabel AWS_*
            // yang sama dengan disk `s3` di bawah: satu bucket, satu set
            // kredensial, satu tempat yang bisa salah setel.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

            /*
             * `throw` SENGAJA tetap false.
             *
             * Kegagalan tulis sudah dijaga penjagaan `=== false` eksplisit di
             * titik pakainya — GenerateCertificate, BangunUlangSnapshotSertifikat,
             * OrganizationController, FolderFileController, CalibrationController.
             * Penjagaan itu ngasih pesan yang kebaca dan kode status yang benar.
             *
             * Dinyalakan `true`, penjagaan itu jadi kode mati dan pesannya
             * berganti jadi stack trace 500. Bukan peningkatan.
             */
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
