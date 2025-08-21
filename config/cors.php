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

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',      // Front-end local
        'http://127.0.0.1:3000',     // Alternativa localhost
        'https://linkchartapp.vercel.app', // Produção Vercel
        'https://your-frontend.vercel.app', // Configuração atual
        'http://138.197.121.81',     // API própria
    ],

    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#', // Qualquer subdomínio do Vercel
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
