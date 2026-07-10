<?php

namespace App\Services\Links;

use App\Contracts\Repositories\TagRepositoryInterface;
use App\Contracts\Services\TagServiceInterface;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business-logic layer for tag CRUD operations.
 *
 * Implements {@see TagServiceInterface} and delegates all persistence to
 * {@see TagRepositoryInterface}. Every read/write is scoped to
 * `auth()->guard('api')->id()`, so a caller can never see or mutate another
 * user's tags through this service — TagController never passes a user id in,
 * it is always resolved here.
 */
class TagService implements TagServiceInterface
{
    protected TagRepositoryInterface $tagRepository;

    public function __construct(TagRepositoryInterface $tagRepository)
    {
        $this->tagRepository = $tagRepository;
    }

    /**
     * {@inheritDoc}
     */
    public function getAllUserTags(): Collection
    {
        return $this->tagRepository->getAllByUser(auth()->guard('api')->id());
    }

    /**
     * {@inheritDoc}
     */
    public function getUserTag(string $id): ?Tag
    {
        return $this->tagRepository->findByIdAndUser($id, auth()->guard('api')->id());
    }

    /**
     * {@inheritDoc}
     */
    public function createTag(array $data): Tag
    {
        $userId = auth()->guard('api')->id();

        if ($this->tagRepository->countByUser($userId) >= Tag::MAX_TAGS_PER_USER) {
            throw new \InvalidArgumentException(
                'Você atingiu o limite máximo de '.Tag::MAX_TAGS_PER_USER.' tags.'
            );
        }

        if ($this->tagRepository->nameExists($userId, $data['name'])) {
            throw new \InvalidArgumentException('Você já possui uma tag com este nome.');
        }

        return $this->tagRepository->create([
            'user_id' => $userId,
            'name' => $data['name'],
            'color' => $data['color'],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function updateTag(string $id, array $data): ?Tag
    {
        $userId = auth()->guard('api')->id();

        if (array_key_exists('name', $data) && $this->tagRepository->nameExists($userId, $data['name'], $id)) {
            throw new \InvalidArgumentException('Você já possui uma tag com este nome.');
        }

        return $this->tagRepository->update($id, $data, $userId);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteTag(string $id): bool
    {
        return $this->tagRepository->delete($id, auth()->guard('api')->id());
    }
}
