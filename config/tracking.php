<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Viral Rank Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds used by ClickVelocityService to classify a link's viral rank
    | based on click velocity over sliding Redis windows.
    |
    | - viral:    clicks in the last 5 minutes to be ranked "viral"
    | - trending: clicks in the last 5 minutes to be ranked "trending"
    | - warming:  clicks in the last 60 minutes to be ranked "warming"
    |
    */
    'viral_thresholds' => [
        'viral' => env('VIRAL_THRESHOLD_VIRAL', 50),
        'trending' => env('VIRAL_THRESHOLD_TRENDING', 20),
        'warming' => env('VIRAL_THRESHOLD_WARMING', 100),
    ],
];
