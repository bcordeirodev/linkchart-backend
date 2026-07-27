<?php

namespace Tests\Feature\Observability;

use App\Observability\Otel;
use ReflectionClass;
use Tests\TestCase;

class JobMetricsTest extends TestCase
{
    public function test_record_job_is_noop_when_disabled(): void
    {
        config(['otel.enabled' => false]);
        Otel::recordJob('App\\Jobs\\ProcessLinkClickJob', 'succeeded', 0.05);
        $this->assertFalse(Otel::enabled());
    }

    /**
     * Exercises the instrument-creation path with telemetry on. The global meter
     * is the SDK no-op here, so nothing is exported — what this guards is that
     * every instrument `recordJob` builds actually exists in the installed SDK.
     * `createGauge` in particular is marked `@experimental` upstream, so an SDK
     * bump that drops or renames it must fail here rather than in production,
     * where `recordJob` swallows every Throwable by design.
     */
    public function test_record_job_creates_every_instrument_when_enabled(): void
    {
        config(['otel.enabled' => true]);

        Otel::recordJob('App\\Jobs\\LinkHealthCheckJob', 'succeeded', 1.25);
        Otel::recordJob('App\\Jobs\\LinkHealthCheckJob', 'failed', 0.5);

        $this->assertTrue(Otel::enabled());
    }

    /**
     * The success-timestamp gauge is the liveness signal for scheduled jobs, so
     * it must be stamped only on success — a job that keeps failing has to read
     * as stale, not healthy.
     */
    public function test_success_timestamp_gauge_is_only_stamped_for_succeeded_jobs(): void
    {
        config(['otel.enabled' => true]);

        $gauge = (new ReflectionClass(Otel::class))->getProperty('jobLastSuccessGauge');
        $gauge->setAccessible(true);
        $gauge->setValue(null, null);

        Otel::recordJob('App\\Jobs\\LinkHealthCheckJob', 'failed', 0.5);
        $this->assertNull(
            $gauge->getValue(),
            'A failed job must not stamp the last-success gauge.',
        );

        Otel::recordJob('App\\Jobs\\LinkHealthCheckJob', 'succeeded', 0.5);
        $this->assertNotNull(
            $gauge->getValue(),
            'A succeeded job must stamp the last-success gauge.',
        );
    }
}
