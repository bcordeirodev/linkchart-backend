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

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->fakeOnboardingJobs) {
            Queue::fake([SendWelcomeEmailJob::class, SeedDemoLinkJob::class]);
        }
    }
}
