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

    // Validacao de origem do webhook (header secreto verificado com hash_equals).
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
        'header' => env('WEBHOOK_HEADER', 'X-Webhook-Secret'),
    ],

    // 2a Evolution (instancia isolada do msgautomation).
    // CH-2 — WhatsApp Cloud API oficial (Meta). Credenciais SEMPRE por canal
    // (cifradas); aqui so a base/versao do Graph (versao CONFIGURAVEL — a doc
    // da Meta nao era alcancavel do ambiente; validacao real na Parte B).
    'cloud_api' => [
        'graph_base' => env('CLOUD_API_GRAPH_BASE', 'https://graph.facebook.com'),
        'graph_version' => env('CLOUD_API_GRAPH_VERSION', 'v23.0'),
    ],

    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8090'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE', 'fabio-pessoal'),
        // URL que o CONTAINER da Evolution usa pra alcancar o app no host:
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL', 'http://host.docker.internal:8190/webhook/evolution'),
    ],

    // IA classificadora (Camada 3) — Gemini free tier (Flash-Lite). Chave SO no .env
    // (chmod 600, gitignored). Chave vazia = a IA nao chama a API (silencia e loga).
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 12),
        'max_attempts' => (int) env('GEMINI_MAX_ATTEMPTS', 3),
        'retry_sleep_ms' => (int) env('GEMINI_RETRY_SLEEP_MS', 500),
        'daily_cap' => (int) env('GEMINI_DAILY_CAP', 1000),
    ],

];
