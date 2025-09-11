<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel 12 CORS Configuration - DESENVOLVIMENTO
    |--------------------------------------------------------------------------
    | Configuração simplificada para aceitar TODAS as origens durante desenvolvimento
    */

    'paths' => ['api/*', 'r/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
