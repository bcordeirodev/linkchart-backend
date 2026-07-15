<?php

namespace Tests\Feature\Links;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers POST /api/links/bulk-action (activate/deactivate/delete over up to
 * 50 owned links).
 *
 * The most important test here is test_bulk_delete_invalidates_slug_cache:
 * it proves the bulk action iterates Eloquent models (firing the `deleted` /
 * `saved` events that Link::booted() relies on to invalidate
 * findActiveBySlugCached()) rather than issuing a raw whereIn()->delete()/
 * ->update(), which would silently leave the redirect hot path serving a
 * stale cached Link.
 */
class LinkBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

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

    /** delete apaga os links próprios e reporta affected. */
    public function test_bulk_delete_own_links(): void
    {
        $links = Link::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/links/bulk-action', [
            'action' => 'delete',
            'ids' => $links->pluck('id')->all(),
        ], $this->auth());

        $response->assertOk();
        $response->assertJsonPath('data.affected', 3);
        $response->assertJsonPath('data.requested', 3);
        foreach ($links as $link) {
            $this->assertDatabaseMissing('links', ['id' => $link->id]);
        }
    }

    /** Ids de outro usuário são ignorados: affected < requested, links alheios intactos. */
    public function test_bulk_ignores_foreign_ids(): void
    {
        $own = Link::factory()->count(2)->create(['user_id' => $this->user->id]);
        $other = User::factory()->create();
        $foreign = Link::factory()->create(['user_id' => $other->id]);

        $ids = $own->pluck('id')->push($foreign->id)->all();

        $response = $this->postJson('/api/links/bulk-action', [
            'action' => 'delete',
            'ids' => $ids,
        ], $this->auth());

        $response->assertOk();
        $response->assertJsonPath('data.affected', 2);
        $response->assertJsonPath('data.requested', 3);
        $this->assertDatabaseHas('links', ['id' => $foreign->id]);
    }

    /** deactivate seta is_active=false via Eloquent (cache do slug invalidado). */
    public function test_bulk_deactivate(): void
    {
        $links = Link::factory()->count(2)->create(['user_id' => $this->user->id, 'is_active' => true]);

        $response = $this->postJson('/api/links/bulk-action', [
            'action' => 'deactivate',
            'ids' => $links->pluck('id')->all(),
        ], $this->auth());

        $response->assertOk();
        $response->assertJsonPath('data.affected', 2);
        foreach ($links as $link) {
            $this->assertDatabaseHas('links', ['id' => $link->id, 'is_active' => false]);
        }
    }

    /** Mais de 50 ids → 422. */
    public function test_bulk_caps_at_50(): void
    {
        $ids = range(1, 51);

        $response = $this->postJson('/api/links/bulk-action', [
            'action' => 'delete',
            'ids' => $ids,
        ], $this->auth());

        $response->assertStatus(422);
    }

    /** delete invalida o cache de slug: /r/{slug} deixa de redirecionar. */
    public function test_bulk_delete_invalidates_slug_cache(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'bulkcache1',
            'is_active' => true,
            'original_url' => 'https://example.com/destino',
        ]);

        // Populates Link::findActiveBySlugCached() cache entry.
        $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug)
            ->assertStatus(302);

        Queue::assertPushed(ProcessLinkClickJob::class);

        $this->postJson('/api/links/bulk-action', [
            'action' => 'delete',
            'ids' => [$link->id],
        ], $this->auth())->assertOk();

        // If the cache were still populated, this would still redirect (302).
        $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug)
            ->assertStatus(404);
    }
}
