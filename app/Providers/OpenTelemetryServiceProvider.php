<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Builds the OpenTelemetry SDK and registers it as the global provider set.
 *
 * Guarded by config('otel.enabled'): when false this provider returns
 * immediately, leaving the SDK's no-op global providers in place so the
 * App\Observability\Otel facade and instrumentation become inert. When true it
 * wires OTLP/HTTP (JSON) exporters for traces, metrics, and logs to the
 * configured gateway (Grafana Cloud), with batch processors that flush at
 * process shutdown (Sdk::setAutoShutdown). Spans use a parent-based ratio
 * sampler so the frontend's sampling decision is honored and root spans fall
 * back to the configured ratio.
 *
 * API notes (vs. the brief's reference code):
 *   - Clock: uses `Clock::getDefault()` from `OpenTelemetry\API\Common\Time\Clock`
 *     (no `ClockFactory` class exists in v1.14; `Clock` is the registry in the API package).
 *   - ResourceAttributes: sem-conv v1.38 renamed `DEPLOYMENT_ENVIRONMENT` to
 *     `DEPLOYMENT_ENVIRONMENT_NAME`; the new constant is used.
 *   - MetricExporter: accepts temporality as its second constructor argument
 *     (`string|Temporality|null`); `ExportingReader` only wraps the exporter.
 */
class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register the OpenTelemetry SDK when telemetry is enabled.
     *
     * When config('otel.enabled') is false (the default), this method returns
     * immediately — no exporter is instantiated, no global provider is replaced.
     * This keeps the test suite fully isolated from the OTel SDK.
     */
    public function register(): void
    {
        if (! config('otel.enabled')) {
            return;
        }

        $endpoint = rtrim((string) config('otel.endpoint'), '/');
        $headers = (array) config('otel.headers');
        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => config('otel.service_name'),
            ResourceAttributes::SERVICE_VERSION => config('otel.service_version'),
            // sem-conv v1.38+ renamed DEPLOYMENT_ENVIRONMENT to DEPLOYMENT_ENVIRONMENT_NAME.
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => config('otel.environment'),
        ])));

        $clock = Clock::getDefault();

        // --- Traces ---
        $spanTransport = (new OtlpHttpTransportFactory)
            ->create($endpoint.'/v1/traces', 'application/json', $headers);
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new BatchSpanProcessor(new SpanExporter($spanTransport), $clock))
            ->setResource($resource)
            ->setSampler(new ParentBased(new TraceIdRatioBasedSampler((float) (config('otel.traces.sampler_ratio') ?? 1.0))))
            ->build();

        // --- Metrics (delta temporality suits short-lived FPM processes) ---
        $metricTransport = (new OtlpHttpTransportFactory)
            ->create($endpoint.'/v1/metrics', 'application/json', $headers);
        $meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader(new ExportingReader(new MetricExporter($metricTransport, Temporality::DELTA)))
            ->build();

        // --- Logs ---
        $logTransport = (new OtlpHttpTransportFactory)
            ->create($endpoint.'/v1/logs', 'application/json', $headers);
        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor(new BatchLogRecordProcessor(new LogsExporter($logTransport), $clock))
            ->build();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($meterProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }
}
