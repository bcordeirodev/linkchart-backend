<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\User;
use App\Services\Onboarding\OnboardingDemoDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Seeds demo link data for a newly registered user as part of onboarding.
 *
 * Trigger: `UserObserver::created()` (line 10 of
 * `app/Models/Observers/UserObserver.php`) calls
 * `SeedDemoLinkJob::dispatch($user->id)` on every `User` `created` event.
 * The observer is registered in `AppServiceProvider::boot()`.
 *
 * Side effects:
 *   - DB writes: delegates to `OnboardingDemoDataService::run()`, which
 *     creates one demo `Link` row and seeds multiple historical `clicks` rows
 *     for that link so the user sees a populated analytics dashboard on first
 *     login.
 *   - Cache: none written directly; Eloquent `saved` / `created` events on
 *     `Link` will fire the Link cache-invalidation observer.
 *   - Queue: no further jobs are dispatched.
 *   - Log channels: `jobs` (lifecycle — started / succeeded / failed) via
 *     `AppLogger::jobStarted`, `AppLogger::jobSucceeded`, `AppLogger::jobFailed`.
 *     The `app` channel may receive entries from within `OnboardingDemoDataService`.
 *   - HTTP / external calls: none.
 *
 * Request-id propagation (HasLogContext):
 *   No originating request_id is available because the job is dispatched
 *   after the HTTP registration response is already sent.
 *   `logContextRequestId()` returns `null`, so `HasLogContext::pushLogContext()`
 *   generates a fallback id (`job_<hex>`) that correlates all log lines within
 *   this job execution. `logContextUserId()` returns `$this->userId` so logs
 *   are also tagged with the new user's id.
 *   See {@see \App\Logging\Context\HasLogContext}.
 *
 * Retry policy:
 *   - `$tries = 3` — up to three attempts.
 *   - `$backoff = 30` — 30 seconds between retries.
 *   - On final failure: `failed(Throwable $e)` is called, which logs via
 *     `AppLogger::jobFailed(static::class, $e, $this->tries)` to the `jobs`
 *     channel.
 *
 * Idempotency: NO.
 *   Running the job twice for the same user creates a second demo Link row
 *   and another set of seeded clicks. There is no deduplication check (e.g.
 *   "does this user already have a demo link?"). The only practical guard is
 *   that `UserObserver::created()` fires exactly once per user registration;
 *   duplicate dispatches would only occur on infrastructure failure.
 *
 * @see \App\Services\Onboarding\OnboardingDemoDataService::run()
 * @see \App\Models\Observers\UserObserver
 * @see \App\Logging\Context\HasLogContext
 */
class SeedDemoLinkJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    /**
     * Execute the seed inside a request context populated with a fallback id,
     * so logs emitted during the seed are still correlated within this run.
     */
    public function handle(OnboardingDemoDataService $service): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, ['user_id' => $this->userId]);

        try {
            $user = User::find($this->userId);

            if (! $user) {
                AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);

                return;
            }

            $service->run($user);
            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        } finally {
            $this->popLogContext();
        }
    }

    /**
     * Final failure callback (after exhausting retries).
     */
    public function failed(Throwable $e): void
    {
        AppLogger::jobFailed(static::class, $e, $this->tries);
    }

    /** {@inheritDoc} */
    protected function logContextRequestId(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    protected function logContextUserId(): ?int
    {
        return $this->userId;
    }
}
