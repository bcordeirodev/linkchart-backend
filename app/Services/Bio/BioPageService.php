<?php

namespace App\Services\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Logging\AppLogger;
use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use App\Models\UserSubdomain;

/**
 * Business-logic layer for the "link-in-bio" module.
 *
 * Implements {@see BioPageServiceInterface}. Persists via Eloquent directly
 * (no repository layer — the module is small enough that BioPage/BioPageItem
 * queries stay simple, matching the precedent set by TagService).
 */
class BioPageService implements BioPageServiceInterface
{
    /**
     * Maximum number of buttons a single bio page may have.
     */
    public const MAX_ITEMS_PER_PAGE = 20;

    /**
     * {@inheritDoc}
     */
    public function getPublicByHandle(string $handle): ?array
    {
        $page = BioPage::where('handle', strtolower($handle))->where('is_active', true)->first();
        if (! $page) {
            return null;
        }

        $items = $page->items()->where('is_active', true)->with('link')->get();

        return [
            'handle' => $page->handle,
            'title' => $page->title,
            'bio' => $page->bio,
            'theme' => $page->theme,
            'url' => $this->computeUrl($page),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'label' => $item->label,
                'url' => $item->link->getShortedUrl(),
            ])->values()->all(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getForUser(int $userId): ?array
    {
        $page = BioPage::where('user_id', $userId)->first();

        return $page ? $this->formatManagement($page) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $userId, array $data): array
    {
        $handle = strtolower($data['handle']);

        $page = BioPage::where('user_id', $userId)->first();
        $isNew = $page === null;
        $handleChanged = ! $isNew && $page->handle !== $handle;

        if ($isNew) {
            $page = new BioPage(['user_id' => $userId]);
        }

        $page->fill([
            'handle' => $handle,
            'title' => $data['title'],
            'bio' => $data['bio'] ?? null,
            'theme' => $data['theme'] ?? ($page->theme ?? 'dark'),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($page->is_active ?? true),
        ]);

        // subdomain_id is handled outside fill()/$fillable on purpose — it
        // goes through ownership/active-status validation, never blind mass
        // assignment. Absent from $data: leave the current association (if
        // any) untouched. Present as null: explicitly detach. Present as an
        // int: must be an ACTIVE subdomain owned by $userId, mirroring
        // LinkService::resolveShortDomain()'s check for links.
        if (array_key_exists('subdomain_id', $data)) {
            if ($data['subdomain_id'] === null) {
                $page->subdomain_id = null;
            } else {
                $sub = UserSubdomain::where('id', $data['subdomain_id'])
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->first();

                if ($sub === null) {
                    throw new \InvalidArgumentException('Subdomínio inválido, inativo ou não pertence ao usuário.');
                }

                $page->subdomain_id = $sub->id;
            }
        }

        $page->save();

        if ($isNew) {
            AppLogger::event('app', 'info', 'bio.page_created', [
                'user_id' => $userId,
                'bio_page_id' => $page->id,
                'handle' => $handle,
            ]);
        } elseif ($handleChanged) {
            AppLogger::event('app', 'info', 'bio.handle_changed', [
                'user_id' => $userId,
                'bio_page_id' => $page->id,
                'handle' => $handle,
            ]);
        }

        return $this->formatManagement($page->fresh());
    }

    /**
     * {@inheritDoc}
     */
    public function isHandleAvailable(int $userId, string $handle): bool
    {
        $handle = strtolower($handle);

        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $handle)) {
            return false;
        }

        if (in_array($handle, config('bio.reserved_handles', []), true)) {
            return false;
        }

        return ! BioPage::where('handle', $handle)->where('user_id', '!=', $userId)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function addItem(int $userId, array $data): array
    {
        $page = BioPage::where('user_id', $userId)->first();
        if (! $page) {
            throw new \InvalidArgumentException('Página bio ainda não foi criada.');
        }

        $link = Link::where('id', $data['link_id'])->where('user_id', $userId)->first();
        if (! $link) {
            throw new \InvalidArgumentException('Link inválido ou não pertence ao usuário.');
        }

        $itemCount = $page->items()->count();
        if ($itemCount >= self::MAX_ITEMS_PER_PAGE) {
            throw new \InvalidArgumentException('Limite de '.self::MAX_ITEMS_PER_PAGE.' itens por página atingido.');
        }

        $label = $data['label'] ?? ($link->title ?: $link->slug);
        $maxPosition = $page->items()->max('position');
        $nextPosition = $maxPosition === null ? 0 : $maxPosition + 1;

        $item = BioPageItem::create([
            'bio_page_id' => $page->id,
            'link_id' => $link->id,
            'label' => $label,
            'position' => $nextPosition,
            'is_active' => true,
        ]);

        return $this->formatItem($item->fresh('link'));
    }

    /**
     * {@inheritDoc}
     */
    public function updateItem(int $userId, int $itemId, array $data): ?array
    {
        $item = $this->findOwnedItem($userId, $itemId);
        if (! $item) {
            return null;
        }

        $item->fill(array_intersect_key($data, array_flip(['label', 'is_active'])));
        $item->save();

        return $this->formatItem($item->fresh('link'));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteItem(int $userId, int $itemId): bool
    {
        $item = $this->findOwnedItem($userId, $itemId);
        if (! $item) {
            return false;
        }

        return (bool) $item->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function reorderItems(int $userId, array $ids): array
    {
        $page = BioPage::where('user_id', $userId)->first();
        if (! $page) {
            throw new \InvalidArgumentException('Página bio ainda não foi criada.');
        }

        $currentIds = $page->items()->pluck('id')->sort()->values()->all();
        $requestedIds = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($currentIds !== $requestedIds) {
            throw new \InvalidArgumentException('A lista de ids não corresponde exatamente aos itens da página.');
        }

        foreach (array_values($ids) as $position => $id) {
            BioPageItem::where('id', $id)->update(['position' => $position]);
        }

        return $this->formatManagement($page->fresh());
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveHandleBySubdomainId(int $subdomainId): ?string
    {
        return BioPage::where('subdomain_id', $subdomainId)->where('is_active', true)->value('handle');
    }

    /**
     * Find a bio page item by id, scoped to items whose bio page belongs to $userId.
     *
     * Returns null both when the item does not exist and when it belongs to
     * another user's page — the two cases are indistinguishable to the
     * caller by design (never confirms whether a foreign id exists).
     */
    private function findOwnedItem(int $userId, int $itemId): ?BioPageItem
    {
        return BioPageItem::where('id', $itemId)
            ->whereHas('bioPage', fn ($q) => $q->where('user_id', $userId))
            ->first();
    }

    /**
     * Compute the shareable URL for a bio page.
     *
     * Data relationship only (Option A of the bio<->subdomain integration):
     * this does NOT make the subdomain root actually serve the bio page —
     * that is a separate, explicitly-scoped follow-up. When an associated
     * subdomain exists, returns its root address
     * (`{scheme}://{subdomain}.{app.domain}`, scheme derived from
     * `config('app.redirect_url')` the same way {@see \App\Models\Link::getShortedUrl()}
     * does). Otherwise falls back to a path-based address (`/@{handle}`) for
     * the frontend to route on its own domain.
     *
     * Note: this reads `subdomain_id` plus a `belongsTo` lookup, not the
     * subdomain's `status` — a subdomain that was soft-released (status =
     * inactive) via `/api/subdomains/{id}` while still linked here would
     * still resolve to its root URL until the bio page itself is updated to
     * clear or replace the association. Only a hard delete of the
     * `user_subdomains` row (which nulls `subdomain_id` via `nullOnDelete()`)
     * is covered automatically. Flagged as a known follow-up, not fixed here
     * to stay within this change's scope.
     */
    private function computeUrl(BioPage $page): string
    {
        if ($page->subdomain_id !== null && $page->subdomain !== null) {
            $redirectUrl = config('app.redirect_url', 'http://localhost:8000');
            $scheme = parse_url($redirectUrl, PHP_URL_SCHEME) ?? 'https';

            return "{$scheme}://{$page->subdomain->subdomain}.".config('app.domain');
        }

        return '/@'.$page->handle;
    }

    /**
     * Build the management-shape array for a single bio page item.
     *
     * @return array{id: int, link_id: int, label: string, position: int, is_active: bool, url: string, clicks: int}
     */
    private function formatItem(BioPageItem $item): array
    {
        return [
            'id' => $item->id,
            'link_id' => $item->link_id,
            'label' => $item->label,
            'position' => $item->position,
            'is_active' => $item->is_active,
            'url' => $item->link->getShortedUrl(),
            'clicks' => $item->link->clicks,
        ];
    }

    /**
     * Build the management-shape array for a bio page (GET /api/bio, PUT /api/bio).
     *
     * Unlike the public shape, this includes `id`, `is_active`, and per-item
     * `link_id`/`position`/`is_active`/`clicks` — everything the editor UI
     * needs, none of which is exposed on the public endpoint.
     *
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, is_active: bool, items: array<int, array<string, mixed>>}
     */
    private function formatManagement(BioPage $page): array
    {
        $items = $page->items()->with('link')->get();

        return [
            'id' => $page->id,
            'handle' => $page->handle,
            'title' => $page->title,
            'bio' => $page->bio,
            'theme' => $page->theme,
            'is_active' => $page->is_active,
            'subdomain_id' => $page->subdomain_id,
            'url' => $this->computeUrl($page),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'link_id' => $item->link_id,
                'label' => $item->label,
                'position' => $item->position,
                'is_active' => $item->is_active,
                'url' => $item->link->getShortedUrl(),
                'clicks' => $item->link->clicks,
            ])->values()->all(),
        ];
    }
}
