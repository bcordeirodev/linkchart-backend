<?php

namespace App\Services\Links;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\CreatePublicLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Business-logic layer for link CRUD operations.
 *
 * Implements LinkServiceInterface and delegates all persistence to
 * LinkRepositoryInterface, keeping controllers and the service layer free of
 * raw Eloquent calls.
 *
 * @see \App\Contracts\Services\LinkServiceInterface
 *
 * Side effects: reads/writes to the links table via LinkRepository.
 * No cache, queue, or log calls — those live in the repository and in
 * LinkTrackingService / LinkAuditService.
 */
class LinkService implements LinkServiceInterface
{
    protected LinkRepositoryInterface $linkRepository;

    public function __construct(LinkRepositoryInterface $linkRepository)
    {
        $this->linkRepository = $linkRepository;
    }

    /**
     * Returns all links owned by the currently authenticated API user.
     *
     * Delegates to LinkRepositoryInterface::getAllByUser() which scopes by
     * auth()->guard('api')->id().
     *
     * @return Collection<int, Link>
     */
    public function getAllUserLinks(): Collection
    {
        return $this->linkRepository->getAllByUser();
    }

    /**
     * Returns a specific link belonging to the authenticated user, or null.
     *
     * Delegates to LinkRepositoryInterface::findByIdAndUser().
     *
     * @param  string  $id  Link primary key (stringified int).
     */
    public function getUserLink(string $id): ?Link
    {
        return $this->linkRepository->findByIdAndUser($id, auth()->guard('api')->id());
    }

    /**
     * Creates a new shortened link for the authenticated user.
     *
     * Validates the URL via CreateLinkDTO::isValidUrl(). Generates a unique
     * random slug (6 chars) if none is provided; rejects duplicate custom slugs
     * with InvalidArgumentException. Delegates persistence to
     * LinkRepositoryInterface::create().
     *
     * @param  CreateLinkDTO  $linkDTO  Validated input DTO.
     * @return Link The newly created model (ready to pass to LinkResource).
     *
     * @throws \InvalidArgumentException If URL is invalid or slug is already taken.
     */
    public function createLink(CreateLinkDTO $linkDTO): Link
    {
        // Validação de negócio
        if (! $linkDTO->isValidUrl()) {
            throw new \InvalidArgumentException('URL inválida fornecida.');
        }

        $data = $linkDTO->toArray();

        // Resolve the user's active subdomain and record it on the link.
        // Stored at creation time so the short URL is immutable even if the user
        // later releases their subdomain.
        $sub = \App\Models\UserSubdomain::findByUserCached($linkDTO->user_id);
        if ($sub) {
            $data['short_domain'] = $sub->subdomain . '.' . config('app.domain');
        }

        // Gera slug único se não fornecido
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        return $this->linkRepository->create($data);
    }

    /**
     * Updates an existing link owned by the authenticated user.
     *
     * Validates that the DTO has data to update and that the URL is valid.
     * Delegates to LinkRepositoryInterface::update() which scopes by user_id.
     * Returns null if the link does not exist or does not belong to the user.
     *
     * @param  string  $id  Link primary key (stringified int).
     * @param  UpdateLinkDTO  $linkDTO  Validated input DTO.
     * @return Link|null Updated model, or null if not found.
     *
     * @throws \InvalidArgumentException If no data to update or URL is invalid.
     */
    public function updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link
    {
        // Validação de negócio
        if (! $linkDTO->hasDataToUpdate()) {
            throw new \InvalidArgumentException('Nenhum dado fornecido para atualização.');
        }

        if (! $linkDTO->isValidUrl()) {
            throw new \InvalidArgumentException('URL inválida fornecida.');
        }

        return $this->linkRepository->update($id, $linkDTO->toArray(), auth()->guard('api')->id());
    }

    /**
     * Deletes a link owned by the authenticated user.
     *
     * Delegates to LinkRepositoryInterface::delete() which scopes by user_id.
     *
     * @param  string  $id  Link primary key (stringified int).
     * @return bool True on success, false if not found.
     */
    public function deleteLink(string $id): bool
    {
        return $this->linkRepository->delete($id, auth()->guard('api')->id());
    }

    /**
     * Creates a new public short link, optionally associated with a user.
     *
     * Validates the URL and data completeness via the DTO. Generates a unique
     * random slug if not provided; rejects duplicate custom slugs. The `user_id`
     * from the DTO is preserved — null for guests, or the authenticated user's ID
     * when the /shorter page is used by a logged-in user. Delegates persistence to
     * LinkRepositoryInterface::create().
     *
     * Rate-limited upstream by the public-shorten limiter (10/min per IP).
     *
     * @param  CreatePublicLinkDTO  $linkDTO  Validated input DTO.
     * @return Link The newly created model.
     *
     * @throws \InvalidArgumentException If URL is invalid, data is insufficient, or slug is taken.
     */
    public function createPublicLink(CreatePublicLinkDTO $linkDTO): Link
    {
        // Validação de negócio
        if (! $linkDTO->isValidUrl()) {
            throw new \InvalidArgumentException('URL inválida fornecida.');
        }

        if (! $linkDTO->hasValidData()) {
            throw new \InvalidArgumentException('Dados insuficientes para criar o link.');
        }

        $data = $linkDTO->toArray();

        // Gera slug único se não fornecido
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        return $this->linkRepository->create($data);
    }

    /**
     * Generates a random alphanumeric slug that does not yet exist in the links table.
     *
     * Loops until a unique value is found (collision probability is negligible at
     * standard table sizes with the default 6-char length). Delegates uniqueness
     * check to LinkRepositoryInterface::slugExists().
     *
     * @param  int  $length  Slug character length (default 6).
     * @return string The generated unique slug.
     */
    public function generateUniqueSlug(int $length = 6): string
    {
        do {
            $slug = Str::random($length);
        } while ($this->linkRepository->slugExists($slug));

        return $slug;
    }
}
