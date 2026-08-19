<?php

namespace Tests\Feature;

use App\Jobs\FetchLinkPreviewJob;
use App\Models\Click;
use App\Models\Link;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinkMetaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authAs(User $user): static
    {
        return $this->actingAs($user, 'api');
    }

    /**
     * Creates $count verified-owner links for batch-meta scenarios.
     *
     * @return \Illuminate\Support\Collection<int, Link>
     */
    private function makeLinksFor(User $user, int $count): \Illuminate\Support\Collection
    {
        return Link::factory()->count($count)->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_batch_meta_returns_data_for_owned_links(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $response = $this->authAs($user)->postJson(
            '/api/links/batch-meta',
            ['ids' => [(int) $link->id]]
        );

        $response->assertOk()
            ->assertJsonPath("data.{$link->id}.health.status", 'unknown')
            ->assertJsonStructure([
                'data' => [
                    (string) $link->id => [
                        'sparkline',
                        'trend' => ['current', 'previous', 'percent_change', 'last_click_at'],
                        'health' => ['status', 'last_checked_at', 'http_code'],
                        'quality' => ['tier', 'organic_pct'],
                    ],
                ],
            ]);
    }

    /**
     * Characterization of the batch-meta response VALUES (not just structure):
     * sparkline daily counts, trend aggregates and the preview payload are a
     * locked contract consumed by the frontend links list. This test was
     * written against the original per-link implementation and must keep
     * passing identically after the batched-aggregation rewrite.
     */
    public function test_batch_meta_returns_real_sparkline_trend_and_preview_values(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        [$linkA, $linkB] = $this->makeLinksFor($user, 2);

        // Link A: 2 clicks today + 1 click yesterday; fresh preview (no re-fetch).
        Click::factory()->count(2)->create(['link_id' => $linkA->id, 'created_at' => now()]);
        Click::factory()->create(['link_id' => $linkA->id, 'created_at' => now()->subDay()]);
        LinkPreview::create([
            'link_id' => $linkA->id,
            'favicon_url' => 'https://example.com/favicon.ico',
            'og_title' => 'Example Title',
            'og_image_url' => 'https://example.com/og.png',
            'fetched_at' => now(),
        ]);

        $response = $this->authAs($user)->postJson('/api/links/batch-meta', [
            'ids' => [(int) $linkA->id, (int) $linkB->id],
            'days' => 7,
        ]);

        $response->assertOk();

        $a = $response->json("data.{$linkA->id}");
        $b = $response->json("data.{$linkB->id}");

        // Sparkline: 7 zero-filled daily buckets, newest last.
        $this->assertCount(7, $a['sparkline']);
        $this->assertSame(now()->format('Y-m-d'), $a['sparkline'][6]['date']);
        $this->assertSame(2, $a['sparkline'][6]['clicks']);
        $this->assertSame(1, $a['sparkline'][5]['clicks']);
        $this->assertSame(0, array_sum(array_column($b['sparkline'], 'clicks')));

        // Trend: 3 clicks in the current window, none before => +100%.
        // percent_change is a float in PHP but loses the zero fraction on the
        // wire (json_encode without JSON_PRESERVE_ZERO_FRACTION), so whole
        // numbers decode as int — asserted as the frontend actually sees them.
        $this->assertSame(3, $a['trend']['current']);
        $this->assertSame(0, $a['trend']['previous']);
        $this->assertSame(100, $a['trend']['percent_change']);
        $this->assertNotNull($a['trend']['last_click_at']);
        $this->assertSame(0, $b['trend']['current']);
        $this->assertSame(0, $b['trend']['percent_change']);
        $this->assertNull($b['trend']['last_click_at']);

        // Preview: full payload for A, null for B (never fetched).
        $this->assertSame([
            'favicon_url' => 'https://example.com/favicon.ico',
            'og_title' => 'Example Title',
            'og_image_url' => 'https://example.com/og.png',
        ], $a['preview']);
        $this->assertNull($b['preview']);

        // A fresh preview must not be re-fetched; B's missing one must be.
        Queue::assertPushed(FetchLinkPreviewJob::class, 1);
    }

    /**
     * The preview back-fill is capped: a single batch-meta request may enqueue
     * at most 10 FetchLinkPreviewJob dispatches — the remaining stale/missing
     * previews are picked up by subsequent calls. Guards against a 50-id
     * request flooding the queue with 50 HTTP-fetching jobs at once.
     */
    public function test_batch_meta_caps_preview_dispatches_at_ten_per_request(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $links = $this->makeLinksFor($user, 15);

        $this->authAs($user)->postJson('/api/links/batch-meta', [
            'ids' => $links->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ])->assertOk();

        Queue::assertPushed(FetchLinkPreviewJob::class, 10);
    }

    /**
     * The aggregation must run a constant number of queries regardless of how
     * many ids are requested (batched GROUP BY link_id), instead of the old
     * ~4 queries per link. 10 links stay comfortably under the bound that the
     * per-link implementation blew past (40+ queries).
     */
    public function test_batch_meta_query_count_does_not_scale_with_link_count(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $links = $this->makeLinksFor($user, 10);
        foreach ($links as $link) {
            Click::factory()->create(['link_id' => $link->id, 'created_at' => now()]);
        }

        $this->authAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson('/api/links/batch-meta', [
            'ids' => $links->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ])->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            12,
            $queries,
            "batch-meta ran {$queries} queries for 10 links — aggregation is not batched"
        );
    }

    public function test_batch_meta_ignores_other_users_links(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $other = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $other->id]);

        $response = $this->authAs($user)->postJson(
            '/api/links/batch-meta',
            ['ids' => [(int) $link->id]]
        );

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_batch_meta_requires_auth(): void
    {
        $this->postJson('/api/links/batch-meta', ['ids' => [1]])->assertUnauthorized();
    }

    public function test_sparkline_returns_n_daily_points(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->authAs($user)->getJson("/api/links/{$link->id}/sparkline?days=7");

        $response->assertOk();
        $this->assertCount(7, $response->json('data'));
    }

    public function test_trend_returns_correct_structure(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->authAs($user)->getJson("/api/links/{$link->id}/trend");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['current', 'previous', 'percent_change', 'last_click_at']]);
    }
}
