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
            self::$redirectHistogram ??= self::meter()->createHistogram('redirect.duration', 's');

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

    /** Maps an HTTP status code to its class string (caps metric cardinality). */
    public static function statusClass(int $statusCode): string
    {
        return intdiv($statusCode, 100).'xx';
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
            self::$httpHistogram ??= self::meter()->createHistogram('http.server.request.duration', 's');

            $attributes = [
                'http.route' => $route,
                'http.request.method' => $method,
                'http.response.status_class' => self::statusClass($statusCode),
            ];

            self::$httpCounter->add(1, $attributes);
            self::$httpHistogram->record($durationSeconds, $attributes);
        } catch (Throwable) {
            // Telemetry must never break a request.
        }
    }
}
