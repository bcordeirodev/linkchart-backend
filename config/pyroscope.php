<?php

declare(strict_types=1);

/**
 * Continuous profiling → Grafana Pyroscope, via the `ext-excimer` sampling
 * profiler (App\Profiling\PyroscopeProfiler + PyroscopeSampling middleware).
 *
 * Off by default. When enabled, only a small `sample_rate` fraction of requests
 * is profiled, and the profile is pushed AFTER the response (terminable
 * middleware), so the request path is never blocked by the export. Every
 * failure mode is a silent no-op — profiling must never affect serving traffic.
 */
return [
    // Master kill switch. Off unless a truthy PYROSCOPE_ENABLED is set per env.
    'enabled' => env('PYROSCOPE_ENABLED', false),

    // Grafana Cloud Pyroscope push endpoint (no trailing slash),
    // e.g. https://profiles-prod-017.grafana.net
    'endpoint' => env('PYROSCOPE_ENDPOINT', ''),

    // HTTP basic auth for the push. `username` is the Pyroscope instance id;
    // `password` is a Grafana Cloud Access Policy token with `profiles:write`
    // (reuses the same token already used for OTLP — no new secret required).
    'username' => env('PYROSCOPE_USER', ''),
    'password' => env('PYROSCOPE_PASSWORD', ''),

    // Application name shown in Pyroscope.
    'app_name' => env('PYROSCOPE_APP_NAME', 'linkcharts-backend'),

    // Fraction of requests to profile, 0..1. Kept small so production overhead
    // stays negligible (excimer sampling itself is cheap; this bounds the
    // per-request export volume).
    'sample_rate' => (float) env('PYROSCOPE_SAMPLE_RATE', 0.02),

    // Excimer sampling period in seconds — the stack is sampled every N seconds.
    'period' => (float) env('PYROSCOPE_PERIOD', 0.01),

    // Deployment environment, sent as a Pyroscope label.
    'environment' => env('APP_ENV', 'production'),

    // HTTP timeout (seconds) for the push — short so a slow/unreachable ingest
    // never keeps a worker busy.
    'push_timeout' => (float) env('PYROSCOPE_PUSH_TIMEOUT', 2.0),
];
