<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic Claude (AI Vision buat baca lembar kerja dari foto)
    |--------------------------------------------------------------------------
    | Nggantiin OCR di HP. Teknisi foto lembar kerja → dikirim ke Claude Vision →
    | balik JSON terstruktur buat dicek & dikonfirmasi teknisi sebelum disimpen.
    | Model default Opus 4.8: paling akurat buat baca tulisan tangan/tabel.
    | `temperature` SENGAJA nggak diset — model 4.8 ke atas nolak parameter itu;
    | konsistensi dijaga lewat prompt yang tegas + JSON-only.
    */
    /**
     * Penyedia AI buat baca lembar kerja dari foto.
     *
     * `anthropic` atau `gemini`. Yang beda cuma cara ngomong ke servernya —
     * prompt, few-shot, skema, dan normalisasi hasilnya dipakai bareng, jadi
     * ganti penyedia nggak ngubah apa pun yang nyampe ke lembar kerja teknisi.
     */
    'vision' => [
        /*
         * Bawaannya `gemini` — itu yang lab ini beneran pakai, dan kuncinya
         * yang ada di server.
         *
         * Dulu bawaannya `anthropic`. Selama `VISION_DRIVER=gemini` ada di
         * `.env` nggak ada bedanya, tapi begitu baris itu hilang (server baru,
         * `.env` disalin dari `.env.example`, atau kepencet kehapus) kameranya
         * diam-diam pindah ke penyedia yang kuncinya nggak ada — dan gagalnya
         * baru ketahuan waktu teknisi udah di lapangan megang alat.
         */
        'driver' => env('VISION_DRIVER', 'gemini'),

        /*
         * Saklar jalur pindai AI. `false` = `POST /raw-measurements/extract-from-photo`
         * balik 503 tanpa pernah nyentuh layanan pihak ketiga.
         *
         * Ada karena endpoint ini sekarang CADANGAN, bukan jalur utama: aplikasi
         * mobile pindahnya ke jalur lokal (`POST /worksheet-scans`, ML Kit
         * on-device) dan nggak pernah manggilnya lagi. Endpointnya sengaja
         * nggak dihapus — dia jalur yang sudah terbukti jalan — tapi dia
         * MENGIRIM FOTO LEMBAR KERJA PELANGGAN KE LAYANAN PIHAK KETIGA, dan
         * jalur keluarnya data yang nggak dipakai siapa-siapa itu yang paling
         * gampang lolos dari perhatian waktu ditinjau.
         *
         * Bawaannya `true` supaya nyalain PR ini nggak diam-diam mematikan
         * sesuatu yang mungkin masih dipakai klien lain. Yang mutusin
         * mematikannya lab, lewat satu baris `.env` — bukan lewat hapus kode.
         */
        'aktif' => (bool) env('VISION_AKTIF', true),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        /*
         * Nama model Gemini MATI TANPA ABA-ABA, dan gejalanya nggak kelihatan
         * kayak masalah model: `gemini-2.5-flash` balas 404 "no longer available
         * to new users", `gemini-2.0-flash` balas 429 quota walau key-nya sehat.
         * Dua-duanya gampang ketuker jadi "API key-nya rusak" waktu ditelusuri.
         * Sebelum ganti pin ini, pastikan namanya masih kejawab:
         *   GET https://generativelanguage.googleapis.com/v1beta/models
         * Jangan turun ke tier `flash-lite` — angka tulisan tangan yang dibaca
         * di sini masuk sertifikat resmi.
         */
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        // 4096 KEKECILAN buat model yang mikir dulu: token berpikir ikut
        // dihitung ke jatah ini, jadi JSON-nya kepotong di tengah dan balik
        // sebagai `finish_reason: MAX_TOKENS`. Gejalanya di HP: "nggak ada
        // angka yang kebaca sama sekali" — kelihatan kayak fotonya jelek,
        // padahal fotonya bagus.
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 32768),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        // Structured Output (output_config.format) — jamin bentuk JSON. Matiin
        // (=false) cuma kalau API nolak skema-nya; prompt tetap minta JSON murni.
        'structured_output' => (bool) env('ANTHROPIC_STRUCTURED_OUTPUT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging — push ke HP yang aplikasinya ketutup total
    |--------------------------------------------------------------------------
    | Cuma menutup satu celah. Selama aplikasinya jalan, kabar sudah sampai
    | lewat Reverb, dan itu jalur yang nggak butuh layanan pihak ketiga.
    |
    | KOSONG = push mati (`PengirimPushMati`), dan itu keadaan yang sah: mesin
    | developer, CI, dan test memang nggak punya kredensial ini. Notifikasi
    | tetap masuk database, tetap muncul di lonceng, tetap disiarkan Reverb.
    |
    | `credentials` menunjuk berkas service account JSON. Simpan DI LUAR repo —
    | itu kunci server, beda dari REVERB_APP_KEY yang memang publik.
    */
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
        'timeout' => (int) env('FCM_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Direktori perusahaan — cari nama & alamat PT dari sumber LUAR
    |--------------------------------------------------------------------------
    | Dipakai waktu teknisi mendaftarkan pelanggan yang belum ada di master lab.
    | Sumbernya direktori TEMPAT USAHA, bukan registri badan hukum: AHU
    | (Kemenkumham) memegang data PT terdaftar tapi nggak membuka API publik,
    | dan OSS/BKPM cuma buat mitra berizin. Jadi yang ketemu di sini perusahaan
    | sebagaimana dia muncul di peta — cukup buat mencocokkan papan nama, TIDAK
    | cukup buat dianggap data akta.
    |
    | KOSONG = jalur direktori mati, dan layar HP-nya BILANG "belum disetel" —
    | bukan diam-diam mulangin daftar kosong. Bedanya penting: yang kedua kebaca
    | teknisi sebagai "PT-nya nggak ada di direktori", lalu dia mendaftarkan
    | ulang perusahaan yang sebenarnya ada di sana.
    |
    | Key-nya kunci SERVER. Jangan pernah ditaruh di aplikasi HP: key di dalam
    | APK bisa dicabut siapa pun dari berkasnya lalu dipakai atas tagihan lab
    | ini. HP nembak endpoint lab, lab yang memegang key-nya.
    |
    | Endpoint ini ditagih PER REQUEST — batasi kuotanya di konsol penyedianya,
    | jangan cuma di sini.
    */
    'direktori_perusahaan' => [
        // `auto` (bawaan) = BERLAPIS: Google duluan kalau key-nya ada, lalu
        // OpenStreetMap. Dua sumbernya punya kelemahan yang berlawanan, jadi
        // pasangannya menutup keduanya — lihat `DirektoriBerlapis`.
        //
        // `google` = Places API saja. Cakupan pabrik Indonesia paling tebal.
        // Text Search punya kuota bebas bulanan (5.000 panggilan/bulan sejak
        // Maret 2025) yang jauh di atas pemakaian satu lab, tapi tetap butuh
        // key — dan tanpa key jalur ini mati total.
        //
        // `osm` = OpenStreetMap lewat Nominatim saja. Tanpa key, tanpa kuota,
        // tapi cakupannya tipis: cuma tempat yang pernah dipetakan sukarelawan.
        //
        // Apa pun drivernya, tiap lapis dibungkus `DirektoriBercache` di
        // `AppServiceProvider` — pencarian yang sama tidak menembak penyedia
        // (dan tidak ditagih) dua kali. Kalau hasilnya terasa basi:
        // `php artisan cache:clear`.
        'driver' => env('DIREKTORI_PERUSAHAAN_DRIVER', 'auto'),

        // Cuma dipakai driver `google`.
        'key' => env('DIREKTORI_PERUSAHAAN_KEY'),

        // Nominatim MENOLAK klien yang nggak menyebut dirinya, dan yang
        // diblokir alamat IP server-nya — bukan satu request. Isi dengan nama
        // aplikasi + cara menghubungi yang beneran bisa dihubungi.
        'user_agent' => env(
            'DIREKTORI_PERUSAHAAN_USER_AGENT',
            'SidikCalibration/1.0 (+https://github.com/ZainulArkaanAlinsi/sidik-calibration-api)',
        ),

        'timeout' => (int) env('DIREKTORI_PERUSAHAAN_TIMEOUT', 8),
    ],

];
