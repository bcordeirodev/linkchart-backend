<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Onboarding\OnboardingDemoDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SeedDemoLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    public function handle(OnboardingDemoDataService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $service->run($user);
    }

    public function failed(\Throwable $e): void
    {
        \Log::channel('api_errors')->error('SeedDemoLinkJob failed', [
            'user_id' => $this->userId,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
