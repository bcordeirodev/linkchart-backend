<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Models\Link;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Periodically checks the reachability of every active link's original URL.
 *
 * Trigger: the Laravel scheduler defined in `bootstrap/app.php` (line 10)
 * schedules this job to run `hourly()->withoutOverlapping()`:
 * ```php
 * $schedule->job(new \App\Jobs\LinkHealthCheckJob)->hourly()->withoutOverlapping();
 * ```
 * No application code dispatches this job directly.
 *
 * Side effects:
 *   - HTTP / external calls: issues an HTTP `HEAD` request (via Guzzle) to
 *     each active link's `original_url`. Processes links in chunks of 50 to
 *     limit peak memory usage. TLS verification is enabled (Guzzle default);
 *     links with broken or self-signed certificates will be flagged as
 *     `health_status = 'error'`.
 *   - DB writes: updates `links.health_status` ('ok' | 'error') and
 *     `links.health_checked_at` for every active link via a direct
 *     `DB::table('links')->update([...])` call (no model events, so the Link
 *     cache is NOT invalidated — health fields are read separately from slug
 *     cache hits).
 *   - Cache: none written by this job.
 *   - Queue: no further jobs are dispatched.
 *   - Log channels: `jobs` — lifecycle events (`job.started` / `job.succeeded` /
 *     `job.failed`) plus per-run totals (`checked` / `errors`) via `AppLogger`.
 *     Per-link HTTP errors are still caught silently and only recorded as
 *     `health_status = 'error'` (no log line per unhealthy link).
 *
 * Retry policy:
 *   - `$tries = 1` — no retries; a failed run is acceptable since the scheduler
 *     will try again in the next hourly tick.
 *   - `$backoff`: not set; uses framework default (irrelevant given `$tries = 1`).
 *   - On final failure: no `failed()` callback. The job is moved to failed-jobs
 *     silently; health columns for that batch are not updated.
 *
 * Idempotency: YES.
 *   Re-running the job at any time simply re-checks each URL and overwrites the
 *   health columns. No new rows are created; existing data is always replaced.
 *
 * @see \App\Models\Link
 */
class LinkHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $start = microtime(true);
        AppLogger::jobStarted(static::class);

        try {
            [$checked, $errors] = $this->checkActiveLinks();

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'checked' => $checked,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        }
    }

    /**
     * Runs the HEAD check over every active link and persists the health columns.
     *
     * @return array{0:int,1:int} Tuple of [links checked, links flagged as 'error'].
     */
    private function checkActiveLinks(): array
    {
        $http = new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
            'allow_redirects' => ['max' => 5],
            'http_errors' => false,
        ]);

        $checked = 0;
        $errors = 0;

        Link::where('is_active', true)
            ->select(['id', 'original_url'])
            ->chunk(50, function ($links) use ($http, &$checked, &$errors) {
                foreach ($links as $link) {
                    try {
                        $response = $http->head($link->original_url);
                        $code = $response->getStatusCode();
                        $status = ($code >= 200 && $code < 400) ? 'ok' : 'error';
                    } catch (\Exception $e) {
                        $status = 'error';
                    }

                    $checked++;
                    if ($status === 'error') {
                        $errors++;
                    }

                    DB::table('links')
                        ->where('id', $link->id)
                        ->update([
                            'health_status' => $status,
                            'health_checked_at' => now(),
                        ]);
                }
            });

        return [$checked, $errors];
    }
}
