<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the /api/bio/items* item-management endpoints
 * (BioPageController::storeItem/updateItem/destroyItem/reorderItems).
 *
 * Mirrors the setup of {@see \Tests\Feature\BioPageManagementTest}: JWT auth
 * via the api guard, RefreshDatabase per test.
 */
class BioPageItemsTest extends TestCase
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

    /**
     * O payload de gestão expõe o favicon do destino (link_previews) por item
     * — âncora visual da lista do editor. Sem preview buscado ainda, null.
     */
    public function test_management_items_expose_favicon_url_or_null(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $withPreview = Link::factory()->create(['user_id' => $this->user->id]);
        $withoutPreview = Link::factory()->create(['user_id' => $this->user->id]);
        $withPreview->preview()->create([
            'favicon_url' => 'https://example.com/favicon.ico',
            'fetched_at' => now(),
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id,
            'link_id' => $withPreview->id,
            'position' => 0,
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id,
            'link_id' => $withoutPreview->id,
            'position' => 1,
        ]);

        $items = $this->getJson('/api/bio', $this->auth())->json('data.items');

        $this->assertSame('https://example.com/favicon.ico', $items[0]['favicon_url']);
        $this->assertNull($items[1]['favicon_url']);
    }

    public function test_store_item_defaults_label_to_link_title(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'My Landing Page']);

        $response = $this->postJson('/api/bio/items', ['link_id' => $link->id], $this->auth());

        $response->assertStatus(201)->assertJsonStructure([
            'data' => ['id', 'link_id', 'label', 'position', 'is_active', 'url', 'clicks'],
        ]);
        $this->assertSame('My Landing Page', $response->json('data.label'));
        $this->assertSame(0, $response->json('data.position'));

        $this->assertDatabaseHas('bio_page_items', [
            'bio_page_id' => $page->id,
            'link_id' => $link->id,
            'label' => 'My Landing Page',
        ]);
    }

    public function test_store_item_falls_back_to_slug_when_link_has_no_title(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => null, 'slug' => 'my-slug']);

        $response = $this->postJson('/api/bio/items', ['link_id' => $link->id], $this->auth());

        $response->assertStatus(201);
        $this->assertSame('my-slug', $response->json('data.label'));
    }

    public function test_store_item_uses_custom_label_when_provided(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Original Title']);

        $response = $this->postJson('/api/bio/items', [
            'link_id' => $link->id,
            'label' => 'Custom Label',
        ], $this->auth());

        $response->assertStatus(201);
        $this->assertSame('Custom Label', $response->json('data.label'));
    }

    public function test_store_item_appends_at_the_end_position(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $linkA = Link::factory()->create(['user_id' => $this->user->id]);
        $linkB = Link::factory()->create(['user_id' => $this->user->id]);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $linkA->id, 'position' => 0]);

        $response = $this->postJson('/api/bio/items', ['link_id' => $linkB->id], $this->auth());

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('data.position'));
    }

    public function test_store_item_rejects_link_owned_by_another_user(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);
        $other = User::factory()->create();
        $foreignLink = Link::factory()->create(['user_id' => $other->id]);

        $this->postJson('/api/bio/items', ['link_id' => $foreignLink->id], $this->auth())
            ->assertStatus(422);

        $this->assertDatabaseMissing('bio_page_items', ['link_id' => $foreignLink->id]);
    }

    public function test_store_item_rejects_when_page_not_created_yet(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->postJson('/api/bio/items', ['link_id' => $link->id], $this->auth())
            ->assertStatus(422);
    }

    public function test_store_item_enforces_cap_of_twenty(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $links = Link::factory()->count(20)->create(['user_id' => $this->user->id]);
        foreach ($links as $i => $link) {
            BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => $i]);
        }

        $oneMore = Link::factory()->create(['user_id' => $this->user->id]);

        $this->postJson('/api/bio/items', ['link_id' => $oneMore->id], $this->auth())
            ->assertStatus(422);

        $this->assertDatabaseCount('bio_page_items', 20);
    }

    public function test_update_item_modifies_label_and_active(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $item = BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id]);

        $response = $this->putJson("/api/bio/items/{$item->id}", [
            'label' => 'Updated Label',
            'is_active' => false,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame('Updated Label', $response->json('data.label'));
        $this->assertFalse($response->json('data.is_active'));

        $this->assertDatabaseHas('bio_page_items', [
            'id' => $item->id,
            'label' => 'Updated Label',
            'is_active' => false,
        ]);
    }

    public function test_update_item_denies_access_to_other_user_item(): void
    {
        $other = User::factory()->create();
        $otherPage = BioPage::factory()->create(['user_id' => $other->id]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);
        $item = BioPageItem::factory()->create(['bio_page_id' => $otherPage->id, 'link_id' => $otherLink->id, 'label' => 'Theirs']);

        $this->putJson("/api/bio/items/{$item->id}", ['label' => 'Hacked'], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('bio_page_items', ['id' => $item->id, 'label' => 'Theirs']);
    }

    public function test_destroy_item_deletes_owned_item(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $item = BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id]);

        $response = $this->deleteJson("/api/bio/items/{$item->id}", [], $this->auth());

        $response->assertOk();
        $this->assertTrue($response->json('data.deleted'));
        $this->assertDatabaseMissing('bio_page_items', ['id' => $item->id]);
    }

    public function test_destroy_item_denies_access_to_other_user_item(): void
    {
        $other = User::factory()->create();
        $otherPage = BioPage::factory()->create(['user_id' => $other->id]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);
        $item = BioPageItem::factory()->create(['bio_page_id' => $otherPage->id, 'link_id' => $otherLink->id]);

        $this->deleteJson("/api/bio/items/{$item->id}", [], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('bio_page_items', ['id' => $item->id]);
    }

    public function test_reorder_items_updates_positions(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $linkA = Link::factory()->create(['user_id' => $this->user->id]);
        $linkB = Link::factory()->create(['user_id' => $this->user->id]);
        $itemA = BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $linkA->id, 'position' => 0]);
        $itemB = BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $linkB->id, 'position' => 1]);

        $response = $this->putJson('/api/bio/items-order', [
            'ids' => [$itemB->id, $itemA->id],
        ], $this->auth());

        $response->assertOk();
        $this->assertDatabaseHas('bio_page_items', ['id' => $itemB->id, 'position' => 0]);
        $this->assertDatabaseHas('bio_page_items', ['id' => $itemA->id, 'position' => 1]);

        $items = $response->json('data.items');
        $this->assertSame($itemB->id, $items[0]['id']);
        $this->assertSame($itemA->id, $items[1]['id']);
    }

    public function test_reorder_items_rejects_mismatched_ids(): void
    {
        $page = BioPage::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $item = BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        $this->putJson('/api/bio/items-order', ['ids' => [$item->id, 999999]], $this->auth())
            ->assertStatus(422);

        $this->assertDatabaseHas('bio_page_items', ['id' => $item->id, 'position' => 0]);
    }

    public function test_reorder_items_rejects_when_page_not_created(): void
    {
        $this->putJson('/api/bio/items-order', ['ids' => [1, 2]], $this->auth())
            ->assertStatus(422);
    }
}
