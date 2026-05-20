<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Click;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class SocialPlatformTrackingTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ip' => '8.8.8.8',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Mobile/15E148',
            'referer' => null,
            'accept_language' => 'pt-BR',
            'query_params' => [],
            'http_response_ms' => 42.0,
        ], $overrides);
    }

    public function test_detects_instagram_from_l_instagram_referer(): void
    {
        $link = $this->makeLink();
        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://l.instagram.com/?u=https%3A%2F%2Fexample.com&e=hash',
        ])))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertSame('instagram', $click->social_platform);
        $this->assertSame('social', $click->click_source);
    }

    public function test_detects_tiktok_from_vt_tiktok_referer(): void
    {
        $link = $this->makeLink();
        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://vt.tiktok.com/ZS8x5gm/',
        ])))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertSame('tiktok', $click->social_platform);
    }

    public function test_detects_facebook_from_lm_facebook_referer(): void
    {
        $link = $this->makeLink();
        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://lm.facebook.com/l.php?u=https%3A%2F%2Fexample.com',
        ])))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertSame('facebook', $click->social_platform);
    }

    public function test_social_platform_null_for_search_referer(): void
    {
        $link = $this->makeLink();
        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://www.google.com/search?q=test',
        ])))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertNull($click->social_platform);
        $this->assertSame('search', $click->click_source);
    }

    public function test_social_platform_null_when_no_referer(): void
    {
        $link = $this->makeLink();
        (new ProcessLinkClickJob($link->id, $this->payload(['referer' => null])))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertNull($click->social_platform);
        $this->assertSame('direct', $click->click_source);
    }
}
