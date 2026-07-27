<?php

namespace App\Repositories;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Models\Link;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eloquent persistence layer for the `links` table.
 *
 * Implements {@see LinkRepositoryInterface} and contains no business logic — all
 * validation, slug generation, and cache invalidation live in the service layer
 * ({@see \App\Services\Links\LinkService}). Methods map 1-to-1 to CRUD operations on
 * the {@see \App\Models\Link} Eloquent model and return typed results that callers can
 * rely on without inspecting raw query output.
 *
 * Relevant indexes on the `links` table (created in
 * `2025_09_14_140100_add_performance_indexes_simple.php`):
 * - `idx_links_user_active` on `(user_id, is_active, created_at)` — supports
 *   {@see self::getAllByUser()} and {@see self::findByIdAndUser()}.
 * - `idx_links_expiration` on `(expires_at, is_active)` — used by scheduler queries
 *   outside this repository.
 * The `slug` column carries a unique index from the original
 * `2025_04_20_032909_create_links_table.php` migration — supports
 * {@see self::findPublicActiveBySlug()} and {@see self::slugExists()}.
 */
class LinkRepository implements LinkRepositoryInterface
{
    /**
     * Return all links owned by the currently authenticated API-guard user, newest first.
     *
     * Relies on `idx_links_user_active` (`user_id`, `is_active`, `created_at`) added in
     * `2025_09_14_140100_add_performance_indexes_simple.php`. Modifying the `ORDER BY`
     * or adding a filter on a different column may bypass that index.
     *
     * Eager-loads the `tags` relation so LinkResource can include the `tags` array
     * without an N+1 query per link.
     *
     * @return Collection<int, Link> All links belonging to the authenticated user.
     */
    public function getAllByUser(): Collection
    {
        return Link::with('tags')
            ->where('user_id', auth()->guard('api')->id())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Return a single link that belongs to the given user, or null if not found.
     *
     * Scoping by `user_id` prevents cross-user data access; callers must not skip
     * the `$userId` argument to avoid information disclosure. Uses the
     * `idx_links_user_active` index (`user_id`, `is_active`, `created_at`) from
     * `2025_09_14_140100_add_performance_indexes_simple.php`.
     *
     * Eager-loads the `tags` relation so LinkResource can include the `tags` array.
     *
     * @param  string  $id  Primary key of the link (UUID string).
     * @param  int  $userId  ID of the user who must own the link.
     * @return Link|null The matching link, or null if not found or not owned.
     */
    public function findByIdAndUser(string $id, int $userId): ?Link
    {
        return Link::with('tags')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findPublicActiveBySlug(string $slug): ?Link
    {
        return Link::where('slug', $slug)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('starts_in')->orWhere('starts_in', '<=', now());
            })
            ->first();
    }

    /**
     * Persist a new link record and return the created Eloquent model.
     *
     * Delegates to `Link::create()` which fires the Eloquent `creating` / `created`
     * model events (including cache invalidation observers on `saved`). The caller is
     * responsible for ensuring `$data` is already validated and contains a unique `slug`.
     * Typically called by {@see \App\Services\Links\LinkService::createLink()} after
     * slug generation and DTO serialization via {@see \App\DTOs\CreateLinkDTO::toArray()}.
     *
     * The refresh() after create is load-bearing: `clicks` is NOT fillable
     * (denormalised counter), so the in-memory model returned by Link::create()
     * has no `clicks` attribute — and because the model also defines a
     * `clicks()` relationship, reading `$link->clicks` on the unhydrated
     * instance would return an Eloquent Collection instead of the int column
     * (see the name-collision note on the Link model). Refreshing hydrates the
     * DB defaults (clicks = 0, timestamps) so downstream consumers such as
     * PublicLinkResource read the counter correctly.
     *
     * @param  array<string, mixed>  $data  Column map for the `links` table (must include `original_url` and `slug`).
     * @return Link The freshly created and hydrated model instance.
     */
    public function create(array $data): Link
    {
        return tap(Link::create($data))->refresh();
    }

    /**
     * Update an existing link owned by the given user and return the refreshed model.
     *
     * First calls {@see self::findByIdAndUser()} to enforce ownership before issuing
     * the UPDATE query — this prevents cross-user mutations. After `$link->update($data)`,
     * `$link->fresh()` is called so the returned instance always reflects the current
     * database state (including any timestamp or default changes). Returns null when the
     * link does not exist or is not owned by `$userId`.
     *
     * Model events (`updating` / `updated`) fire normally, triggering cache invalidation.
     *
     * @param  string  $id  Primary key of the link (UUID string).
     * @param  array<string, mixed>  $data  Partial column map; typically from {@see \App\DTOs\UpdateLinkDTO::toArray()}.
     * @param  int  $userId  ID of the user who must own the link.
     * @return Link|null The updated and refreshed model, or null if not found/owned.
     */
    public function update(string $id, array $data, int $userId): ?Link
    {
        $link = $this->findByIdAndUser($id, $userId);

        if ($link) {
            $link->update($data);

            return $link->fresh();
        }

        return null;
    }

    /**
     * Delete a link owned by the given user and return whether the deletion succeeded.
     *
     * First calls {@see self::findByIdAndUser()} to enforce ownership before issuing
     * the DELETE query. Model events (`deleting` / `deleted`) fire normally, which
     * triggers the cache invalidation observer on the `Link` model. Returns false when
     * the link does not exist or is not owned by `$userId`.
     *
     * @param  string  $id  Primary key of the link (UUID string).
     * @param  int  $userId  ID of the user who must own the link.
     * @return bool True if deleted, false if the link was not found or not owned.
     */
    public function delete(string $id, int $userId): bool
    {
        $link = $this->findByIdAndUser($id, $userId);

        if ($link) {
            return $link->delete();
        }

        return false;
    }

    /**
     * Check whether a given slug is already taken by any link (active or inactive).
     *
     * Uses the unique index on `slug` (from `2025_04_20_032909_create_links_table.php`)
     * and produces an `EXISTS` subquery, which avoids loading a model instance. Called
     * by the service layer during slug generation to prevent collisions before insert.
     *
     * @param  string  $slug  The candidate slug to check.
     * @return bool True if the slug is already in use.
     */
    public function slugExists(string $slug): bool
    {
        return Link::where('slug', $slug)->exists();
    }

    /**
     * {@inheritDoc}
     *
     * Eager-loads the `tags` relation for parity with {@see self::getAllByUser()}
     * so `LinkResource` serialises an identical per-item shape regardless of
     * whether `GET /api/links` took the legacy or the paginated branch.
     */
    public function searchByUser(int $userId, array $filters): LengthAwarePaginator
    {
        $query = Link::with('tags')->where('user_id', $userId);

        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $needle = mb_strtolower($q);
            $query->where(function ($sub) use ($needle) {
                $sub->whereRaw('LOWER(title) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(original_url) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$needle}%"]);
            });
        }

        match ($filters['status'] ?? null) {
            'active' => $query->where('is_active', true)
                ->where(fn ($s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            'inactive' => $query->where('is_active', false),
            'expired' => $query->where('expires_at', '<=', now()),
            default => null,
        };

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'clicks', 'title'], true)
            ? $filters['sort']
            : 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)
            ->paginate(perPage: $filters['per_page'], page: $filters['page']);
    }
}
