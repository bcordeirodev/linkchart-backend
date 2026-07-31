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
| Bio-page subdomains (one per creator, e.g. bruno.linkcharts.com.br — see
| middleware.ts's extractBioSubdomain) are a first-party origin too, but
| there's no fixed list to enumerate: allowed via a wildcard pattern instead
| of allowed_origins. Same idea outside production for {sub}.localhost.
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

    'allowed_origins_patterns' => array_merge(
        [
            '#^https://[a-z0-9-]+\.linkcharts\.com\.br$#',
        ],
        $isProduction ? [] : [
            '#^https?://localhost:\d+$#',
            '#^https?://127\.0\.0\.1:\d+$#',
            '#^https?://[a-z0-9-]+\.localhost(:\d+)?$#',
        ]
    ),

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
