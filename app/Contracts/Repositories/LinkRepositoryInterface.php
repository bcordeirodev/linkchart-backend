<?php

namespace App\Contracts\Repositories;

use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence contract for the `links` table.
 *
 * Defines the data-access operations that any link repository must implement,
 * keeping the service layer decoupled from the Eloquent ORM. This interface
 * contains no business logic — slug generation, validation, and cache
 * management are concerns of the service layer.
 *
 * Concrete implementation: {@see \App\Repositories\LinkRepository}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(LinkRepositoryInterface::class, LinkRepository::class)`.
 */
interface LinkRepositoryInterface
{
    /**
     * Return all links owned by the currently authenticated API-guard user, newest first.
     *
     * Relies on the authenticated user resolved via `auth()->guard('api')->id()`.
     * Results are ordered by `created_at DESC`.
     *
     * @return Collection<int, Link> All links belonging to the authenticated user.
     */
    public function getAllByUser(): Collection;

    /**
     * Return a single link that belongs to the given user, or null if not found.
     *
     * Scoping by `$userId` prevents cross-user data access; callers must always
     * supply the authenticated user's id to prevent information disclosure.
     *
     * @param  string  $id  Primary key of the link.
     * @param  int  $userId  ID of the user who must own the link.
     * @return Link|null The matching link, or null if not found or not owned.
     */
    public function findByIdAndUser(string $id, int $userId): ?Link;

    /**
     * Return an active link matching the given slug, or null if absent or inactive.
     *
     * The `slug` column carries a unique index. An additional `is_active = true`
     * filter ensures deactivated links are never served. For the hot redirect path,
     * prefer `Link::findActiveBySlugCached()` which wraps this lookup in a
     * 10-minute cache.
     *
     * @param  string  $slug  The short URL slug to look up.
     * @return Link|null The matching active link, or null.
     */
    public function findBySlug(string $slug): ?Link;

    /**
     * Persist a new link record and return the created Eloquent model.
     *
     * The caller is responsible for validating `$data` and supplying a unique
     * `slug`. Typically called by the service layer after DTO serialization via
     * `CreateLinkDTO::toArray()`. Eloquent `creating` / `created` events fire
     * normally, triggering any registered model observers.
     *
     * @param  array<string, mixed>  $data  Column map for the `links` table (must
     *                                      include at least `original_url` and `slug`).
     * @return Link The freshly created and hydrated model.
     */
    public function create(array $data): Link;

    /**
     * Update an existing link owned by the given user and return the refreshed model.
     *
     * Ownership is verified before the update. Returns null if the link does not
     * exist or is not owned by `$userId`. The returned model is always a fresh
     * instance from the database (via `$link->fresh()`), reflecting any timestamp
     * or default changes applied by the DB.
     *
     * @param  string  $id  Primary key of the link.
     * @param  array<string, mixed>  $data  Partial column map to update; typically
     *                                      from `UpdateLinkDTO::toArray()`.
     * @param  int  $userId  ID of the user who must own the link.
     * @return Link|null The updated and refreshed model, or null.
     */
    public function update(string $id, array $data, int $userId): ?Link;

    /**
     * Delete a link owned by the given user and return whether the deletion succeeded.
     *
     * Ownership is verified before the delete. Model events (`deleting` / `deleted`)
     * fire normally, triggering the Link cache-invalidation observer. Returns false
     * when the link is not found or not owned by `$userId`.
     *
     * @param  string  $id  Primary key of the link.
     * @param  int  $userId  ID of the user who must own the link.
     * @return bool True if deleted, false if not found or not owned.
     */
    public function delete(string $id, int $userId): bool;

    /**
     * Return true if the given slug is already taken by any link (active or inactive).
     *
     * Uses an `EXISTS` subquery to avoid loading a full model. Called by the service
     * layer during slug generation to prevent collisions before insert.
     *
     * @param  string  $slug  The candidate slug to check.
     * @return bool True if the slug is already in use.
     */
    public function slugExists(string $slug): bool;
}
