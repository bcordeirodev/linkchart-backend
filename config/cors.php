<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'r/*', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://linkcharts.com.br',
        'https://www.linkcharts.com.br'
    ],

    'allowed_origins_patterns' => [
        '#^https?://localhost:\d+$#',
        '#^https?://127\.0\.0\.1:\d+$#'
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-Real-IP',
        'X-Forwarded-For',
        'Origin',
        'User-Agent',
        'Referer'
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
