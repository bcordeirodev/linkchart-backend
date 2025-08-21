<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration specifically for API-only Laravel applications.
    | These settings optimize Laravel for JSON API responses and disable unnecessary
    | features for web applications.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | API Only Mode
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, certain web-specific features are disabled
    | to optimize the application for API usage only.
    |
    */

    'api_only' => env('API_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Disable Session and Cookies for API
    |--------------------------------------------------------------------------
    |
    | Since APIs are stateless, we can disable session management and cookies
    | for better performance.
    |
    */

    'disable_sessions' => env('API_DISABLE_SESSIONS', true),

    /*
    |--------------------------------------------------------------------------
    | CORS Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CORS settings specifically for API usage.
    |
    */

    'cors' => [
        'enabled' => env('API_CORS_ENABLED', true),
        'allowed_origins' => explode(',', env('API_CORS_ORIGINS', '*')),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        'max_age' => 86400, // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Default API Response Format
    |--------------------------------------------------------------------------
    |
    | Configure the default response format for API endpoints.
    |
    */

    'response' => [
        'format' => 'json',
        'charset' => 'utf-8',
        'pretty_print' => env('API_PRETTY_PRINT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure default rate limiting for API endpoints.
    |
    */

    'rate_limiting' => [
        'enabled' => env('RATE_LIMIT_ENABLED', true),
        'max_attempts' => env('RATE_LIMIT_MAX_ATTEMPTS', 100),
        'decay_minutes' => env('RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimizations
    |--------------------------------------------------------------------------
    |
    | These settings optimize Laravel specifically for API usage.
    |
    */

    'optimizations' => [
        'skip_view_compilation' => true,
        'disable_blade' => true,
        'cache_routes' => true,
        'cache_config' => true,
    ],
];
