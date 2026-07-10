<?php

namespace App\Repositories;

use App\Contracts\Repositories\TagRepositoryInterface;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eloquent persistence layer for the `tags` table.
 *
 * Implements {@see TagRepositoryInterface} and contains no business logic —
 * cap enforcement and duplicate-name rejection messaging live in
 * {@see \App\Services\Links\TagService}. Every method requires an explicit
 * `$userId` argument and scopes its query accordingly; there is no unscoped
 * lookup by design, since tags are a strictly per-user resource.
 */
class TagRepository implements TagRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function getAllByUser(int $userId): Collection
    {
        return Tag::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdAndUser(int|string $id, int $userId): ?Tag
    {
        return Tag::where('id', $id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function countByUser(int $userId): int
    {
        return Tag::where('user_id', $userId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int|string $id, array $data, int $userId): ?Tag
    {
        $tag = $this->findByIdAndUser($id, $userId);

        if ($tag) {
            $tag->update($data);

            return $tag->fresh();
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int|string $id, int $userId): bool
    {
        $tag = $this->findByIdAndUser($id, $userId);

        if ($tag) {
            return (bool) $tag->delete();
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function nameExists(int $userId, string $name, int|string|null $excludeId = null): bool
    {
        return Tag::where('user_id', $userId)
            ->where('name', $name)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
    }
}
