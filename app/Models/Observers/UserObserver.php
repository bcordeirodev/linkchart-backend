<?php

namespace App\Models\Observers;

use App\Jobs\SeedDemoLinkJob;
use App\Models\User;

/**
 * Observes lifecycle events on User.
 *
 * Registered in App\Providers\AppServiceProvider::boot() via:
 *     User::observe(UserObserver::class);
 *
 * Currently only reacts to `created`, which dispatches SeedDemoLinkJob
 * to seed a demo link for the new user. The observer has no other
 * lifecycle reactions.
 */
class UserObserver
{
    /**
     * Dispatch SeedDemoLinkJob to create a demo link for the newly registered user.
     */
    public function created(User $user): void
    {
        SeedDemoLinkJob::dispatch($user->id);
    }
}
