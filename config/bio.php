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

    /*
    |--------------------------------------------------------------------------
    | Avatar storage disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk (see config/filesystems.php) used to store bio page
    | avatar uploads. Defaults to the existing 'public' disk (local, served
    | via the public/storage symlink created by `php artisan storage:link`).
    | Swapping to object storage (e.g. an 's3'-driver disk pointed at
    | DigitalOcean Spaces) later is a pure env change — no code change
    | required — since {@see \App\Services\Bio\BioPageService} always reads
    | this config key rather than hardcoding a disk name.
    */
    'avatar_disk' => env('BIO_AVATAR_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Social icon platforms
    |--------------------------------------------------------------------------
    |
    | Whitelist of `bio_page_items.social_platform` values accepted when an
    | item's `display` is 'icon' (see {@see \App\Models\BioPageItem::DISPLAY_ICON}).
    | Enforced by CreateBioPageItemRequest/UpdateBioPageItemRequest via
    | `Rule::in(config('bio.social_platforms'))`. Initial set fixed by product
    | decision (2026-08-04) — extend here as new platforms are supported, no
    | migration required (the column is a plain nullable varchar(30)).
    */
    'social_platforms' => [
        'instagram', 'tiktok', 'youtube', 'x', 'whatsapp', 'linkedin', 'github', 'website',
    ],

];
