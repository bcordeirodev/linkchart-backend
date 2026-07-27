<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the bio page <-> subdomain relationship: `subdomain_id`
 * on PUT /api/bio, and the computed `url` field on both the management
 * (GET/PUT /api/bio) and public (GET /api/public/bio/{handle}) shapes.
 *
 * Data-relationship only (Option A) — this does NOT make the subdomain root
 * actually serve the bio page; that is an explicit, separately-scoped
 * follow-up. `url` here is purely advisory: what the frontend would show/
 * share, computed from whether an active subdomain is associated.
 */
class BioPageSubdomainTest extends TestCase
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

    public function test_upsert_associates_an_active_own_subdomain(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame($sub->id, $response->json('data.subdomain_id'));
        $this->assertSame(
            $this->expectedSubdomainUrl('acme'),
            $response->json('data.url')
        );

        $this->assertDatabaseHas('bio_pages', [
            'user_id' => $this->user->id,
            'subdomain_id' => $sub->id,
        ]);
    }

    public function test_upsert_rejects_subdomain_owned_by_another_user(): void
    {
        $other = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $other->id]);

        $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'subdomain_id' => $sub->id,
        ], $this->auth())->assertStatus(422);

        $this->assertDatabaseMissing('bio_pages', ['handle' => 'joaosilva']);
    }

    public function test_upsert_rejects_inactive_subdomain(): void
    {
        $sub = UserSubdomain::factory()->inactive()->create(['user_id' => $this->user->id]);

        $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'subdomain_id' => $sub->id,
        ], $this->auth())->assertStatus(422);
    }

    public function test_upsert_with_explicit_null_removes_association(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva', 'title' => 'João', 'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João',
            'subdomain_id' => null,
        ], $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data.subdomain_id'));
        $this->assertSame('/@joaosilva', $response->json('data.url'));
        $this->assertDatabaseHas('bio_pages', ['handle' => 'joaosilva', 'subdomain_id' => null]);
    }

    public function test_upsert_without_subdomain_id_key_keeps_current_association(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva', 'title' => 'João', 'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        // No `subdomain_id` key at all in this payload — must not touch it.
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Atualizado',
        ], $this->auth());

        $response->assertOk();
        $this->assertSame($sub->id, $response->json('data.subdomain_id'));
        $this->assertDatabaseHas('bio_pages', ['handle' => 'joaosilva', 'subdomain_id' => $sub->id]);
    }

    public function test_deleting_the_subdomain_nulls_the_association_and_falls_back_to_path_url(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva', 'title' => 'João', 'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        $sub->delete();

        $response = $this->getJson('/api/bio', $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data.subdomain_id'));
        $this->assertSame('/@joaosilva', $response->json('data.url'));
    }

    public function test_page_without_subdomain_has_path_based_url_by_default(): void
    {
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
        ], $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data.subdomain_id'));
        $this->assertSame('/@joaosilva', $response->json('data.url'));
    }

    public function test_public_endpoint_includes_url_reflecting_the_associated_subdomain(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);
        BioPage::factory()->create([
            'user_id' => $this->user->id,
            'handle' => 'joaosilva',
            'subdomain_id' => $sub->id,
        ]);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $this->assertSame($this->expectedSubdomainUrl('acme'), $response->json('data.url'));
        // Public shape never exposes the raw id, only the computed url.
        $this->assertArrayNotHasKey('subdomain_id', $response->json('data'));
    }

    public function test_public_endpoint_falls_back_to_path_url_without_subdomain(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $this->assertSame('/@joaosilva', $response->json('data.url'));
    }

    /**
     * The expected `url` for a bio page associated with the given subdomain
     * label, mirroring BioPageService::computeUrl() exactly — scheme is
     * derived from config('app.redirect_url'), not hardcoded, matching the
     * same convention already used by Link::getShortedUrl().
     */
    private function expectedSubdomainUrl(string $subdomainLabel): string
    {
        $scheme = parse_url(config('app.redirect_url', 'http://localhost:8000'), PHP_URL_SCHEME) ?? 'https';

        return "{$scheme}://{$subdomainLabel}.".config('app.domain');
    }
}
