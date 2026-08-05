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
        'driver' => env('VISION_DRIVER', 'anthropic'),
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
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 4096),
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

];
