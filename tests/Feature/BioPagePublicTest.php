<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the public, unauthenticated GET /api/public/bio/{handle}
 * endpoint (PublicBioController::show).
 *
 * This is the endpoint the frontend's public bio page hits directly — the
 * response must never leak user_id, email, or original_url, only the
 * handle/title/bio/theme and each active item's id/label/short url.
 */
class BioPagePublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_active_page_with_active_items_in_order(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create([
            'user_id' => $user->id,
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'bio' => 'Minha bio.',
            'theme' => 'dark',
            'is_active' => true,
        ]);
        $linkA = Link::factory()->create(['user_id' => $user->id, 'original_url' => 'https://example.com/a']);
        $linkB = Link::factory()->create(['user_id' => $user->id, 'original_url' => 'https://example.com/b']);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $linkB->id, 'label' => 'Second', 'position' => 1,
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $linkA->id, 'label' => 'First', 'position' => 0,
        ]);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk()->assertJsonStructure([
            'data' => ['handle', 'title', 'bio', 'theme', 'items' => [['id', 'label', 'url']]],
        ]);

        $this->assertSame('joaosilva', $response->json('data.handle'));
        $this->assertSame('João Silva', $response->json('data.title'));

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame('First', $items[0]['label']);
        $this->assertSame('Second', $items[1]['label']);
        $this->assertSame($linkA->getShortedUrl(), $items[0]['url']);
    }

    public function test_returns_404_for_unknown_handle(): void
    {
        $this->getJson('/api/public/bio/doesnotexist')->assertStatus(404);
    }

    public function test_returns_404_for_inactive_page(): void
    {
        $user = User::factory()->create();
        BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'inactivepage', 'is_active' => false]);

        $this->getJson('/api/public/bio/inactivepage')->assertStatus(404);
    }

    public function test_excludes_inactive_items(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'joaosilva']);
        $activeLink = Link::factory()->create(['user_id' => $user->id]);
        $inactiveLink = Link::factory()->create(['user_id' => $user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $activeLink->id, 'label' => 'Active', 'position' => 0, 'is_active' => true,
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $inactiveLink->id, 'label' => 'Inactive', 'position' => 1, 'is_active' => false,
        ]);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Active', $items[0]['label']);
    }

    public function test_response_never_leaks_user_id_or_original_url(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'joaosilva']);
        $link = Link::factory()->create(['user_id' => $user->id, 'original_url' => 'https://secret-destination.example/path']);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $raw = $response->getContent();
        $this->assertStringNotContainsString('user_id', $raw);
        $this->assertStringNotContainsString($user->email, $raw);
        $this->assertStringNotContainsString('secret-destination.example', $raw);
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
    }

    public function test_handle_lookup_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'joaosilva']);

        $this->getJson('/api/public/bio/JoaoSilva')->assertOk();
    }
}
