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

    /**
     * Cada item público expõe o favicon do destino (ou null) — o botão da
     * página pública mostra para onde o clique leva, sinal de confiança que
     * o pipeline de previews já alimenta.
     */
    public function test_public_items_expose_favicon_url_or_null(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create([
            'user_id' => $user->id, 'handle' => 'comfavicon', 'is_active' => true,
        ]);
        $withPreview = Link::factory()->create(['user_id' => $user->id]);
        $withPreview->preview()->create([
            'favicon_url' => 'https://example.com/favicon.ico',
            'fetched_at' => now(),
        ]);
        $withoutPreview = Link::factory()->create(['user_id' => $user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $withPreview->id, 'position' => 0,
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $withoutPreview->id, 'position' => 1,
        ]);

        $items = $this->getJson('/api/public/bio/comfavicon')->json('data.items');

        $this->assertSame('https://example.com/favicon.ico', $items[0]['favicon_url']);
        $this->assertNull($items[1]['favicon_url']);
    }

    /**
     * Cada item público expõe o domínio de DESTINO (original_url, sem www) —
     * a segunda linha do botão diz para onde o clique leva.
     */
    public function test_public_items_expose_destination_host(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create([
            'user_id' => $user->id, 'handle' => 'comdestino', 'is_active' => true,
        ]);
        $link = Link::factory()->create([
            'user_id' => $user->id,
            'original_url' => 'https://www.github.com/bcordeirodev/perfil',
        ]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0,
        ]);

        $items = $this->getJson('/api/public/bio/comdestino')->json('data.items');

        $this->assertSame('github.com', $items[0]['destination_host']);
    }

    /**
     * O payload público expõe `display`/`social_platform` por item — é o
     * que permite o frontend renderizar a linha de ícones sociais acima dos
     * botões (ver proposta de enriquecimento da bio, §2 #1).
     */
    public function test_public_items_expose_display_and_social_platform_for_icon_variant(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create([
            'user_id' => $user->id, 'handle' => 'comicone', 'is_active' => true,
        ]);
        $link = Link::factory()->create(['user_id' => $user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id,
            'link_id' => $link->id,
            'position' => 0,
            'display' => 'icon',
            'social_platform' => 'whatsapp',
        ]);

        $items = $this->getJson('/api/public/bio/comicone')->json('data.items');

        $this->assertSame('icon', $items[0]['display']);
        $this->assertSame('whatsapp', $items[0]['social_platform']);
    }

    /**
     * Item regular (sem variante escolhida) expõe display='item' e
     * social_platform nulo — o default cobre também o payload público.
     */
    public function test_public_items_default_display_to_item(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create([
            'user_id' => $user->id, 'handle' => 'comitemdefault', 'is_active' => true,
        ]);
        $link = Link::factory()->create(['user_id' => $user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0,
        ]);

        $items = $this->getJson('/api/public/bio/comitemdefault')->json('data.items');

        $this->assertSame('item', $items[0]['display']);
        $this->assertNull($items[0]['social_platform']);
    }

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

    /**
     * Invariante de privacidade (revisado 2026-07-29): o HOST do destino é
     * público de propósito (segunda linha do botão; o clique o revela de
     * todo jeito) — mas path/query do original_url e identidade do dono
     * continuam nunca vazando.
     */
    public function test_response_never_leaks_user_id_or_original_url_path(): void
    {
        $user = User::factory()->create();
        $page = BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'joaosilva']);
        $link = Link::factory()->create(['user_id' => $user->id, 'original_url' => 'https://destination.example/secret-path?secret=query']);
        BioPageItem::factory()->create(['bio_page_id' => $page->id, 'link_id' => $link->id, 'position' => 0]);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $raw = $response->getContent();
        $this->assertStringNotContainsString('user_id', $raw);
        $this->assertStringNotContainsString($user->email, $raw);
        $this->assertStringNotContainsString('secret-path', $raw);
        $this->assertStringNotContainsString('secret=query', $raw);
        $this->assertSame('destination.example', $response->json('data.items.0.destination_host'));
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
    }

    public function test_handle_lookup_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        BioPage::factory()->create(['user_id' => $user->id, 'handle' => 'joaosilva']);

        $this->getJson('/api/public/bio/JoaoSilva')->assertOk();
    }
}
