<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for attaching tags to links via the /api/links endpoints.
 *
 * Covers the `tag_ids` optional field accepted by CreateLinkRequest and
 * UpdateLinkRequest: attachment on create, sync on update, silent filtering
 * of tag ids that belong to another user, the 5-tags-per-link cap, and that
 * the `tags` array is present in the LinkResource payload returned by
 * GET /api/links (eager-loaded by LinkRepository).
 */
class LinkTagAttachTest extends TestCase
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

    public function test_store_with_tag_ids_attaches_tags(): void
    {
        $tags = Tag::factory()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'tag_ids' => $tags->pluck('id')->all(),
        ], $this->auth());

        $response->assertStatus(201);
        $linkId = $response->json('data.id');

        $this->assertDatabaseHas('link_tag', ['link_id' => $linkId, 'tag_id' => $tags[0]->id]);
        $this->assertDatabaseHas('link_tag', ['link_id' => $linkId, 'tag_id' => $tags[1]->id]);

        $this->assertCount(2, $response->json('data.tags'));
    }

    public function test_store_without_tag_ids_creates_link_with_empty_tags(): void
    {
        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
        ], $this->auth());

        $response->assertStatus(201);
        $this->assertSame([], $response->json('data.tags'));
    }

    public function test_store_filters_out_foreign_tag_ids(): void
    {
        $other = User::factory()->create();
        $foreignTag = Tag::factory()->create(['user_id' => $other->id]);
        $ownTag = Tag::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'tag_ids' => [$foreignTag->id, $ownTag->id],
        ], $this->auth());

        $response->assertStatus(201);
        $linkId = $response->json('data.id');

        $this->assertDatabaseHas('link_tag', ['link_id' => $linkId, 'tag_id' => $ownTag->id]);
        $this->assertDatabaseMissing('link_tag', ['link_id' => $linkId, 'tag_id' => $foreignTag->id]);
        $this->assertCount(1, $response->json('data.tags'));
    }

    public function test_store_rejects_more_than_five_tag_ids(): void
    {
        $tags = Tag::factory()->count(6)->create(['user_id' => $this->user->id]);

        $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'tag_ids' => $tags->pluck('id')->all(),
        ], $this->auth())->assertStatus(422);
    }

    public function test_update_with_tag_ids_syncs_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $initialTag = Tag::factory()->create(['user_id' => $this->user->id]);
        $link->tags()->attach($initialTag->id);

        $newTag = Tag::factory()->create(['user_id' => $this->user->id]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'tag_ids' => [$newTag->id],
        ], $this->auth());

        $response->assertOk();

        $this->assertDatabaseMissing('link_tag', ['link_id' => $link->id, 'tag_id' => $initialTag->id]);
        $this->assertDatabaseHas('link_tag', ['link_id' => $link->id, 'tag_id' => $newTag->id]);
        $this->assertCount(1, $response->json('data.tags'));
    }

    public function test_update_without_tag_ids_leaves_existing_tags_untouched(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'Old']);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $link->tags()->attach($tag->id);

        $this->putJson("/api/links/{$link->id}", ['title' => 'New'], $this->auth())
            ->assertOk();

        $this->assertDatabaseHas('link_tag', ['link_id' => $link->id, 'tag_id' => $tag->id]);
    }

    public function test_update_filters_out_foreign_tag_ids(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $other = User::factory()->create();
        $foreignTag = Tag::factory()->create(['user_id' => $other->id]);

        $this->putJson("/api/links/{$link->id}", [
            'tag_ids' => [$foreignTag->id],
        ], $this->auth())->assertOk();

        $this->assertDatabaseMissing('link_tag', ['link_id' => $link->id, 'tag_id' => $foreignTag->id]);
    }

    public function test_update_with_only_tag_ids_is_accepted(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $this->putJson("/api/links/{$link->id}", [
            'tag_ids' => [$tag->id],
        ], $this->auth())->assertOk();

        $this->assertDatabaseHas('link_tag', ['link_id' => $link->id, 'tag_id' => $tag->id]);
    }

    public function test_index_includes_tags_array_for_each_link(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $link->tags()->attach($tag->id);

        $response = $this->getJson('/api/links', $this->auth());

        $response->assertOk();
        $data = collect($response->json('data'))->firstWhere('id', $link->id);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['tags']);
        $this->assertSame($tag->id, $data['tags'][0]['id']);
        $this->assertSame($tag->name, $data['tags'][0]['name']);
        $this->assertSame($tag->color, $data['tags'][0]['color']);
    }

    public function test_show_includes_tags_array(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $link->tags()->attach($tag->id);

        $response = $this->getJson("/api/links/{$link->id}", $this->auth());

        $response->assertOk();
        $this->assertCount(1, $response->json('data.tags'));
    }
}
