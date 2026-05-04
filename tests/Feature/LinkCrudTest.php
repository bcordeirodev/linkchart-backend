<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinkCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user  = User::factory()->create([
            'email_verified'    => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_store_creates_link(): void
    {
        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'title'        => 'Test Link',
        ], $this->auth());

        $response->assertStatus(201);
        $this->assertDatabaseHas('links', [
            'original_url' => 'https://example.com',
            'user_id'      => $this->user->id,
        ]);
    }

    public function test_index_returns_only_user_links(): void
    {
        $other = User::factory()->create();
        Link::factory()->create(['user_id' => $other->id]);
        Link::factory()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/links', $this->auth());

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_show_returns_owned_link(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/links/{$link->id}", $this->auth())
             ->assertOk();
    }

    public function test_show_denies_access_to_other_user_link(): void
    {
        $other = User::factory()->create();
        $link  = Link::factory()->create(['user_id' => $other->id]);

        $this->getJson("/api/links/{$link->id}", $this->auth())
             ->assertStatus(404);
    }

    public function test_update_modifies_owned_link(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Old Title']);

        $this->putJson("/api/links/{$link->id}", ['title' => 'New Title'], $this->auth())
             ->assertOk();

        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'New Title']);
    }

    public function test_destroy_deletes_owned_link(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->deleteJson("/api/links/{$link->id}", [], $this->auth())
             ->assertOk();

        $this->assertDatabaseMissing('links', ['id' => $link->id]);
    }

    public function test_destroy_denies_access_to_other_user_link(): void
    {
        $other = User::factory()->create();
        $link  = Link::factory()->create(['user_id' => $other->id]);

        $this->deleteJson("/api/links/{$link->id}", [], $this->auth())
             ->assertStatus(404);

        $this->assertDatabaseHas('links', ['id' => $link->id]);
    }
}
