<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the backend-derived bio handle (subdomain-first pivot).
 *
 * The editor no longer renders a handle input and never sends `handle` in
 * the PUT payload: on CREATE the service derives it from the associated
 * subdomain's label (suffixing `-1`, `-2`, ... on collision/reserved), and
 * on UPDATE an absent `handle` keeps the current one. An explicit `handle`
 * remains accepted and fully validated for API clients.
 */
class BioPageHandleDerivationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    /**
     * Authorization header for the panel JWT guard.
     *
     * @return array{Authorization: string}
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Creates an active subdomain owned by the test user.
     */
    private function makeSubdomain(string $label): UserSubdomain
    {
        return UserSubdomain::factory()->create([
            'user_id' => $this->user->id,
            'subdomain' => $label,
            'status' => 'active',
        ]);
    }

    /** Create without handle derives it from the subdomain label. */
    public function test_create_without_handle_derives_from_subdomain_label(): void
    {
        $sub = $this->makeSubdomain('acme');

        $response = $this->putJson('/api/bio', [
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame('acme', $response->json('data.handle'));
    }

    /** Collision with another user's handle gets an incremental suffix. */
    public function test_derived_handle_collision_gets_suffix(): void
    {
        $other = User::factory()->create();
        $otherSub = UserSubdomain::factory()->create(['user_id' => $other->id, 'subdomain' => 'outra']);
        BioPage::factory()->create([
            'user_id' => $other->id,
            'handle' => 'acme',
            'subdomain_id' => $otherSub->id,
        ]);
        $sub = $this->makeSubdomain('acme');

        $response = $this->putJson('/api/bio', [
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame('acme-1', $response->json('data.handle'));
    }

    /** A subdomain label on the bio reserved-handles list is suffixed too. */
    public function test_derived_handle_respects_reserved_list(): void
    {
        $reserved = config('bio.reserved_handles')[0];
        $sub = $this->makeSubdomain($reserved);

        $response = $this->putJson('/api/bio', [
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame("{$reserved}-1", $response->json('data.handle'));
    }

    /** Update without handle keeps the current one (never re-derives). */
    public function test_update_without_handle_keeps_current(): void
    {
        $sub = $this->makeSubdomain('acme');
        $this->putJson('/api/bio', [
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        $outro = $this->makeSubdomain('novo-endereco');
        $response = $this->putJson('/api/bio', [
            'title' => 'Título novo',
            'subdomain_id' => $outro->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame('acme', $response->json('data.handle'));
    }

    /** Explicit handle keeps working and being validated for API clients. */
    public function test_explicit_handle_still_accepted_and_validated(): void
    {
        $sub = $this->makeSubdomain('acme');

        $this->putJson('/api/bio', [
            'handle' => 'meu-handle-custom',
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.handle', 'meu-handle-custom');

        BioPage::where('user_id', $this->user->id)->delete();

        $this->putJson('/api/bio', [
            'handle' => 'A!',
            'title' => 'Minha bio',
            'subdomain_id' => $sub->id,
        ], $this->auth())->assertStatus(422);
    }
}
