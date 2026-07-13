<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the /api/tags CRUD family (TagController).
 *
 * Mirrors the structure of {@see \Tests\Feature\LinkCrudTest}: JWT auth via
 * the api guard, RefreshDatabase per test, Queue::fake() in setUp (tag CRUD
 * itself dispatches no jobs, but the shared harness keeps parity with the
 * other feature tests in this suite).
 */
class TagCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    /**
     * Create an authenticated, verified user and mint a JWT for it.
     */
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
     * Bearer-auth header array for the currently logged-in test user.
     *
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_store_creates_tag(): void
    {
        $response = $this->postJson('/api/tags', [
            'name' => 'Marketing',
            'color' => '#3B82F6',
        ], $this->auth());

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name', 'color']]);

        $this->assertDatabaseHas('tags', [
            'name' => 'Marketing',
            'color' => '#3B82F6',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_index_returns_only_user_tags(): void
    {
        $other = User::factory()->create();
        Tag::factory()->create(['user_id' => $other->id]);
        Tag::factory()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/tags', $this->auth());

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_update_modifies_owned_tag(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'Old']);

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'New'], $this->auth())
            ->assertOk();

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'New']);
    }

    public function test_update_denies_access_to_other_user_tag(): void
    {
        $other = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Hacked'], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'Theirs']);
    }

    public function test_destroy_deletes_owned_tag(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $this->deleteJson("/api/tags/{$tag->id}", [], $this->auth())
            ->assertOk();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_destroy_denies_access_to_other_user_tag(): void
    {
        $other = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $other->id]);

        $this->deleteJson("/api/tags/{$tag->id}", [], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_destroy_detaches_tag_from_links(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $link->tags()->attach($tag->id);

        $this->assertDatabaseHas('link_tag', ['link_id' => $link->id, 'tag_id' => $tag->id]);

        $this->deleteJson("/api/tags/{$tag->id}", [], $this->auth())
            ->assertOk();

        $this->assertDatabaseMissing('link_tag', ['link_id' => $link->id, 'tag_id' => $tag->id]);
    }

    public function test_store_rejects_duplicate_name_for_same_user(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'Marketing']);

        $this->postJson('/api/tags', [
            'name' => 'Marketing',
            'color' => '#3B82F6',
        ], $this->auth())->assertStatus(422);
    }

    public function test_store_allows_same_name_for_different_users(): void
    {
        $other = User::factory()->create();
        Tag::factory()->create(['user_id' => $other->id, 'name' => 'Marketing']);

        $this->postJson('/api/tags', [
            'name' => 'Marketing',
            'color' => '#3B82F6',
        ], $this->auth())->assertStatus(201);
    }

    public function test_store_rejects_invalid_color(): void
    {
        $this->postJson('/api/tags', [
            'name' => 'Marketing',
            'color' => 'blue',
        ], $this->auth())->assertStatus(422);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->postJson('/api/tags', [
            'color' => '#3B82F6',
        ], $this->auth())->assertStatus(422);
    }

    public function test_store_rejects_beyond_cap_of_twenty(): void
    {
        Tag::factory()->count(20)->create(['user_id' => $this->user->id]);

        $this->postJson('/api/tags', [
            'name' => 'One More',
            'color' => '#3B82F6',
        ], $this->auth())->assertStatus(422);
    }
}
