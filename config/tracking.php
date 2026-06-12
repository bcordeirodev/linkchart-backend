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

    /*
     | Days before click IPs are anonymized by clicks:anonymize-ips (LGPD).
     | Recent IPs stay intact for antifraud (quality score, session analysis).
     */
    'ip_retention_days' => env('TRACKING_IP_RETENTION_DAYS', 90),

    /*
     | Optional GeoLite2-ASN database for connection_type classification.
     | When the file is absent, classification falls back to ISP keyword
     | matching + datacenter CIDR prefixes (legacy behavior).
     */
    'asn_database_path' => env('TRACKING_ASN_DB', storage_path('app/geoip/GeoLite2-ASN.mmdb')),
];
