<?php

namespace App\Contracts\Services;

use App\DTOs\CreateLinkDTO;
use App\DTOs\CreatePublicLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Models\Link;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business-logic contract for link management.
 *
 * Encapsulates all rules related to creating, reading, updating, and deleting
 * shortened links — including URL validation, slug generation and uniqueness
 * checks, and ownership enforcement. The repository layer handles persistence;
 * this interface handles the "why" and "when".
 *
 * Concrete implementation: {@see \App\Services\Links\LinkService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(LinkServiceInterface::class, LinkService::class)`.
 *
 * Injected by `LinkController` and `PublicLinkController` via constructor.
 */
interface LinkServiceInterface
{
    /**
     * Return all links owned by the currently authenticated user, newest first.
     *
     * Delegates to the repository using the authenticated API-guard user id.
     *
     * @return Collection<int, Link> All links belonging to the authenticated user.
     */
    public function getAllUserLinks(): Collection;

    /**
     * Return a single link owned by the authenticated user, or null if not found.
     *
     * Scopes the lookup by both `$id` and the authenticated user's id to prevent
     * cross-user access.
     *
     * @param  string  $id  Primary key of the link.
     * @return Link|null The link if found and owned, otherwise null.
     */
    public function getUserLink(string $id): ?Link;

    /**
     * Validate, assign a slug, and persist a new authenticated link.
     *
     * Throws `\InvalidArgumentException` if:
     *   - `$linkDTO->isValidUrl()` returns false (URL is invalid).
     *   - A custom slug is provided but already taken.
     * If no slug is provided, a unique one is generated via `generateUniqueSlug()`.
     *
     * @param  CreateLinkDTO  $linkDTO  Validated creation payload.
     * @return Link The created and hydrated link model.
     *
     * @throws \InvalidArgumentException On invalid URL or slug conflict.
     */
    public function createLink(CreateLinkDTO $linkDTO): Link;

    /**
     * Validate and apply partial updates to an authenticated user's link.
     *
     * Throws `\InvalidArgumentException` if:
     *   - `$linkDTO->hasDataToUpdate()` returns false (DTO has no updateable fields).
     *   - `$linkDTO->isValidUrl()` returns false when a URL is included.
     * Returns null if the link is not found or not owned by the authenticated user.
     *
     * @param  string  $id  Primary key of the link.
     * @param  UpdateLinkDTO  $linkDTO  Validated update payload (partial column map).
     * @return Link|null The updated link, or null if not found/owned.
     *
     * @throws \InvalidArgumentException On empty DTO or invalid URL.
     */
    public function updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link;

    /**
     * Delete a link owned by the authenticated user.
     *
     * Returns false if the link does not exist or is not owned by the current user.
     *
     * @param  string  $id  Primary key of the link.
     * @return bool True if deleted, false if not found or not owned.
     */
    public function deleteLink(string $id): bool;

    /**
     * Validate and persist a new public shortened link.
     *
     * When called for a guest request, the DTO carries `user_id = null` and the
     * link is created anonymously. When called for an authenticated user, the DTO
     * carries the real `user_id` and the created link will have the user's active
     * custom subdomain applied to `short_domain` if one exists.
     *
     * Rate-limited at the route level (`public-shorten`, 10/min per IP).
     *
     * @param  CreatePublicLinkDTO  $linkDTO  Validated creation payload.
     * @return Link The created and hydrated link model.
     *
     * @throws \InvalidArgumentException On invalid URL, missing required data, or slug conflict.
     */
    public function createPublicLink(CreatePublicLinkDTO $linkDTO): Link;

    /**
     * Generate a random unique slug of the given length.
     *
     * Loops with `Str::random($length)` until a slug is found that does not
     * already exist in the `links` table. In practice, collision probability
     * is negligible for the default 6-character slug space.
     *
     * @param  int  $length  Number of characters in the slug (default: 6).
     * @return string A slug that does not yet exist in `links.slug`.
     */
    public function generateUniqueSlug(int $length = 6): string;

    /**
     * Return a paginated, filtered, and sorted list of the authenticated user's links.
     *
     * Opt-in counterpart to {@see self::getAllUserLinks()}, used by
     * `GET /api/links` only when a `page` query parameter is present — the
     * unparametrized call keeps returning the full unpaginated list for
     * blue/green compatibility. Scopes the query by the authenticated
     * API-guard user id, mirroring {@see self::getUserLink()}.
     *
     * @param  array{page: int, per_page: int, q?: string|null, status?: string|null, sort?: string|null, order?: string|null}  $filters  Validated query filters.
     * @return LengthAwarePaginator<int, Link> Paginated links belonging to the authenticated user.
     */
    public function searchUserLinks(array $filters): LengthAwarePaginator;
}
