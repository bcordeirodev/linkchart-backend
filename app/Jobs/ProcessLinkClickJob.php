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
 * Idempotency: NO.
 *   Each retry inserts a new row in `clicks`, producing duplicate click records
 *   for the same user action. This is a known, accepted trade-off: under-counting
 *   is considered more harmful than occasional over-counting, and adding a
 *   deduplication key (e.g. a unique constraint on `request_id`) would require
 *   a schema migration that does not currently exist. If strict deduplication is
 *   ever required, a `request_id` column on `clicks` with a unique index is the
 *   recommended path.
 *
 * Forward reference: the docblock on `RedirectController::dispatchTracking()`
 * (line 116) does not describe the idempotency concern — that is tracked for
 * Task 3.7.
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
     * originating HTTP redirect.
     */
    public function handle(LinkTrackingService $trackingService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, ['link_id' => $this->linkId]);

        try {
            $trackingService->registrarCliqueFromPayload($this->linkId, $this->payload);
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
}
