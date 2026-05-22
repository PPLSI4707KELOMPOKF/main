<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lentra AI — Ollama Service
    |--------------------------------------------------------------------------
    | Konfigurasi koneksi ke Ollama local API.
    | Pastikan Ollama sudah berjalan: ollama serve
    */

    'ollama' => [
        'base_url'       => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model'          => env('OLLAMA_MODEL', 'mistral'),
        'fallback_model' => env('OLLAMA_FALLBACK_MODEL', 'llama3'),
        'api_key'        => env('OLLAMA_API_KEY', 'ollama'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI-Compatible Endpoint (via Ollama)
    |--------------------------------------------------------------------------
    */

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'api_key'  => env('OPENAI_API_KEY', 'ollama'),
    ],

];
