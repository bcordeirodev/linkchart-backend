<?php

namespace App\Contracts\Repositories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence contract for the `tags` table.
 *
 * Every method requires an explicit `$userId` argument and scopes its query
 * accordingly — there is no unscoped "get all tags" or "find by id" method,
 * because tags are always a strictly per-user resource. This interface
 * contains no business logic (cap enforcement, duplicate-name rejection
 * messaging); that lives in the service layer.
 *
 * Concrete implementation: {@see \App\Repositories\TagRepository}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(TagRepositoryInterface::class, TagRepository::class)`.
 */
interface TagRepositoryInterface
{
    /**
     * Return all tags owned by the given user, newest first.
     *
     * @param  int  $userId  Owning user id.
     * @return Collection<int, Tag> All tags belonging to the given user.
     */
    public function getAllByUser(int $userId): Collection;

    /**
     * Return a single tag that belongs to the given user, or null if not found.
     *
     * @param  int|string  $id  Primary key of the tag.
     * @param  int  $userId  ID of the user who must own the tag.
     * @return Tag|null The matching tag, or null if not found or not owned.
     */
    public function findByIdAndUser(int|string $id, int $userId): ?Tag;

    /**
     * Count how many tags the given user currently owns.
     *
     * Used by the service layer to enforce the per-user cap
     * ({@see \App\Models\Tag::MAX_TAGS_PER_USER}) before creating a new tag.
     *
     * @param  int  $userId  Owning user id.
     * @return int Number of tags owned by the user.
     */
    public function countByUser(int $userId): int;

    /**
     * Persist a new tag record and return the created Eloquent model.
     *
     * The caller is responsible for validating `$data` (including uniqueness
     * of `name` within the user's tags and the per-user cap).
     *
     * @param  array<string, mixed>  $data  Column map (must include user_id, name, color).
     * @return Tag The freshly created and hydrated model.
     */
    public function create(array $data): Tag;

    /**
     * Update an existing tag owned by the given user and return the refreshed model.
     *
     * Ownership is verified before the update. Returns null if the tag does
     * not exist or is not owned by `$userId`.
     *
     * @param  int|string  $id  Primary key of the tag.
     * @param  array<string, mixed>  $data  Partial column map to update.
     * @param  int  $userId  ID of the user who must own the tag.
     * @return Tag|null The updated and refreshed model, or null.
     */
    public function update(int|string $id, array $data, int $userId): ?Tag;

    /**
     * Delete a tag owned by the given user and return whether the deletion succeeded.
     *
     * Ownership is verified before the delete. The `link_tag` pivot rows for
     * this tag cascade-delete at the database level (see the `link_tag`
     * migration's `cascadeOnDelete()` foreign key).
     *
     * @param  int|string  $id  Primary key of the tag.
     * @param  int  $userId  ID of the user who must own the tag.
     * @return bool True if deleted, false if not found or not owned.
     */
    public function delete(int|string $id, int $userId): bool;

    /**
     * Return true if the given user already has a tag with the given name.
     *
     * Optionally excludes a tag id so an update can keep a tag's own current name.
     *
     * @param  int  $userId  Owning user id.
     * @param  string  $name  Candidate tag name.
     * @param  int|string|null  $excludeId  Tag id to exclude from the uniqueness check (used by update).
     * @return bool True if a conflicting tag name already exists for this user.
     */
    public function nameExists(int $userId, string $name, int|string|null $excludeId = null): bool;
}
