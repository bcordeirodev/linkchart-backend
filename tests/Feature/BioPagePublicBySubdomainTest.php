<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the public, unauthenticated
 * GET /api/public/bio/by-subdomain/{subdomain} endpoint
 * (PublicBioController::showBySubdomain).
 *
 * The frontend will render the bio page directly on the subdomain's own
 * host (e.g. bruno.linkcharts.com.br), so it needs to resolve the bio page
 * by the subdomain label rather than by the bio page's own handle. The
 * response shape must be EXACTLY the same as GET /api/public/bio/{handle}.
 */
class BioPagePublicBySubdomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_same_shape_as_lookup_by_handle(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'bruno']);
        $page = BioPage::factory()->create([
            'user_id' => $user->id,
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'bio' => 'Minha bio.',
            'theme' => 'dark',
            'is_active' => true,
            'subdomain_id' => $sub->id,
        ]);
        $link = Link::factory()->create(['user_id' => $user->id]);
        BioPageItem::factory()->create([
            'bio_page_id' => $page->id, 'link_id' => $link->id, 'label' => 'First', 'position' => 0,
        ]);

        $byHandle = $this->getJson('/api/public/bio/joaosilva');
        $bySubdomain = $this->getJson('/api/public/bio/by-subdomain/bruno');

        $byHandle->assertOk();
        $bySubdomain->assertOk();
        $this->assertSame($byHandle->json('data'), $bySubdomain->json('data'));
    }

    public function test_returns_404_when_subdomain_does_not_exist(): void
    {
        $this->getJson('/api/public/bio/by-subdomain/doesnotexist')->assertStatus(404);
    }

    public function test_returns_404_when_subdomain_is_inactive(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'bruno']);
        BioPage::factory()->create(['user_id' => $user->id, 'subdomain_id' => $sub->id]);

        $this->getJson('/api/public/bio/by-subdomain/bruno')->assertStatus(404);
    }

    public function test_returns_404_when_subdomain_has_no_bio_page(): void
    {
        UserSubdomain::factory()->create(['subdomain' => 'orphan']);

        $this->getJson('/api/public/bio/by-subdomain/orphan')->assertStatus(404);
    }

    public function test_returns_404_when_bio_page_is_inactive(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'bruno']);
        BioPage::factory()->create(['user_id' => $user->id, 'subdomain_id' => $sub->id, 'is_active' => false]);

        $this->getJson('/api/public/bio/by-subdomain/bruno')->assertStatus(404);
    }

    public function test_both_routes_respond_correctly_even_when_handle_equals_the_literal_prefix(): void
    {
        // A bio page whose HANDLE is literally "by-subdomain" is the sharpest
        // possible collision case: /api/public/bio/by-subdomain (one segment)
        // must still resolve through the {handle} route to THIS page, proving
        // the more specific by-subdomain/{subdomain} route (which requires a
        // second segment) does not swallow or otherwise interfere with it.
        $handleUser = User::factory()->create();
        BioPage::factory()->create([
            'user_id' => $handleUser->id,
            'handle' => 'by-subdomain',
            'title' => 'Handle Collision Page',
        ]);

        $subUser = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $subUser->id, 'subdomain' => 'bruno']);
        BioPage::factory()->create([
            'user_id' => $subUser->id,
            'handle' => 'joaosilva',
            'title' => 'Subdomain Page',
            'subdomain_id' => $sub->id,
        ]);

        $byHandle = $this->getJson('/api/public/bio/by-subdomain');
        $byHandle->assertOk();
        $this->assertSame('Handle Collision Page', $byHandle->json('data.title'));

        $bySubdomain = $this->getJson('/api/public/bio/by-subdomain/bruno');
        $bySubdomain->assertOk();
        $this->assertSame('Subdomain Page', $bySubdomain->json('data.title'));
    }

    public function test_rate_limit_returns_429_after_60_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/public/bio/by-subdomain/doesnotexist')->assertStatus(404);
        }

        $this->getJson('/api/public/bio/by-subdomain/doesnotexist')->assertStatus(429);
    }
}
