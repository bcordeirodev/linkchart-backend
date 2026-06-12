<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Services\Links\LinkTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Enriches and persists one click event captured by the redirect controller.
 *
 * Trigger: `RedirectController::dispatchTracking()` (line 121 of
 * `app/Http/Controllers/Links/RedirectController.php`) calls
 * `ProcessLinkClickJob::dispatch($link->id, $payload)` at line 143
 * for every human (non-bot, non-preview) redirect.
 *
 * Side effects:
 *   - DB writes: inserts one row into `clicks` via
 *     `LinkTrackingService::registrarCliqueFromPayload()`; also increments
 *     `links.clicks` via a direct `DB::table->increment()` inside that service
 *     (no model events, keeping the Link cache stable).
 *   - Cache: none written by this job directly.
 *   - Queue: no further jobs are dispatched.
 *   - Log channels: `tracking` (click details), `jobs` (lifecycle — started /
 *     succeeded / failed), via `AppLogger::jobStarted`, `AppLogger::jobSucceeded`,
 *     `AppLogger::jobFailed`.
 *   - HTTP / external calls: GeoIP lookup (torann/geoip, may hit a local DB
 *     or remote service depending on config) is performed inside
 *     `LinkTrackingService`.
 *
 * Request-id propagation (HasLogContext):
 *   The payload carries `request_id` from `RequestContext::current()` at
 *   dispatch time. `handle()` calls `$this->pushLogContext()` which calls
 *   `RequestContext::set()`, restoring that id in the worker process so every
 *   log line emitted inside the job shares the same `request_id` as the
 *   originating HTTP redirect. `popLogContext()` clears the context in `finally`.
 *   See {@see \App\Logging\Context\HasLogContext}.
 *
 * Retry policy:
 *   - `$tries = 3` — up to three attempts before the job is considered failed.
 *   - `$backoff = 10` — 10 seconds between retries.
 *   - On final failure: the exception is re-thrown by `handle()` after logging
 *     via `AppLogger::jobFailed`. Laravel will move the job to the failed-jobs
 *     table. No explicit `failed()` callback is defined; the `jobs` channel
 *     already has a record from the last `jobFailed` log.
 *
 * Idempotency: YES (best-effort, request-id based).
 *   Before persisting, `alreadyProcessed()` checks a cache marker keyed by the
 *   payload's request_id; after a successful insert, `markProcessed()` sets it
 *   (TTL 1h). A retry of the same click is skipped and logged as
 *   `job.duplicate_skipped` on the `jobs` channel. When Redis is unavailable
 *   or the payload has no request_id, behavior degrades to the old
 *   at-least-once semantics (duplicates possible, under-counting avoided).
 *
 * @see \App\Services\Links\LinkTrackingService::registrarCliqueFromPayload()
 * @see \App\Logging\Context\HasLogContext
 */
class ProcessLinkClickJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    /**
     * @param  array<string,mixed>  $payload  See LinkTrackingService::registrarCliqueFromPayload signature.
     */
    public function __construct(
        public readonly int $linkId,
        public readonly array $payload,
    ) {}

    /**
     * Run the tracking service inside a request context populated from
     * $payload['request_id'] so every log line carries the same id as the
     * originating HTTP redirect. A best-effort dedup guard (via Cache) skips
     * processing when this request_id was already persisted by an earlier
     * attempt, preventing duplicate `clicks` rows on retry.
     */
    public function handle(LinkTrackingService $trackingService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, ['link_id' => $this->linkId]);

        try {
            if ($this->alreadyProcessed()) {
                AppLogger::event('jobs', 'info', 'job.duplicate_skipped', [
                    'job' => static::class,
                    'link_id' => $this->linkId,
                ]);
                AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);

                return;
            }

            $trackingService->registrarCliqueFromPayload($this->linkId, $this->payload);
            $this->markProcessed();
            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        } finally {
            $this->popLogContext();
        }
    }

    /** {@inheritDoc} */
    protected function logContextRequestId(): ?string
    {
        return $this->payload['request_id'] ?? null;
    }

    /**
     * Cache key used to deduplicate retries of the same click event, derived
     * from the request_id captured at redirect time. Null when the payload has
     * no request_id (dedup disabled for that event).
     */
    private function dedupCacheKey(): ?string
    {
        $requestId = $this->payload['request_id'] ?? null;

        return $requestId ? 'click:processed:'.$requestId : null;
    }

    /**
     * Whether this click event was already fully persisted by a previous
     * attempt. Degrades to false (process normally) when the cache backend is
     * unavailable — over-counting on retry is the accepted fallback.
     */
    private function alreadyProcessed(): bool
    {
        $key = $this->dedupCacheKey();

        if (! $key) {
            return false;
        }

        try {
            return Cache::has($key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Mark this click event as persisted so a retry of the same request_id is
     * skipped. The 1-hour TTL comfortably covers the retry window (3 tries,
     * 10s backoff). Cache failures are swallowed: the marker is best-effort.
     */
    private function markProcessed(): void
    {
        $key = $this->dedupCacheKey();

        if (! $key) {
            return;
        }

        try {
            Cache::put($key, 1, now()->addHour());
        } catch (Throwable) {
            // Cache unavailable — accept potential duplicate on retry.
        }
    }
}
