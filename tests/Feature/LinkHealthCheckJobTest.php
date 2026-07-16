<?php

namespace Tests\Feature;

use App\Jobs\LinkHealthCheckJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Covers the LinkHealthCheckJob lifecycle instrumentation added after the
 * 2026-07-16 observability report found the job was a telemetry blind spot
 * (it ran hourly but emitted no logs).
 *
 * URLs point at 127.0.0.1 on a closed port so the Guzzle HEAD check fails
 * instantly (connection refused) without ever leaving the machine.
 */
class LinkHealthCheckJobTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    /** URL whose HEAD check fails immediately with connection refused (port 9 = discard). */
    private const UNREACHABLE_URL = 'http://127.0.0.1:9/health-check-test';

    public function test_updates_health_columns_for_active_links(): void
    {
        $link = $this->makeLink(['original_url' => self::UNREACHABLE_URL]);

        (new LinkHealthCheckJob)->handle();

        $row = DB::table('links')->where('id', $link->id)->first(['health_status', 'health_checked_at']);
        $this->assertSame('error', $row->health_status);
        $this->assertNotNull($row->health_checked_at);
    }

    public function test_logs_lifecycle_with_checked_and_error_totals(): void
    {
        $this->makeLink(['original_url' => self::UNREACHABLE_URL]);

        // Capture the lifecycle calls on the 'jobs' channel via a spy.
        $jobsSpy = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('jobs')->andReturn($jobsSpy);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        (new LinkHealthCheckJob)->handle();

        $jobsSpy->shouldHaveReceived('info')
            ->with('job.started', \Mockery::on(fn ($ctx) => ($ctx['job'] ?? null) === LinkHealthCheckJob::class));
        $jobsSpy->shouldHaveReceived('info')
            ->with('job.succeeded', \Mockery::on(fn ($ctx) => ($ctx['job'] ?? null) === LinkHealthCheckJob::class
                && ($ctx['checked'] ?? null) === 1
                && ($ctx['errors'] ?? null) === 1));
    }

    public function test_skips_inactive_links(): void
    {
        $link = $this->makeLink(['original_url' => self::UNREACHABLE_URL, 'is_active' => false]);

        (new LinkHealthCheckJob)->handle();

        $this->assertNull(DB::table('links')->where('id', $link->id)->value('health_checked_at'));
    }
}
