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
    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8090'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE', 'fabio-pessoal'),
        // URL que o CONTAINER da Evolution usa pra alcancar o app no host:
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL', 'http://host.docker.internal:8190/webhook/evolution'),
    ],

];
