<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the product rule that every bio page MUST have an
 * associated subdomain — the subdomain IS the page's identity now (decision
 * recorded 2026-07-27). `handle` still exists as an identifier and an
 * alternate `/@handle` URL, but a page can no longer be *created*, nor
 * *knowingly re-saved*, without a subdomain association.
 *
 * Enforced in {@see \App\Services\Bio\BioPageService::upsert()} as a business
 * rule (not FormRequest shape validation) — mirrors how ownership/active-status
 * of `subdomain_id` is already enforced there — and surfaced as a 422 by
 * {@see \App\Http\Controllers\Bio\BioPageController::upsert()}.
 *
 * The `bio_pages.subdomain_id` column stays nullable in the schema (see the
 * `2026_07_27_150000_add_subdomain_id_to_bio_pages_table` migration) —
 * pre-existing (legacy) pages saved before this rule may still have a null
 * `subdomain_id`, but only until their owner's next PUT /api/bio; see
 * {@see self::test_update_of_legacy_page_without_subdomain_id_key_is_rejected()}
 * and {@see self::test_update_of_legacy_page_with_a_valid_subdomain_id_migrates_it()}.
 */
class BioPageSubdomainRequiredTest extends TestCase
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

    public function test_create_without_subdomain_id_key_is_rejected(): void
    {
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
        ], $this->auth());

        $response->assertStatus(422);
        $this->assertStringContainsString('endereço personalizado', $response->json('error.message'));
        $this->assertDatabaseMissing('bio_pages', ['handle' => 'joaosilva']);
    }

    public function test_create_with_explicit_null_subdomain_id_is_rejected(): void
    {
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'subdomain_id' => null,
        ], $this->auth());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bio_pages', ['handle' => 'joaosilva']);
    }

    public function test_create_with_a_valid_subdomain_id_succeeds(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame($sub->id, $response->json('data.subdomain_id'));
        $this->assertDatabaseHas('bio_pages', ['handle' => 'joaosilva', 'subdomain_id' => $sub->id]);
    }

    public function test_update_with_explicit_null_subdomain_id_is_rejected(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva', 'title' => 'João', 'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Atualizado',
            'subdomain_id' => null,
        ], $this->auth());

        $response->assertStatus(422);
        $this->assertStringContainsString('endereço personalizado', $response->json('error.message'));
        // Rejected before persisting — the existing association and title must survive untouched.
        $this->assertDatabaseHas('bio_pages', [
            'handle' => 'joaosilva',
            'subdomain_id' => $sub->id,
            'title' => 'João',
        ]);
    }

    public function test_update_without_subdomain_id_key_keeps_the_current_association(): void
    {
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva', 'title' => 'João', 'subdomain_id' => $sub->id,
        ], $this->auth())->assertOk();

        // No `subdomain_id` key at all — must keep the current association,
        // not demand one again just because this update omits it.
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Atualizado',
        ], $this->auth());

        $response->assertOk();
        $this->assertSame($sub->id, $response->json('data.subdomain_id'));
        $this->assertDatabaseHas('bio_pages', ['handle' => 'joaosilva', 'subdomain_id' => $sub->id]);
    }

    public function test_update_of_legacy_page_without_subdomain_id_key_is_rejected(): void
    {
        // Simulates a page persisted before this rule existed — created
        // directly via the model factory (bypassing the service/validation),
        // never through PUT /api/bio, since that path always enforces the rule.
        BioPage::factory()->create([
            'user_id' => $this->user->id,
            'handle' => 'joaosilva',
            'title' => 'Original',
        ]);

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Atualizado',
        ], $this->auth());

        $response->assertStatus(422);
        $this->assertDatabaseHas('bio_pages', [
            'handle' => 'joaosilva',
            'title' => 'Original',
            'subdomain_id' => null,
        ]);
    }

    public function test_update_of_legacy_page_with_a_valid_subdomain_id_migrates_it(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);
        $sub = UserSubdomain::factory()->create(['user_id' => $this->user->id]);

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Atualizado',
            'subdomain_id' => $sub->id,
        ], $this->auth());

        $response->assertOk();
        $this->assertSame($sub->id, $response->json('data.subdomain_id'));
        $this->assertDatabaseHas('bio_pages', ['handle' => 'joaosilva', 'subdomain_id' => $sub->id]);
    }
}
