<?php

namespace App\Models\Observers;

use App\Jobs\SeedDemoLinkJob;
use App\Jobs\SendWelcomeEmailJob;
use App\Models\User;

/**
 * Observes lifecycle events on User.
 *
 * Registered in App\Providers\AppServiceProvider::boot() via:
 *     User::observe(UserObserver::class);
 *
 * Only reacts to `created`, which fans out the onboarding side effects: seeding a
 * demo link and enqueueing the welcome email. The observer does not decide whether
 * the email actually goes out — SendWelcomeEmailJob guards on `hasVerifiedEmail()`,
 * so an unverified email/password signup is enqueued here and simply returns without
 * sending, then gets a second dispatch once the user verifies.
 */
class UserObserver
{
    /**
     * Fan out onboarding side effects for the newly registered user.
     */
    public function created(User $user): void
    {
        SeedDemoLinkJob::dispatch($user->id);
        SendWelcomeEmailJob::dispatch($user->id);
    }
}
