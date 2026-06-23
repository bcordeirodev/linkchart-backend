<?php

/**
 * OpenTelemetry configuration. All values are env-driven so the same image
 * runs with telemetry on (production) or off (tests, local) with no code change.
 * When 'enabled' is false the OpenTelemetryServiceProvider builds nothing and
 * App\Observability\Otel returns no-op API objects.
 */
return [
    // Master kill switch. Off by default — must be explicitly enabled per env.
    'enabled' => env('OTEL_ENABLED', false),

    // OTLP/HTTP gateway (Grafana Cloud). Signals are POSTed to
    // {endpoint}/v1/traces, {endpoint}/v1/metrics, {endpoint}/v1/logs.
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),

    // Parsed from OTEL_EXPORTER_OTLP_HEADERS ("k1=v1,k2=v2"); typically the
    // "Authorization=Basic <base64>" header for Grafana Cloud.
    'headers' => collect(explode(',', (string) env('OTEL_EXPORTER_OTLP_HEADERS', '')))
        ->filter()
        ->mapWithKeys(function (string $pair): array {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

            return [trim($k) => trim($v)];
        })
        ->all(),

    'service_name' => env('OTEL_SERVICE_NAME', 'linkcharts-backend'),
    'service_version' => env('OTEL_SERVICE_VERSION', env('APP_VERSION', 'dev')),
    'environment' => env('APP_ENV', 'production'),

    'traces' => [
        // Default sampler ratio for normal API requests/jobs.
        'sampler_ratio' => (float) env('OTEL_TRACES_SAMPLER_RATIO', 1.0),
    ],

    // The redirect hot path is sampled far more aggressively to protect latency.
    'redirect_sampler_ratio' => (float) env('OTEL_REDIRECT_SAMPLER_RATIO', 0.05),
];
