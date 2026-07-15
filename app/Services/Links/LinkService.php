<?php

namespace App\Services\Links;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\CreatePublicLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Models\Link;
use App\Models\Tag;
use App\Models\UserSubdomain;
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
     * @return Link The newly created model (ready to pass to LinkResource), with the
     *              `tags` relation eager-loaded.
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

        // Resolve short_domain from the caller's subdomain choice (or the
        // default, oldest-active one) and record it on the link. Stored at
        // creation time so the short URL is immutable even if the user later
        // releases the subdomain or picks a different one for future links.
        $data['short_domain'] = $this->resolveShortDomain(
            $linkDTO->user_id,
            $linkDTO->subdomain_id,
            $linkDTO->subdomain_id_provided
        );

        // Gera slug único se não fornecido
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        $link = $this->linkRepository->create($data);

        if ($linkDTO->tag_ids !== null) {
            $this->syncLinkTags($link, $linkDTO->tag_ids, $linkDTO->user_id);
        } else {
            // No tag_ids sent: still eager-load the (empty) relation so
            // LinkResource always serialises a `tags` array, never a
            // missing key, for a freshly created link.
            $link->load('tags');
        }

        \App\Logging\AppLogger::linkCreated($link, false);

        return $link;
    }

    /**
     * Resolves the `short_domain` a new link should be created with.
     *
     * `short_domain` is immutable once a link is created (existing contract —
     * links never change domain after creation, even if the underlying
     * UserSubdomain is later released). Three cases, distinguished by
     * `$wasProvided` so an absent `subdomain_id` field can be told apart from
     * an explicit `null`:
     *
     *   - `$wasProvided === true && $subdomainId === null`: the caller
     *     explicitly asked for the default root domain — returns null even if
     *     the user has an active subdomain.
     *   - `$subdomainId !== null`: the caller chose a specific subdomain. It
     *     must be active and owned by `$userId`, otherwise this throws.
     *   - Neither of the above (field absent): preserves the
     *     pre-multi-subdomain behavior — falls back to the user's default
     *     (oldest active) subdomain via {@see UserSubdomain::findByUserCached()},
     *     or null if the user has none.
     *
     * @param  int  $userId  Owner the resolved subdomain (if any) must belong to.
     * @param  int|null  $subdomainId  Id of the UserSubdomain the caller chose, or null.
     * @param  bool  $wasProvided  Whether the `subdomain_id` field was present in the request at all.
     * @return string|null The `short_domain` value to persist on the link, or null for the default domain.
     *
     * @throws \InvalidArgumentException If `$subdomainId` does not reference an active subdomain owned by `$userId`.
     */
    private function resolveShortDomain(int $userId, ?int $subdomainId, bool $wasProvided): ?string
    {
        if ($wasProvided && $subdomainId === null) {
            return null; // Usuário escolheu explicitamente o domínio padrão.
        }

        if ($subdomainId !== null) {
            $sub = UserSubdomain::where('id', $subdomainId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->first();

            if ($sub === null) {
                throw new \InvalidArgumentException('Subdomínio inválido.');
            }

            return $sub->subdomain.'.'.config('app.domain');
        }

        $default = UserSubdomain::findByUserCached($userId);

        return $default !== null ? $default->subdomain.'.'.config('app.domain') : null;
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
     * @return Link|null Updated model with the `tags` relation eager-loaded, or
     *                   null if not found.
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

        $userId = auth()->guard('api')->id();

        $link = $this->linkRepository->update($id, $linkDTO->toArray(), $userId);

        if (! $link) {
            return null;
        }

        if ($linkDTO->hasTagIds()) {
            $this->syncLinkTags($link, $linkDTO->tag_ids ?? [], $userId);
        } else {
            $link->load('tags');
        }

        return $link;
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

        $link = $this->linkRepository->create($data);

        \App\Logging\AppLogger::linkCreated($link, true);

        // Apply the user's default (oldest active) subdomain if they have one.
        // Mirrors createLink()'s behaviour for authenticated users; the public
        // shortener form has no subdomain picker, so there's never an explicit
        // choice or an explicit "force default domain" here.
        if (! empty($data['user_id'])) {
            $shortDomain = $this->resolveShortDomain((int) $data['user_id'], null, false);
            if ($shortDomain !== null) {
                $link->short_domain = $shortDomain;
                $link->save();
            }
        }

        return $link;
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
            $slug = strtolower(Str::random($length));
        } while ($this->linkRepository->slugExists($slug));

        return $slug;
    }

    /**
     * Sync a link's tags to exactly the subset of the given IDs that belong to $userId.
     *
     * Any id in $tagIds that does not belong to $userId (a foreign or
     * non-existent tag) is silently dropped rather than raising a validation
     * error — this keeps tag attachment forgiving of stale client state
     * (e.g. a tag the user just deleted in another tab) while still
     * preventing a user from attaching another user's private tag to their
     * link. `sync()` fully replaces the link's tag set with the filtered
     * list, matching the "tag_ids is a complete replacement, not a merge"
     * contract documented on {@see \App\DTOs\UpdateLinkDTO::$tag_ids}.
     * Always eager-loads the `tags` relation on $link afterwards so the
     * caller's LinkResource serialisation reflects the new tag list without
     * an extra round trip.
     *
     * @param  Link  $link  The link whose tags pivot will be synced.
     * @param  array<int, int>  $tagIds  Candidate tag IDs (may include foreign or non-existent IDs).
     * @param  int  $userId  Owner whose tags are allowed to be attached.
     */
    private function syncLinkTags(Link $link, array $tagIds, int $userId): void
    {
        $ownedTagIds = Tag::where('user_id', $userId)->whereIn('id', $tagIds)->pluck('id')->all();

        $link->tags()->sync($ownedTagIds);
        $link->load('tags');
    }
}
