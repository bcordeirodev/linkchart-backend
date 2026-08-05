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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'from' => [
            'email' => env('MAIL_FROM_ADDRESS', 'noreply@linkcharts.com.br'),
            'name' => env('MAIL_FROM_NAME', 'Link Charts'),
        ],

        // Segredo compartilhado do webhook de eventos (POST /api/webhooks/brevo,
        // rota pública). Vai na query string do endpoint cadastrado no painel do
        // Brevo. Vazio fecha o endpoint — sem token configurado, nenhuma
        // requisição passa.
        'webhook_token' => env('BREVO_WEBHOOK_TOKEN'),
    ],

    // Guarda-corpo de volume do transacional. O antigo seletor de provedor foi
    // removido em 2026-08-05: Brevo é o único transporte de e-mail transacional.
    'transactional_email' => [
        // Warning quando a leva do digest semanal se aproxima do cap free do
        // Brevo (300 e-mails/dia, transacional + marketing somados).
        'volume_warn_threshold' => (int) env('DIGEST_VOLUME_WARN_THRESHOLD', 250),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_safe_browsing' => [
        'key' => env('GOOGLE_SAFE_BROWSING_KEY'),
    ],

    'auth0' => [
        'domain' => env('AUTH0_DOMAIN'),
    ],

];
