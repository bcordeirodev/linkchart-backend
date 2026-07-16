<?php

namespace App\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

/**
 * Thin static accessor for OpenTelemetry instrumentation points.
 *
 * Every method is null-safe: when OTEL_ENABLED is false (tests, local dev with
 * no collector) the global providers are the SDK's no-op implementations, so
 * spans/metrics are silently discarded and instrumentation never affects the
 * request. Callers therefore never need their own enabled() guard.
 */
final class Otel
{
    private const INSTRUMENTATION = 'linkcharts';

    /**
     * Bucket boundaries (seconds) for request-scoped histograms. The SDK default
     * boundaries (0, 5, 10, 25, … — designed for milliseconds) put every
     * sub-5-second request in a single bucket, so histogram_quantile() returned
     * a meaningless interpolated 4.75s p95 for every status code.
     */
    private const REQUEST_DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    /** Bucket boundaries (seconds) for queue jobs, which can legitimately run for minutes. */
    private const JOB_DURATION_BUCKETS = [0.01, 0.05, 0.1, 0.5, 1, 5, 15, 30, 60, 120, 300];

    /** Memoized redirect counter; created once per process. */
    private static ?CounterInterface $redirectCounter = null;

    /** Memoized redirect duration histogram; created once per process. */
    private static ?HistogramInterface $redirectHistogram = null;

    /** Memoized HTTP server request counter; created once per process. */
    private static ?CounterInterface $httpCounter = null;

    /** Memoized HTTP server request duration histogram; created once per process. */
    private static ?HistogramInterface $httpHistogram = null;

    /** Memoized Safe Browsing check counter; created once per process. */
    private static ?CounterInterface $safetyCounter = null;

    /** Memoized job counter; created once per process. */
    private static ?CounterInterface $jobCounter = null;

    /** Memoized job duration histogram; created once per process. */
    private static ?HistogramInterface $jobHistogram = null;

    /** Whether telemetry export is active for this process. */
    public static function enabled(): bool
    {
        return (bool) config('otel.enabled');
    }

    /** Global tracer (no-op when the SDK was not registered). */
    public static function tracer(): TracerInterface
    {
        return Globals::tracerProvider()->getTracer(self::INSTRUMENTATION);
    }

    /** Global meter (no-op when the SDK was not registered). */
    public static function meter(): MeterInterface
    {
        return Globals::meterProvider()->getMeter(self::INSTRUMENTATION);
    }

    /**
     * Record one redirect as OTel metrics. No-op and exception-swallowing so a
     * metrics failure can never break the redirect hot path. The instruments are
     * memoized so each is created only once per process (avoids duplicate-
     * instrument warnings on strict SDK versions). The enabled() guard here is a
     * performance optimization to skip work entirely when telemetry is off; the
     * tracer/meter would already be no-ops in that case.
     *
     * @param  int  $statusCode  HTTP status of the redirect response.
     * @param  float  $durationSeconds  Wall-clock time of the redirect handling.
     * @param  string|null  $country  ISO country derived from the client IP, if known.
     * @param  string  $device  mobile|tablet|desktop|bot|unknown.
     */
    public static function recordRedirect(int $statusCode, float $durationSeconds, ?string $country, string $device): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            self::$redirectCounter ??= self::meter()->createCounter('redirect.count');
            self::$redirectHistogram ??= self::meter()->createHistogram(
                'redirect.duration',
                's',
                advisory: ['ExplicitBucketBoundaries' => self::REQUEST_DURATION_BUCKETS],
            );

            $attributes = [
                'http.response.status_code' => $statusCode,
                'redirect.country' => $country ?? 'unknown',
                'redirect.device' => $device,
            ];

            self::$redirectCounter->add(1, $attributes);
            self::$redirectHistogram->record($durationSeconds, $attributes);
        } catch (Throwable) {
            // Telemetry must never break a redirect.
        }
    }

    /**
     * Records one Safe Browsing check outcome. No-op and exception-swallowing so
     * a telemetry failure never breaks link creation. The instrument is memoized
     * once per process.
     *
     * @param  string  $result  One of: ok|flagged|bad_response|unavailable.
     */
    public static function recordSafetyCheck(string $result): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            self::$safetyCounter ??= self::meter()->createCounter('safety.check.count');
            self::$safetyCounter->add(1, ['safety.result' => $result]);
        } catch (Throwable) {
            // Telemetry must never break a safety check.
        }
    }

    /**
     * Records one finished queue job as OTel metrics. No-op and exception-swallowing
     * so a telemetry failure never breaks job processing. Instruments memoized once
     * per process.
     *
     * @param  string  $job  Fully-qualified job class name.
     * @param  string  $status  One of: succeeded|failed.
     * @param  float  $durationSeconds  Wall-clock time from job start to completion.
     */
    public static function recordJob(string $job, string $status, float $durationSeconds): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            self::$jobCounter ??= self::meter()->createCounter('job.count');
            self::$jobHistogram ??= self::meter()->createHistogram(
                'job.duration',
                's',
                advisory: ['ExplicitBucketBoundaries' => self::JOB_DURATION_BUCKETS],
            );
            $attributes = ['job.name' => $job, 'job.status' => $status];
            self::$jobCounter->add(1, $attributes);
            self::$jobHistogram->record($durationSeconds, $attributes);
        } catch (Throwable) {
            // Telemetry must never break job processing.
        }
    }

    /** Maps an HTTP status code to its class string (caps metric cardinality). */
    public static function statusClass(int $statusCode): string
    {
        return intdiv($statusCode, 100).'xx';
    }

    /** Caps the HTTP method label to a bounded set (avoids cardinality from arbitrary verbs). */
    public static function methodLabel(string $method): string
    {
        $m = strtoupper($method);

        return in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $m : 'OTHER';
    }

    /**
     * Records one HTTP server request as RED metrics (rate/errors/duration).
     * No-op + exception-swallowing; instruments memoized once per process.
     *
     * @param  string  $route  Route name or URI template (never the raw path).
     * @param  string  $method  HTTP method (GET, POST, …).
     * @param  int  $statusCode  HTTP response status code.
     * @param  float  $durationSeconds  Wall-clock time for the full request.
     */
    public static function recordHttpServer(string $route, string $method, int $statusCode, float $durationSeconds): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            self::$httpCounter ??= self::meter()->createCounter('http.server.request.count');
            self::$httpHistogram ??= self::meter()->createHistogram(
                'http.server.request.duration',
                's',
                advisory: ['ExplicitBucketBoundaries' => self::REQUEST_DURATION_BUCKETS],
            );

            $attributes = [
                'http.route' => $route,
                'http.request.method' => self::methodLabel($method),
                'http.response.status_class' => self::statusClass($statusCode),
            ];

            self::$httpCounter->add(1, $attributes);
            self::$httpHistogram->record($durationSeconds, $attributes);
        } catch (Throwable) {
            // Telemetry must never break a request.
        }
    }
}
