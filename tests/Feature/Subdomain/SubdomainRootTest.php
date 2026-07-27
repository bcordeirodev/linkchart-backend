<?php

namespace Tests\Feature\Subdomain;

use App\Models\BioPage;
use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Characterizes (and then verifies non-regression of) the root path `/`
 * behavior on subdomain hosts, and covers the new subdomain-root -> bio
 * redirect behavior (Option B of the bio<->subdomain integration).
 *
 * The first block of tests (`test_*_returns_welcome_json`) documents the
 * baseline: BEFORE this feature existed, `GET /` on ANY host — root domain,
 * reserved subdomain, unregistered subdomain, or a real subdomain with no
 * associated active bio page — returned the exact same static welcome JSON
 * (the closure route in routes/web.php). These must keep passing unmodified
 * after the redirect route is added; only the NEW case (an active bio page
 * associated with THIS subdomain via `bio_pages.subdomain_id`, not just any
 * bio owned by the subdomain's user) changes behavior.
 *
 * Slug redirect routes (`/{slug}`, `/r/{slug}`) are untouched by this
 * feature — see SubdomainRedirectTest and RedirectTest for their coverage;
 * this file only adds one sanity check that they still work on a subdomain
 * host that also has this new root route registered.
 */
class SubdomainRootTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'linkcharts.com.br']);
        Cache::flush();
    }

    public function test_root_domain_returns_welcome_json(): void
    {
        $response = $this->get('http://linkcharts.com.br/');

        $response->assertOk()->assertJson([
            'message' => 'Link Charts API is running!',
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }

    public function test_unregistered_subdomain_root_returns_welcome_json(): void
    {
        $response = $this->get('http://nobody.linkcharts.com.br/');

        $response->assertOk()->assertJson(['message' => 'Link Charts API is running!']);
    }

    public function test_reserved_subdomain_root_returns_welcome_json(): void
    {
        // 'www' is in config('app.reserved_subdomains') — ResolveSubdomainContext
        // treats it the same as the root domain, never a real UserSubdomain.
        $response = $this->get('http://www.linkcharts.com.br/');

        $response->assertOk()->assertJson(['message' => 'Link Charts API is running!']);
    }

    public function test_subdomain_without_bio_page_returns_welcome_json(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $response = $this->get('http://acme.linkcharts.com.br/');

        $response->assertOk()->assertJson(['message' => 'Link Charts API is running!']);
    }

    public function test_subdomain_with_bio_associated_to_a_different_subdomain_returns_welcome_json(): void
    {
        $user = User::factory()->create();
        $subA = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $subB = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'other']);
        BioPage::factory()->create([
            'user_id' => $user->id,
            'handle' => 'joaosilva',
            'subdomain_id' => $subB->id,
            'is_active' => true,
        ]);

        // Hitting subA's root must NOT redirect — the bio is linked to subB,
        // not to "acme". Association is per-subdomain, not per-user.
        $response = $this->get('http://acme.linkcharts.com.br/');

        $response->assertOk()->assertJson(['message' => 'Link Charts API is running!']);
    }

    public function test_subdomain_with_inactive_bio_returns_welcome_json(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        BioPage::factory()->create([
            'user_id' => $user->id,
            'handle' => 'joaosilva',
            'subdomain_id' => $sub->id,
            'is_active' => false,
        ]);

        $response = $this->get('http://acme.linkcharts.com.br/');

        $response->assertOk()->assertJson(['message' => 'Link Charts API is running!']);
    }

    public function test_subdomain_with_active_associated_bio_redirects_to_frontend(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        BioPage::factory()->create([
            'user_id' => $user->id,
            'handle' => 'joaosilva',
            'subdomain_id' => $sub->id,
            'is_active' => true,
        ]);

        $response = $this->get('http://acme.linkcharts.com.br/');

        $response->assertStatus(302);
        $response->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/@joaosilva');
    }

    public function test_slug_route_on_subdomain_root_host_is_unaffected(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        Link::factory()->create([
            'user_id' => $user->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com',
            'is_active' => true,
        ]);

        $response = $this->get('http://acme.linkcharts.com.br/abc123');

        $response->assertRedirect('https://example.com');
    }
}
