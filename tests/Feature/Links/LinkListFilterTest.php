<?php

namespace Tests\Feature\Links;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the opt-in server-side pagination/filtering contract of GET /api/links.
 *
 * Without a `page` query param the endpoint must keep returning the legacy full
 * list response unchanged (blue/green compat). With `page`, it must return the
 * paginated envelope { data, meta } with the exact same per-item shape.
 */
class LinkListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /** Sem page, retorna a lista completa (compat com o frontend atual). */
    public function test_index_without_page_returns_full_list(): void
    {
        Link::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/links', $this->auth());

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertNull($response->json('meta'));
    }

    /** Com page, retorna envelope paginado com meta. */
    public function test_index_with_page_returns_paginated_meta(): void
    {
        Link::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/links?page=1&per_page=2', $this->auth());

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);

        // Per-item shape must be identical to the legacy branch.
        $item = $response->json('data')[0];
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('original_url', $item);
        $this->assertArrayHasKey('short_url', $item);
        $this->assertArrayHasKey('tags', $item);
    }

    /** q busca em título, url e slug, case-insensitive. */
    public function test_q_searches_title_url_and_slug(): void
    {
        Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Promo de verão', 'slug' => 'aaa111']);
        Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Outro link', 'original_url' => 'https://example.com/PROMO-page', 'slug' => 'bbb222']);
        Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Sem relação', 'slug' => 'promo-ccc']);
        Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Nada a ver', 'slug' => 'zzz999']);

        $response = $this->getJson('/api/links?page=1&per_page=50&q=PROMO', $this->auth());

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    /** status=expired retorna só links com expires_at no passado. */
    public function test_status_expired_filter(): void
    {
        $expired = Link::factory()->create(['user_id' => $this->user->id, 'expires_at' => now()->subDay()]);
        Link::factory()->create(['user_id' => $this->user->id, 'expires_at' => null, 'is_active' => true]);
        Link::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);

        $response = $this->getJson('/api/links?page=1&per_page=50&status=expired', $this->auth());

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($expired->id, $data[0]['id']);
    }

    /** sort=clicks&order=desc ordena pelo contador denormalizado. */
    public function test_sort_by_clicks(): void
    {
        $low = Link::factory()->create(['user_id' => $this->user->id, 'clicks' => 1]);
        $high = Link::factory()->create(['user_id' => $this->user->id, 'clicks' => 99]);
        $mid = Link::factory()->create(['user_id' => $this->user->id, 'clicks' => 50]);

        $response = $this->getJson('/api/links?page=1&per_page=50&sort=clicks&order=desc', $this->auth());

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$high->id, $mid->id, $low->id], $ids);
    }

    /** per_page acima de 50 é rejeitado com 422. */
    public function test_per_page_is_capped(): void
    {
        $response = $this->getJson('/api/links?page=1&per_page=51', $this->auth());

        $response->assertStatus(422);
    }

    /** Usuário só vê os próprios links (paginado ou não). */
    public function test_scoped_to_owner(): void
    {
        $other = User::factory()->create();
        Link::factory()->create(['user_id' => $other->id]);
        Link::factory()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/links?page=1&per_page=50', $this->auth());

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
