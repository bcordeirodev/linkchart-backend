<?php

namespace Tests\Feature\Subdomain;

use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'linkcharts.com.br']);
    }

    public function test_redirect_works_via_subdomain_when_slug_belongs_to_owner(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create([
            'user_id' => $user->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com',
            'is_active' => true,
        ]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $response = $this->get('http://acme.linkcharts.com.br/abc123');

        $response->assertRedirect('https://example.com');
    }

    public function test_redirect_returns_error_when_slug_belongs_to_different_user(): void
    {
        $owner = User::factory()->create();
        Link::factory()->create([
            'user_id' => $owner->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com',
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $otherUser->id, 'subdomain' => 'evil']);

        $response = $this->get('http://evil.linkcharts.com.br/abc123');

        $response->assertStatus(404);
    }

    public function test_redirect_returns_error_for_unregistered_subdomain(): void
    {
        $user = User::factory()->create();
        Link::factory()->create([
            'user_id' => $user->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com',
            'is_active' => true,
        ]);

        $response = $this->get('http://nobody.linkcharts.com.br/abc123');

        $response->assertStatus(404);
    }

    public function test_redirect_on_root_domain_ignores_subdomain_context(): void
    {
        $user = User::factory()->create();
        Link::factory()->create([
            'user_id' => $user->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com',
            'is_active' => true,
        ]);

        // No subdomain — root domain behaves normally
        $response = $this->get('/abc123');

        $response->assertRedirect('https://example.com');
    }
}
