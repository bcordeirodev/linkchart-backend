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
}
