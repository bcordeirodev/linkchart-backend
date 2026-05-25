<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Models\LinkPreview;
use App\Services\Links\LinkPreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetches Open Graph / meta preview data for a link and persists the result.
 *
 * Trigger (two call sites in `app/Http/Controllers/Links/LinkMetaController.php`):
 *   - `batchMeta()` at line 47 — dispatched for each link whose preview is
 *     missing or older than 24 hours in a batch-meta request.
 *   - `preview()` at line 100 — dispatched for a single link on direct
 *     preview requests when the stored preview is stale.
 *   Both call `FetchLinkPreviewJob::dispatch($linkId, $url)`.
 *
 * Side effects:
 *   - HTTP / external calls: `LinkPreviewService::fetchPreview()` issues an
 *     HTTP GET to `$url` to extract `<title>`, `og:*`, and favicon metadata.
 *   - DB writes: `LinkPreview::updateOrCreate(['link_id' => $linkId], ...)`
 *     — upserts one row in `link_previews`, setting `fetched_at = now()`.
 *   - Cache: none written by this job.
 *   - Queue: no further jobs are dispatched.
 *   - Log channels: none (no `AppLogger` calls; exceptions bubble to the
 *     framework failed-job handler).
 *
 * Retry policy:
 *   - `$tries = 3` — three attempts before the job is discarded.
 *   - `$backoff`: [10, 60] — 10 s before attempt 2, 60 s before attempt 3,
 *     so a temporarily unavailable host has time to recover.
 *   - On final failure: `failed()` logs via AppLogger::jobFailed to the
 *     `jobs` channel; the job then moves to the failed-jobs table.
 *
 * Idempotency: YES.
 *   `updateOrCreate` is keyed on `link_id`. Re-running the job just
 *   re-fetches the remote URL and overwrites the existing preview row —
 *   no duplicate rows are created.
 *
 * @see \App\Services\Links\LinkPreviewService::fetchPreview()
 * @see \App\Models\LinkPreview
 */
class FetchLinkPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Exponential-ish backoff: wait 10 s before retry 2, 60 s before retry 3.
     * Gives a temporarily unavailable host time to recover without hammering it.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(
        public readonly int $linkId,
        public readonly string $url
    ) {}

    public function handle(LinkPreviewService $previewService): void
    {
        $data = $previewService->fetchPreview($this->url);

        LinkPreview::updateOrCreate(
            ['link_id' => $this->linkId],
            array_merge($data, ['fetched_at' => now()])
        );
    }

    /**
     * Called after all retry attempts are exhausted.
     * Writes a structured error entry to the jobs log channel so the failure
     * is observable without having to query the failed_jobs table.
     */
    public function failed(\Throwable $e): void
    {
        AppLogger::jobFailed(self::class, $e, $this->attempts());
    }
}
