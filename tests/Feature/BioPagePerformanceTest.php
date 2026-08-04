<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for GET /api/bio/performance (BioPageController::performance).
 *
 * Aggregates the EXISTING `clicks` table through each bio page item's
 * `link_id` — no page-view tracking, no new tables (product decision
 * 2026-08-04). Mirrors the auth setup of {@see BioPageItemsTest}.
 */
class BioPagePerformanceTest extends TestCase
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

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_requires_authentication(): void
    {
        // tymon/jwt-auth's JWTGuard caches the resolved user on the guard
        // instance once `login()` succeeds (see setUp()) and does not
        // re-parse the request on every `user()`/`check()` call — without an
        // explicit logout() here, this HTTP call would still be treated as
        // authenticated even without an Authorization header, since the
        // guard instance is reused across calls within the same test.
        auth()->guard('api')->logout();

        $this->getJson('/api/bio/performance')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_returns_zeroed_payload_when_user_has_no_bio_page(): void
    {
        $response = $this->getJson('/api/bio/performance', $this->auth());

        $response->assertOk()->assertJsonStructure(['data' => ['total_clicks', 'items']]);
        $this->assertSame(0, $response->json('data.total_clicks'));
        $this->assertSame([], $response->json('data.items'));
    }

    public function test_returns_zeroed_payload_when_bio_page_has_no_items(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $response->assertOk();
        $this->assertSame(0, $response->json('data.total_clicks'));
        $this->assertSame([], $response->json('data.items'));
    }

    /**
     * Totais corretos + ranking por cliques (maior primeiro), com o shape
     * exato do contrato: item_id, title, display, social_platform, clicks.
     */
    public function test_totals_and_ranking_from_fixture_clicks(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $linkA = Link::factory()->create(['user_id' => $this->user->id]);
        $linkB = Link::factory()->create(['user_id' => $this->user->id]);
        $itemA = BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $linkA->id, 'label' => 'Item A', 'position' => 0,
        ]);
        $itemB = BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $linkB->id, 'label' => 'Item B', 'position' => 1,
            'display' => 'icon', 'social_platform' => 'instagram',
        ]);
        Click::factory()->count(3)->create(['link_id' => $linkA->id, 'created_at' => now()->subDay()]);
        Click::factory()->count(7)->create(['link_id' => $linkB->id, 'created_at' => now()->subDay()]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $response->assertOk()->assertJsonStructure([
            'data' => ['total_clicks', 'items' => [['item_id', 'title', 'display', 'social_platform', 'clicks']]],
        ]);
        $this->assertSame(10, $response->json('data.total_clicks'));

        $items = $response->json('data.items');
        $this->assertSame($itemB->id, $items[0]['item_id']);
        $this->assertSame('Item B', $items[0]['title']);
        $this->assertSame('icon', $items[0]['display']);
        $this->assertSame('instagram', $items[0]['social_platform']);
        $this->assertSame(7, $items[0]['clicks']);
        $this->assertSame($itemA->id, $items[1]['item_id']);
        $this->assertSame('item', $items[1]['display']);
        $this->assertNull($items[1]['social_platform']);
        $this->assertSame(3, $items[1]['clicks']);
    }

    /**
     * Escopo estrito ao dono: cliques de outro usuário nunca entram na conta,
     * mesmo que apontem para um link cujo id colide numericamente com nada
     * do dono (aqui, simplesmente um usuário totalmente diferente).
     */
    public function test_another_users_clicks_are_never_counted(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        $other = User::factory()->create();
        $otherLink = Link::factory()->create(['user_id' => $other->id]);
        Click::factory()->count(5)->create(['link_id' => $otherLink->id, 'created_at' => now()->subDay()]);
        Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subDay()]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $this->assertSame(2, $response->json('data.total_clicks'));
    }

    /**
     * period=7d/30d/90d/all recortam a janela corretamente contra a mesma
     * massa de cliques (2/20/60 dias atrás).
     */
    public function test_period_filtering_scopes_the_window(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subDays(2)]);
        Click::factory()->count(3)->create(['link_id' => $link->id, 'created_at' => now()->subDays(20)]);
        Click::factory()->count(5)->create(['link_id' => $link->id, 'created_at' => now()->subDays(60)]);

        $this->assertSame(2, $this->getJson('/api/bio/performance?period=7d', $this->auth())->json('data.total_clicks'));
        $this->assertSame(5, $this->getJson('/api/bio/performance?period=30d', $this->auth())->json('data.total_clicks'));
        $this->assertSame(10, $this->getJson('/api/bio/performance?period=90d', $this->auth())->json('data.total_clicks'));
        $this->assertSame(10, $this->getJson('/api/bio/performance?period=all', $this->auth())->json('data.total_clicks'));
    }

    public function test_default_period_is_thirty_days(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        Click::factory()->count(4)->create(['link_id' => $link->id, 'created_at' => now()->subDays(10)]);
        Click::factory()->count(9)->create(['link_id' => $link->id, 'created_at' => now()->subDays(60)]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $this->assertSame(4, $response->json('data.total_clicks'));
    }

    public function test_rejects_invalid_period(): void
    {
        $this->getJson('/api/bio/performance?period=1y', $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * Regra do produto (feedback_exclude_demo_clicks): cliques de um link
     * is_demo NUNCA contam, nem no painel de desempenho pessoal do próprio
     * dono do link demo.
     */
    public function test_excludes_clicks_from_demo_links(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $demoLink = Link::factory()->create(['user_id' => $this->user->id, 'is_demo' => true]);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $demoLink->id, 'position' => 0]);
        Click::factory()->count(5)->create(['link_id' => $demoLink->id, 'created_at' => now()->subDay()]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $this->assertSame(0, $response->json('data.total_clicks'));
        $this->assertSame(0, $response->json('data.items.0.clicks'));
    }

    /**
     * Itens inativos continuam no ranking (o dono decide reativar com base
     * no desempenho histórico) — só o payload PÚBLICO exclui inativos.
     */
    public function test_includes_inactive_items_in_the_ranking(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0, 'is_active' => false,
        ]);
        Click::factory()->count(6)->create(['link_id' => $link->id, 'created_at' => now()->subDay()]);

        $response = $this->getJson('/api/bio/performance', $this->auth());

        $this->assertSame(6, $response->json('data.total_clicks'));
        $this->assertCount(1, $response->json('data.items'));
    }
}
