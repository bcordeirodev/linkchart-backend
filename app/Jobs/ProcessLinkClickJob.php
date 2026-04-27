<?php

namespace App\Jobs;

use App\Services\Links\LinkTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLinkClickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    public function __construct(
        public readonly int $linkId,
        public readonly array $payload,
    ) {}

    public function handle(LinkTrackingService $trackingService): void
    {
        $trackingService->registrarCliqueFromPayload($this->linkId, $this->payload);
    }
}
