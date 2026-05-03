<?php

namespace App\Jobs;

use App\Models\LinkPreview;
use App\Services\Links\LinkPreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchLinkPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

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
}
