<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Services\Links\LinkPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/public/links/suggest-slug.
 *
 * LinkPreviewService is mocked per-test to control the og:title without making
 * real outbound HTTP requests.
 */
class SuggestSlugTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a LinkPreviewService mock that returns the given og:title for any URL.
     */
    private function fakePreviewTitle(?string $title): void
    {
        $this->mock(LinkPreviewService::class, function ($mock) use ($title) {
            $mock->shouldReceive('fetchPreview')->andReturn([
                'favicon_url' => null,
                'og_title' => $title,
                'og_image_url' => null,
                'og_description' => null,
            ]);
        });
    }

    public function test_free_base_from_title_is_returned_as_is(): void
    {
        $this->fakePreviewTitle('Cat - Wikipedia');

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://en.wikipedia.org/wiki/Cat'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'cat-wikipedia');
    }

    public function test_taken_base_gets_short_random_token_not_a_counter(): void
    {
        $this->fakePreviewTitle('Cat - Wikipedia');
        Link::factory()->create(['slug' => 'cat-wikipedia']);

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://en.wikipedia.org/wiki/Cat'));

        $response->assertStatus(200);
        $slug = $response->json('data.slug');

        $this->assertMatchesRegularExpression('/^cat-wikipedia-[a-z0-9]{4}$/', $slug);
        $this->assertStringNotContainsString('cat-wikipedia-2', $slug);
        $this->assertFalse(Link::where('slug', $slug)->exists());
    }

    public function test_falls_back_to_url_path_when_no_title(): void
    {
        $this->fakePreviewTitle(null);

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://example.com/my-cool-page'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'my-cool-page');
    }

    public function test_falls_back_to_host_when_no_title_and_no_path(): void
    {
        $this->fakePreviewTitle(null);

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://www.wikipedia.org'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'wikipedia');
    }

    public function test_reserved_derivation_is_skipped(): void
    {
        // Both title and path normalize to the reserved word "admin"; the host
        // label "example" should be used instead.
        $this->fakePreviewTitle('Admin');

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://example.com/admin'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'example');
    }

    public function test_returns_random_slug_when_nothing_usable(): void
    {
        // No title, no meaningful path, host label too short to be a valid slug.
        $this->fakePreviewTitle(null);

        $response = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://a.bc'));

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $response->json('data.slug'));
    }

    public function test_suggested_slug_is_always_available(): void
    {
        $this->fakePreviewTitle('My Page');
        Link::factory()->create(['slug' => 'my-page']);

        $slug = $this->getJson('/api/public/links/suggest-slug?url='.urlencode('https://example.com/my-page'))
            ->json('data.slug');

        $this->assertFalse(Link::where('slug', $slug)->exists());
    }

    public function test_missing_url_returns_422(): void
    {
        $this->getJson('/api/public/links/suggest-slug')->assertStatus(422);
    }
}
