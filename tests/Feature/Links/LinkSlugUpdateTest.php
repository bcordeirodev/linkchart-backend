<?php

namespace Tests\Feature\Links;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers PUT /api/links/{id} slug handling.
 *
 * Regression suite for two bugs found together while investigating a report
 * that the edit form flagged a link's own slug as "already in use":
 *
 * 1. UpdateLinkRequest validated a field named `slug`, but the wire field the
 *    frontend (and CreateLinkRequest) actually use is `custom_slug` — so any
 *    slug edit via PUT /api/links/{id} was a silent no-op, never validated,
 *    never persisted, regardless of collision.
 * 2. There was no uniqueness check on update at all — a genuine collision
 *    would have fallen through to the `links_slug_unique` DB constraint and
 *    surfaced as a raw 500 instead of a clean 422.
 *
 * {@see \Tests\Feature\LinkCrudTest::test_update_modifies_owned_link()} covers
 * the generic (non-slug) update path; this file is scoped to slug-specific
 * behaviour only.
 */
class LinkSlugUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Re-submitting a link's own current slug must succeed — this is the
     * exact scenario from the bug report (edit form pre-filled with the
     * link's own slug, then saved without changing it).
     */
    public function test_update_keeps_own_slug_without_collision(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'meu-slug-atual',
        ]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'custom_slug' => 'meu-slug-atual',
        ], $this->auth());

        $response->assertOk();
        $this->assertDatabaseHas('links', ['id' => $link->id, 'slug' => 'meu-slug-atual']);
    }

    /**
     * Regression guard for bug #1 above: changing to a brand-new, unused slug
     * must actually persist. Before the fix, `custom_slug` was read by
     * nothing on the backend and the slug column never changed.
     */
    public function test_update_can_change_to_a_new_free_slug(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'slug-antigo',
        ]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'custom_slug' => 'slug-novo-livre',
        ], $this->auth());

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'slug-novo-livre');
        $this->assertDatabaseHas('links', ['id' => $link->id, 'slug' => 'slug-novo-livre']);
        $this->assertDatabaseMissing('links', ['id' => $link->id, 'slug' => 'slug-antigo']);
    }

    /**
     * Regression guard for bug #2: trying to steal a DIFFERENT link's slug —
     * whether that link belongs to the same user or another one — must 422
     * with the same "already in use" message CreateLinkRequest uses, and must
     * never touch the database.
     */
    public function test_update_to_another_links_slug_returns_422(): void
    {
        $taken = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'slug-ja-usado',
        ]);
        $mine = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'meu-outro-slug',
        ]);

        $response = $this->putJson("/api/links/{$mine->id}", [
            'custom_slug' => 'slug-ja-usado',
        ], $this->auth());

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.errors.custom_slug.0', 'Este slug já está em uso.');
        $this->assertDatabaseHas('links', ['id' => $mine->id, 'slug' => 'meu-outro-slug']);
        $this->assertDatabaseHas('links', ['id' => $taken->id, 'slug' => 'slug-ja-usado']);
    }

    /**
     * Same collision guard, but against a link owned by a DIFFERENT user —
     * uniqueness is global on `links.slug` (no per-user or per-domain
     * scoping exists), so this must 422 exactly like the same-owner case.
     */
    public function test_update_to_another_users_slug_returns_422(): void
    {
        $other = User::factory()->create();
        $theirs = Link::factory()->create([
            'user_id' => $other->id,
            'slug' => 'slug-de-outro-usuario',
        ]);
        $mine = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'meu-slug-proprio',
        ]);

        $response = $this->putJson("/api/links/{$mine->id}", [
            'custom_slug' => 'slug-de-outro-usuario',
        ], $this->auth());

        $response->assertStatus(422);
        $this->assertDatabaseHas('links', ['id' => $mine->id, 'slug' => 'meu-slug-proprio']);
    }

    /**
     * Omitting `custom_slug` from the payload entirely (e.g. an update that
     * only touches the title) must leave the slug untouched — guards against
     * a regression where the new explicit presence-tracking for slug
     * (see UpdateLinkDTO::fromRequest()) starts treating "absent" as "clear".
     */
    public function test_update_without_slug_field_leaves_slug_untouched(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'nao-mexer-aqui',
        ]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'title' => 'Novo título, slug intocado',
        ], $this->auth());

        $response->assertOk();
        $this->assertDatabaseHas('links', ['id' => $link->id, 'slug' => 'nao-mexer-aqui']);
    }

    /**
     * Mixed-case input is normalized the same way CreateLinkRequest already
     * normalizes it, so the same slug typed differently never looks "free"
     * on a technicality.
     */
    public function test_update_normalizes_slug_case(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'slug-minusculo',
        ]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'custom_slug' => 'Slug-Minusculo',
        ], $this->auth());

        $response->assertOk();
        $this->assertDatabaseHas('links', ['id' => $link->id, 'slug' => 'slug-minusculo']);
    }
}
