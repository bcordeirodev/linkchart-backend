<?php

namespace App\Models\Observers;

use App\Jobs\SeedDemoLinkJob;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        SeedDemoLinkJob::dispatch($user->id);
    }
}
