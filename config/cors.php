<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Scoped to 'api/*' and 'r/*' on purpose — the old '*' catch-all made every
| web route (/health included) emit permissive CORS headers with credentials
| support. Localhost origins/patterns are dev-only: outside production they
| let the Next.js dev server (any port) call the API directly; in production
| only the real product domains are allowed.
|
| NOTE: this file is evaluated at config:cache time, so env() here is safe.
| The env default is 'production' (fail closed): an unset APP_ENV never
| enables the dev origins.
|
| To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
|
*/

$isProduction = env('APP_ENV', 'production') === 'production';

return [

    'paths' => ['api/*', 'r/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge(
        [
            'https://linkcharts.com.br',
            'https://www.linkcharts.com.br',
        ],
        $isProduction ? [] : [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ]
    ),

    'allowed_origins_patterns' => $isProduction ? [] : [
        '#^https?://localhost:\d+$#',
        '#^https?://127\.0\.0\.1:\d+$#',
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
        'Referer',
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
