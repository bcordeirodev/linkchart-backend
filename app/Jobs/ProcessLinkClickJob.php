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
 * Processes a click captured by the redirect controller asynchronously.
 *
 * The HTTP redirect path serializes only the data the LinkTrackingService
 * needs (IP, UA, referer, query, response time, request_id) and queues this
 * job so the 302 response is not blocked by enrichment work (geoip, UA parse,
 * temporal/behavior analysis).
 *
 * Adopts the originating request_id via HasLogContext so every log line
 * emitted during handle() is traceable back to the redirect.
 */
class ProcessLinkClickJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 30;

    /**
     * @param  int  $linkId
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

    /** @inheritDoc */
    protected function logContextRequestId(): ?string
    {
        return $this->payload['request_id'] ?? null;
    }
}
