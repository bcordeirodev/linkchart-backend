<?php

namespace Tests;

use App\Jobs\SeedDemoLinkJob;
use App\Jobs\SendWelcomeEmailJob;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    /**
     * When true (the default), {@see setUp()} fakes the two onboarding jobs
     * dispatched by `UserObserver::created` so they never run inline during
     * `User::factory()->create()`.
     *
     * Why this exists: `phpunit.xml` forces `QUEUE_CONNECTION=sync`, and
     * `UserFactory::definition()` creates users already verified. Without this,
     * every factory-created user makes `SendWelcomeEmailJob` execute synchronously,
     * claim `welcome_email_sent_at`, and call the real `EmailService` — which fails
     * because `.env.testing` has no `SENDGRID_API_KEY`. That wrote real ERROR-level
     * log lines (`AppLogger::jobFailed` x2 + `AppLogger::emailFailed`) into
     * `storage/logs/errors-*.log` on every single test that creates a user, drowning
     * out genuine failures.
     *
     * Set to `false` in a test class that needs the jobs to actually run — e.g. a
     * test asserting on the job's real dispatch/execution behavior — but do so with
     * care: it restores the log-pollution risk for that class, so keep such classes
     * narrowly scoped and prefer mocking collaborators (as
     * `SendWelcomeEmailJobTest` does) over letting the real `EmailService` fire.
     */
    protected bool $fakeOnboardingJobs = true;

    /**
     * The default auth guard as configured BEFORE any request ran, captured in
     * {@see setUp()}. AuthManager::shouldUse() (invoked by the api.auth
     * middleware) mutates config('auth.defaults.guard') for the rest of the
     * test, so the original value must be snapshotted up front for
     * {@see flushAuthState()} to restore it.
     */
    private string $defaultAuthGuard;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultAuthGuard = $this->app['config']['auth.defaults.guard'];

        if ($this->fakeOnboardingJobs) {
            Queue::fake([SendWelcomeEmailJob::class, SeedDemoLinkJob::class]);
        }
    }

    /**
     * Resets cached authentication state between HTTP requests made within a
     * single test.
     *
     * Why this exists: feature tests reuse one booted application for every
     * request in a test, so three pieces of auth state leak across requests —
     * none of which can happen in production, where each request boots a
     * fresh app:
     *
     *  1. Guards cache the resolved user; a Bearer token sent on the next
     *     request may be ignored entirely in favor of the cached user.
     *  2. The tymon `tymon.jwt` singleton keeps the last parsed token.
     *  3. api.auth's AuthManager::shouldUse('api') repoints auth() at the api
     *     guard for all subsequent requests — desyncing login's
     *     JWTAuth::attempt() (which sets the user on the guard captured by
     *     tymon's auth provider at first resolution) from auth()->user().
     *
     * Implementation notes: guard instances must NOT be discarded via
     * AuthManager::forgetGuards() — tymon's auth-provider singleton holds a
     * reference to the original instance. Instead, the cached user is cleared
     * on the existing instances (bound closure — there is no public API to
     * un-set a guard's user) and the default guard is restored.
     *
     * Call this before any request whose Bearer token differs from the
     * previous request's. Do NOT call it while relying on actingAs() — it
     * clears the acting user too.
     */
    protected function flushAuthState(): void
    {
        foreach (['web', 'api'] as $guardName) {
            $guard = $this->app['auth']->guard($guardName);
            (fn () => $this->user = null)->call($guard);
        }

        $this->app['tymon.jwt']->unsetToken();

        $this->app['auth']->shouldUse($this->defaultAuthGuard);
    }
}
