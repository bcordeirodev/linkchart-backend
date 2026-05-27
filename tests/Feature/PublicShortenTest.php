<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use App\Services\Links\LinkSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /api/public/shorten and GET /api/public/link/{slug}.
 *
 * Safe Browsing is mocked in setUp to avoid real Google API calls.
 */
class PublicShortenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(LinkSafetyService::class, function ($mock) {
            $mock->shouldReceive('checkUrl')
                ->andReturn(['safe' => true, 'threats' => []]);
        });
    }

    public function test_guest_can_create_link_with_auto_generated_slug(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/a-very-long-url',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.original_url', 'https://example.com/a-very-long-url');

        $slug = $response->json('data.slug');
        $this->assertNotNull($slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $slug, 'Auto slug must be 6 lowercase alphanumeric chars');
        $this->assertDatabaseHas('links', ['slug' => $slug]);
    }

    public function test_authenticated_user_link_receives_custom_subdomain(): void
    {
        $user = User::factory()->create();

        UserSubdomain::factory()->create([
            'user_id' => $user->id,
            'subdomain' => 'minha-marca',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/public/shorten', [
                'original_url' => 'https://example.com/page',
            ]);

        $response->assertStatus(201);

        $shortUrl = $response->json('data.short_url');
        $this->assertStringContainsString('minha-marca', $shortUrl);
    }

    public function test_guest_can_create_link_with_custom_slug(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/page',
            'custom_slug' => 'my-test-slug',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'my-test-slug');
        $this->assertDatabaseHas('links', ['slug' => 'my-test-slug']);
    }

    public function test_duplicate_custom_slug_returns_422(): void
    {
        Link::factory()->create(['slug' => 'already-taken']);

        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/other',
            'custom_slug' => 'already-taken',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['custom_slug'], 'error.details.errors');
    }

    public function test_private_ip_url_is_blocked(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'http://192.168.1.1/internal',
        ]);

        $response->assertStatus(422);
    }

    public function test_rate_limit_returns_429_after_threshold(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/public/shorten', [
                'original_url' => "https://example.com/page-{$i}",
            ])->assertStatus(201);
        }

        $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/page-11',
        ])->assertStatus(429);
    }

    public function test_optional_title_is_persisted(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/titled',
            'title' => 'My Test Link',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('links', [
            'slug' => $response->json('data.slug'),
            'title' => 'My Test Link',
        ]);
    }

    public function test_show_by_slug_returns_active_link(): void
    {
        $link = Link::factory()->create([
            'slug' => 'active-link',
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/public/link/{$link->slug}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'active-link');
    }

    public function test_show_by_slug_returns_generic_404_for_inactive_link(): void
    {
        Link::factory()->create([
            'slug' => 'inactive-link',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/public/link/inactive-link');

        $response->assertStatus(404);
        $response->assertJsonMissing(['expirado', 'inativo', 'disponível']);
    }
}
