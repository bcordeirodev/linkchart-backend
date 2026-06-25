<?php

namespace App\Providers;

use App\Observability\Otel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Records queue job metrics (job.count / job.duration) via Laravel Queue events.
 * Job start times are kept in-process keyed by job id; null-safe when telemetry off.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /** @var array<string,float> */
    private array $started = [];

    /** Registers Queue event listeners to record job metrics via Otel. */
    public function boot(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $this->started[$event->job->getJobId()] = microtime(true);
        });
        Queue::after(function (JobProcessed $event): void {
            $this->finish($event->job->getJobId(), $event->job->resolveName(), 'succeeded');
        });
        Queue::failing(function (JobFailed $event): void {
            $this->finish($event->job->getJobId(), $event->job->resolveName(), 'failed');
        });
    }

    /**
     * Calculates elapsed duration and records the job metric, then cleans up the start-time map.
     *
     * @param  string  $id  Job ID used as key in the start-time map.
     * @param  string  $name  Fully-qualified job class name.
     * @param  string  $status  One of: succeeded|failed.
     */
    private function finish(string $id, string $name, string $status): void
    {
        $start = $this->started[$id] ?? null;
        unset($this->started[$id]);
        Otel::recordJob($name, $status, $start !== null ? microtime(true) - $start : 0.0);

        try {
            if ($this->app->bound('otel.meter_provider')) {
                $this->app->make('otel.meter_provider')->forceFlush();
            }
        } catch (\Throwable) {
            // never break the worker
        }
    }
}
