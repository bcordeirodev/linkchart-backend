<?php

namespace Tests\Unit;

use App\Providers\OpenTelemetryServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the OTel stream-identity contract: service.instance.id must be unique
 * PER PROCESS, not per container.
 *
 * Why this matters: every PHP-FPM worker (and each queue:work process) exports
 * its own independent DELTA chain. Alloy's deltatocumulative processor keys its
 * accumulator by stream identity — when N processes share one identity, their
 * interleaved start timestamps read as counter resets, the "cumulative" output
 * becomes a sawtooth, and PromQL rate()/increase() re-adds the value on every
 * apparent reset. In production this inflated redirect_count_total by up to
 * 13.6x versus the nginx access log (2026-08-09 → 2026-08-19).
 */
class OpenTelemetryInstanceIdTest extends TestCase
{
    /**
     * The id must embed the current PID so two workers on the same host can
     * never collide into one OTel stream.
     */
    public function test_instance_id_is_unique_per_process(): void
    {
        $id = OpenTelemetryServiceProvider::instanceId();

        $this->assertSame((gethostname() ?: 'unknown').'-'.getmypid(), $id);
        $this->assertMatchesRegularExpression('/-\d+$/', $id);
    }
}
