<?php

namespace App\Contracts\Services;

/**
 * Business-logic contract for the "link-in-bio" module.
 *
 * A bio page is a private per-user resource (MVP: exactly one per user) —
 * every management method takes an explicit `$userId` rather than resolving
 * it internally, so the calling controller stays the single place that
 * decides which user is authenticated.
 *
 * Concrete implementation: {@see \App\Services\Bio\BioPageService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()}.
 *
 * Injected by {@see \App\Http\Controllers\Bio\BioPageController} (management)
 * and {@see \App\Http\Controllers\Bio\PublicBioController} (public).
 */
interface BioPageServiceInterface
{
    /**
     * Return the public shape of an active bio page by its handle, or null
     * when the handle does not exist or the page is inactive.
     *
     * Only active items are included, ordered by position. Never exposes
     * `user_id`, `subdomain_id`, `link_id`, or `original_url` — see
     * PublicBioController. `url` is the computed shareable address: the
     * associated subdomain's root when one is set
     * (`https://{subdomain}.{app.domain}`), otherwise a path-based fallback
     * (`/@{handle}`) — see BioPageService's `computeUrl()`. `avatar_url` is
     * null when no avatar has been uploaded. Each item also carries
     * `display` ('item'|'icon') and `social_platform` (whitelisted slug or
     * null) so the frontend can render the social icon row above the
     * regular buttons.
     *
     * @return array{handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, url: string, items: array<int, array{id: int, label: string, url: string, display: string, social_platform: ?string}>}|null
     */
    public function getPublicByHandle(string $handle): ?array;

    /**
     * Return the public shape of an active bio page by the subdomain label
     * it's associated with, or null when: the subdomain does not exist, the
     * subdomain is not active, the subdomain has no associated bio page
     * (`bio_pages.subdomain_id`), or that bio page is inactive.
     *
     * Identical response shape to {@see self::getPublicByHandle()} — the
     * frontend renders the bio page directly on the subdomain's own host
     * (e.g. `bruno.linkcharts.com.br`), so it needs to resolve by subdomain
     * label instead of by the bio page's own handle. See PublicBioController.
     *
     * @param  string  $subdomain  Subdomain label (case-insensitive), e.g. "bruno".
     * @return array{handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, url: string, items: array<int, array{id: int, label: string, url: string, display: string, social_platform: ?string}>}|null
     */
    public function getPublicBySubdomain(string $subdomain): ?array;

    /**
     * Return the authenticated user's bio page in the management shape, or
     * null if they haven't created one yet.
     *
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, is_active: bool, subdomain_id: ?int, url: string, items: array<int, array<string, mixed>>}|null
     */
    public function getForUser(int $userId): ?array;

    /**
     * Create or update the authenticated user's bio page.
     *
     * Creates a new row the first time it is called for `$userId`; every
     * subsequent call updates the existing row (upsert — never a second
     * insert, since `bio_pages.user_id` is unique).
     *
     * A subdomain association is now MANDATORY — the subdomain IS the page's
     * identity (decision recorded 2026-07-27). `subdomain_id`'s behaviour
     * based on presence in `$data` (see {@see \App\Http\Requests\Bio\UpsertBioPageRequest}):
     *   - CREATE: must be present as an int; absent or `null` throws.
     *   - UPDATE, present as `null`: always throws — detaching the subdomain
     *     via update is no longer allowed.
     *   - UPDATE, absent: the current association is left untouched — UNLESS
     *     the existing page is legacy (its `subdomain_id` is still null, from
     *     before this rule existed), in which case this also throws, forcing
     *     the migration on the owner's very next save.
     *   - present as an int (create or update): must reference an ACTIVE
     *     `user_subdomains` row owned by `$userId` (mirrors
     *     {@see \App\Services\Links\LinkService::resolveShortDomain()}'s
     *     check), otherwise throws.
     *
     * @param  array{handle: string, title: string, bio?: ?string, theme?: ?string, is_active?: ?bool, subdomain_id?: ?int}  $data  Validated input (see UpsertBioPageRequest).
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, is_active: bool, subdomain_id: ?int, url: string, items: array<int, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException When the subdomain requirement above is
     *                                   violated, or `subdomain_id` is present and
     *                                   not an active subdomain owned by `$userId`.
     */
    public function upsert(int $userId, array $data): array;

    /**
     * Whether `$handle` could be saved by `$userId` right now.
     *
     * False when the format is invalid, the handle is reserved
     * (`config('bio.reserved_handles')`), or it is already taken by a
     * *different* user. The handle currently owned by `$userId` itself
     * always counts as available (re-saving your own unchanged handle must
     * never be reported as taken).
     */
    public function isHandleAvailable(int $userId, string $handle): bool;

    /**
     * Append a new button to `$userId`'s bio page.
     *
     * `label` defaults to the link's `title`, falling back to its `slug`
     * when the link has no title. `position` is always the current end of
     * the list (0-based). Enforces a maximum of
     * {@see \App\Services\Bio\BioPageService::MAX_ITEMS_PER_PAGE} items.
     *
     * `display` defaults to {@see \App\Models\BioPageItem::DISPLAY_ITEM} when
     * absent from `$data`. `link_id` is required regardless of `display` —
     * an icon item is still a link item (tracking preserved). When
     * `display` is {@see \App\Models\BioPageItem::DISPLAY_ICON}, `$data`
     * MUST already carry a whitelisted `social_platform` (enforced by
     * CreateBioPageItemRequest's `required_if`, not re-checked here);
     * otherwise `social_platform` is stored as null regardless of what
     * `$data` contains, preserving the invariant that it is only ever
     * non-null alongside `display = 'icon'`.
     *
     * @param  array{link_id: int, label?: ?string, display?: ?string, social_platform?: ?string}  $data  Validated input (see CreateBioPageItemRequest).
     * @return array{id: int, link_id: int, label: string, position: int, is_active: bool, display: string, social_platform: ?string, url: string, clicks: int}
     *
     * @throws \InvalidArgumentException When the user has no bio page yet, `link_id`
     *                                   does not belong to `$userId`, or the page
     *                                   already has {@see \App\Services\Bio\BioPageService::MAX_ITEMS_PER_PAGE} items.
     */
    public function addItem(int $userId, array $data): array;

    /**
     * Update a button owned (via its bio page) by `$userId`.
     *
     * `display` is editable like every other field here (product decision —
     * see UpdateBioPageItemRequest's docblock for the "simplest coherent
     * rule" reasoning). Whenever the item's EFFECTIVE display (after
     * applying `$data`) is {@see \App\Models\BioPageItem::DISPLAY_ITEM}, this
     * always forces `social_platform` to null server-side — regardless of
     * what `$data['social_platform']` contains — to preserve the invariant
     * that it is only ever non-null alongside `display = 'icon'`. When the
     * effective display is `'icon'` and `$data` does not touch
     * `social_platform`, the item's existing value is left untouched (e.g. a
     * label-only edit of an already-icon item never needs to resend it).
     *
     * @param  array{label?: string, is_active?: bool, display?: string, social_platform?: ?string}  $data  Fields to update.
     * @return array{id: int, link_id: int, label: string, position: int, is_active: bool, display: string, social_platform: ?string, url: string, clicks: int}|null
     *                                                                                                                                                               Null when the item does not exist or its bio page does not belong to `$userId`.
     */
    public function updateItem(int $userId, int $itemId, array $data): ?array;

    /**
     * Delete a button owned (via its bio page) by `$userId`.
     *
     * @return bool True if deleted, false if not found or not owned.
     */
    public function deleteItem(int $userId, int $itemId): bool;

    /**
     * Reorder every item on `$userId`'s bio page to match `$ids`' order.
     *
     * `$ids` must contain exactly the set of item ids currently on the
     * page — no more, no fewer, no foreign ids — otherwise the whole
     * operation is rejected without applying any change.
     *
     * @param  array<int, int>  $ids  Full ordered list of the page's item ids.
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, is_active: bool, subdomain_id: ?int, url: string, items: array<int, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException When the user has no bio page yet, or `$ids`
     *                                   does not exactly match the page's current item ids.
     */
    public function reorderItems(int $userId, array $ids): array;

    /**
     * Return the handle of the ACTIVE bio page associated with a given
     * `user_subdomains` row, or null when that subdomain has no associated
     * bio page, or has one that is inactive.
     *
     * Association is per-subdomain (`bio_pages.subdomain_id`), not "any bio
     * page owned by the subdomain's user" — a user with several subdomains
     * only redirects on the one their bio is actually linked to.
     *
     * Used by the subdomain-root -> bio redirect (Option B of the
     * bio<->subdomain integration; see routes/web.php and
     * {@see \App\Http\Controllers\Bio\PublicBioController::redirectFromSubdomainRoot()}).
     */
    public function findActiveHandleBySubdomainId(int $subdomainId): ?string;

    /**
     * Upload (or replace) the avatar of `$userId`'s bio page.
     *
     * Stores `$file` under `bio-avatars/{random}.{ext}` on the disk
     * configured by `config('bio.avatar_disk')` (a non-enumerable random
     * filename — never derived from `$userId` or the page id). When the page
     * already has an avatar, the previous file is deleted from the same disk
     * before the new one is persisted.
     *
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, is_active: bool, subdomain_id: ?int, url: string, items: array<int, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException When `$userId` has no bio page yet.
     */
    public function uploadAvatar(int $userId, \Illuminate\Http\UploadedFile $file, ?\Illuminate\Http\UploadedFile $thumb = null): array;

    /**
     * Remove the avatar of `$userId`'s bio page, deleting the stored file (if
     * any) and clearing `avatar_url`/`avatar_path`.
     *
     * A no-op success (not an error) when the page has no avatar set — only
     * the absence of the bio page itself is an error.
     *
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, is_active: bool, subdomain_id: ?int, url: string, items: array<int, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException When `$userId` has no bio page yet.
     */
    public function removeAvatar(int $userId): array;

    /**
     * Click performance panel for `$userId`'s bio page: total clicks in the
     * period plus a per-item ranking, aggregated from the EXISTING `clicks`
     * table through each item's `link_id` (no page-view tracking, no new
     * tables — product decision 2026-08-04).
     *
     * Scope: ALL items on the page (active and inactive — an owner deciding
     * whether to reactivate a button needs its numbers too), restricted to
     * `$userId`'s own bio page and, defensively, to non-demo links
     * (`links.is_demo = false`) — mirrors {@see \App\Services\Analytics\ReportsAnalyticsService}'s
     * "aggregate my own clicks across my own links" scope, so a demo link a
     * new user might add to their bio never inflates their real numbers.
     * Items are sorted by `clicks` descending (the "ranking").
     *
     * When `$userId` has no bio page yet, or the page has zero items,
     * returns a zeroed/empty payload rather than throwing — this is a
     * read-only report, not a mutation that presupposes the page exists.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  string  $period  One of '7d', '30d', '90d', 'all' (unrecognised values fall back to '30d' — the controller validates the whitelist before this is ever called).
     * @return array{total_clicks: int, items: array<int, array{item_id: int, title: string, display: string, social_platform: ?string, clicks: int}>}
     */
    public function getPerformance(int $userId, string $period): array;
}
