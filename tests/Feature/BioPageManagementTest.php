<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the authenticated /api/bio management endpoints
 * (BioPageController): GET /api/bio, PUT /api/bio, GET /api/bio/handle-available.
 *
 * Item CRUD and reorder are covered separately in BioPageItemsTest; the
 * public-facing GET /api/public/bio/{handle} contract is covered in
 * BioPagePublicTest.
 */
class BioPageManagementTest extends TestCase
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

    public function test_show_returns_null_data_when_no_page_created(): void
    {
        $response = $this->getJson('/api/bio', $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_upsert_creates_page_on_first_call(): void
    {
        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva',
            'bio' => 'Desenvolvedor e criador de conteúdo.',
            'theme' => 'dark',
        ], $this->auth());

        $response->assertOk()->assertJsonStructure([
            'data' => ['id', 'handle', 'title', 'bio', 'theme', 'is_active', 'items'],
        ]);

        $this->assertSame('joaosilva', $response->json('data.handle'));
        $this->assertSame([], $response->json('data.items'));

        $this->assertDatabaseHas('bio_pages', [
            'user_id' => $this->user->id,
            'handle' => 'joaosilva',
            'title' => 'João Silva',
        ]);
    }

    public function test_handle_is_normalized_to_lowercase_on_create(): void
    {
        $this->putJson('/api/bio', [
            'handle' => 'Ana',
            'title' => 'Ana',
        ], $this->auth())->assertOk();

        $this->assertDatabaseHas('bio_pages', [
            'user_id' => $this->user->id,
            'handle' => 'ana',
        ]);
    }

    public function test_upsert_updates_existing_page(): void
    {
        $this->putJson('/api/bio', ['handle' => 'joaosilva', 'title' => 'João'], $this->auth())->assertOk();

        $response = $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João Silva Atualizado',
            'bio' => 'Nova bio.',
            'theme' => 'light',
        ], $this->auth());

        $response->assertOk();
        $this->assertSame('João Silva Atualizado', $response->json('data.title'));
        $this->assertSame('light', $response->json('data.theme'));

        // Still only one bio_pages row for this user (upsert, not insert).
        $this->assertDatabaseCount('bio_pages', 1);
    }

    public function test_upsert_rejects_reserved_handle(): void
    {
        $this->putJson('/api/bio', [
            'handle' => 'admin',
            'title' => 'Anything',
        ], $this->auth())->assertStatus(422);

        $this->assertDatabaseMissing('bio_pages', ['handle' => 'admin']);
    }

    public function test_upsert_rejects_invalid_handle_format(): void
    {
        // Too short (2 chars).
        $this->putJson('/api/bio', ['handle' => 'ab', 'title' => 'X'], $this->auth())
            ->assertStatus(422);

        // Uppercase not normalized away by prepareForValidation would still
        // be lowercased, so use a char the regex truly rejects: leading hyphen.
        $this->putJson('/api/bio', ['handle' => '-abcde', 'title' => 'X'], $this->auth())
            ->assertStatus(422);

        // Contains invalid character.
        $this->putJson('/api/bio', ['handle' => 'ab_cde', 'title' => 'X'], $this->auth())
            ->assertStatus(422);
    }

    public function test_upsert_rejects_handle_already_taken_by_another_user(): void
    {
        $other = User::factory()->create();
        \App\Models\BioPage::factory()->create(['user_id' => $other->id, 'handle' => 'takenhandle']);

        $this->putJson('/api/bio', [
            'handle' => 'takenhandle',
            'title' => 'Mine',
        ], $this->auth())->assertStatus(422);
    }

    public function test_upsert_allows_keeping_own_unchanged_handle(): void
    {
        $this->putJson('/api/bio', ['handle' => 'joaosilva', 'title' => 'João'], $this->auth())->assertOk();

        // Re-saving with the same handle (only title changes) must not be
        // treated as a duplicate of itself.
        $this->putJson('/api/bio', [
            'handle' => 'joaosilva',
            'title' => 'João V2',
        ], $this->auth())->assertOk();
    }

    public function test_handle_available_true_for_free_handle(): void
    {
        $response = $this->getJson('/api/bio/handle-available?handle=freehandle', $this->auth());

        $response->assertOk();
        $this->assertTrue($response->json('data.available'));
    }

    public function test_handle_available_false_for_handle_taken_by_another_user(): void
    {
        $other = User::factory()->create();
        \App\Models\BioPage::factory()->create(['user_id' => $other->id, 'handle' => 'takenhandle']);

        $response = $this->getJson('/api/bio/handle-available?handle=takenhandle', $this->auth());

        $response->assertOk();
        $this->assertFalse($response->json('data.available'));
    }

    public function test_handle_available_true_for_own_current_handle(): void
    {
        $this->putJson('/api/bio', ['handle' => 'joaosilva', 'title' => 'João'], $this->auth())->assertOk();

        $response = $this->getJson('/api/bio/handle-available?handle=joaosilva', $this->auth());

        $response->assertOk();
        $this->assertTrue($response->json('data.available'));
    }

    public function test_handle_available_false_for_reserved_word(): void
    {
        $response = $this->getJson('/api/bio/handle-available?handle=admin', $this->auth());

        $response->assertOk();
        $this->assertFalse($response->json('data.available'));
    }
}
