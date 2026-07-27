<?php

namespace App\Services\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Logging\AppLogger;
use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;

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
