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
