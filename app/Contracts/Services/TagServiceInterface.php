<?php

namespace App\Contracts\Services;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business-logic contract for tag management.
 *
 * Tags are a private per-user resource: every method is implicitly scoped to
 * the authenticated user (resolved via `auth()->guard('api')->id()` inside
 * the concrete implementation). A tag id belonging to another user always
 * resolves to null/false, exactly like a non-existent id — never a 403 — so
 * callers cannot use this contract to confirm whether a given tag id exists
 * for another user.
 *
 * Concrete implementation: {@see \App\Services\Links\TagService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(TagServiceInterface::class, TagService::class)`.
 *
 * Injected by {@see \App\Http\Controllers\Links\TagController}.
 */
interface TagServiceInterface
{
    /**
     * Return all tags owned by the authenticated user, newest first.
     *
     * @return Collection<int, Tag> All tags belonging to the authenticated user.
     */
    public function getAllUserTags(): Collection;

    /**
     * Return a single tag owned by the authenticated user, or null if not found/owned.
     *
     * @param  string  $id  Primary key of the tag.
     * @return Tag|null The tag if found and owned, otherwise null.
     */
    public function getUserTag(string $id): ?Tag;

    /**
     * Validate and persist a new tag for the authenticated user.
     *
     * Throws `\InvalidArgumentException` if:
     *   - The user has already reached {@see \App\Models\Tag::MAX_TAGS_PER_USER}.
     *   - The user already has a tag with the same `name`.
     *
     * @param  array{name: string, color: string}  $data  Validated tag attributes.
     * @return Tag The created and hydrated tag model.
     *
     * @throws \InvalidArgumentException When the per-user cap is reached or the name is a duplicate.
     */
    public function createTag(array $data): Tag;

    /**
     * Validate and apply a partial update to a tag owned by the authenticated user.
     *
     * Returns null when the tag is not found or not owned. Throws
     * `\InvalidArgumentException` when a new `name` collides with another of
     * the user's tags.
     *
     * @param  string  $id  Primary key of the tag.
     * @param  array{name?: string, color?: string}  $data  Fields to update.
     * @return Tag|null The updated tag, or null if not found/owned.
     *
     * @throws \InvalidArgumentException When the new name collides with another of the user's tags.
     */
    public function updateTag(string $id, array $data): ?Tag;

    /**
     * Delete a tag owned by the authenticated user.
     *
     * Detaching from any linked links is handled automatically by the
     * `link_tag` pivot's `cascadeOnDelete()` foreign key.
     *
     * @param  string  $id  Primary key of the tag.
     * @return bool True if deleted, false if not found or not owned.
     */
    public function deleteTag(string $id): bool;
}
