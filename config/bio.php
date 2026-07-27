<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reserved bio handles
    |--------------------------------------------------------------------------
    |
    | Handles a user can never claim for their public "link-in-bio" page,
    | because they collide with an existing top-level path segment on either
    | the frontend (e.g. /links, /profile) or this API (e.g. /api, /bio
    | itself). Enforced by {@see \App\Rules\BioHandleNotReserved}, used by
    | UpsertBioPageRequest and BioPageService::isHandleAvailable().
    |
    | Extend this array as new top-level routes are added — no code change
    | required elsewhere.
    */
    'reserved_handles' => [
        'admin', 'api', 'www', 'app', 'login', 'sign-in', 'links', 'shorter',
        'ferramentas', 'guia', 'comparar', 'privacy', 'terms', 'support',
        'r', 'b', 'bio', 'public-analytics', 'profile', 'reports',
        'subdomains', 'api-keys', 'health', 'help', 'about', 'contact',
        'blog', 'docs', 'static', 'assets',
    ],

];
