<?php

namespace Tests\Feature\Observability;

use App\Observability\Otel;
use Tests\TestCase;

class JobMetricsTest extends TestCase
{
    public function test_record_job_is_noop_when_disabled(): void
    {
        config(['otel.enabled' => false]);
        Otel::recordJob('App\\Jobs\\ProcessLinkClickJob', 'succeeded', 0.05);
        $this->assertFalse(Otel::enabled());
    }
}
