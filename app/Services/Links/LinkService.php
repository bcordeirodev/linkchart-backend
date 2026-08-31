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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * Returns a paginated, filtered, and sorted list of the authenticated user's links.
     *
     * Delegates to LinkRepositoryInterface::searchByUser() scoped by
     * auth()->guard('api')->id(), the same authenticated-user resolution used
     * by getAllUserLinks() and getUserLink().
     *
     * @param  array{page: int, per_page: int, q?: string|null, status?: string|null, sort?: string|null, order?: string|null}  $filters  Validated query filters.
     * @return LengthAwarePaginator<int, Link>
     */
    public function searchUserLinks(array $filters): LengthAwarePaginator
    {
        return $this->linkRepository->searchByUser(auth()->guard('api')->id(), $filters);
    }

    /**
     * Executes a bulk action (activate/deactivate/delete) over the given user's links.
     *
     * Iterates via Eloquent on purpose: mass operations (`whereIn()->update()`/
     * `->delete()`) do NOT fire model events and would leave the cache in
     * Link::findActiveBySlugCached() stale — a direct regression on the
     * `/r/{slug}` hot path. The 50-id cap enforced by the controller keeps
     * this loop cheap. Ids that do not belong to `$userId` are excluded by the
     * `whereIn('id', $ids)` + `where('user_id', $userId)` scoping and are
     * simply absent from `$links`, so `affected` naturally comes out lower
     * than `requested` without any explicit existence check (no leak of
     * whether a foreign id exists).
     *
     * @param  int  $userId  ID of the user who must own the affected links.
     * @param  string  $action  One of 'activate', 'deactivate', 'delete'.
     * @param  array<int, int>  $ids  1–50 candidate link ids.
     * @return array{affected: int, requested: int}
     */
    public function bulkAction(int $userId, string $action, array $ids): array
    {
        $links = Link::where('user_id', $userId)->whereIn('id', $ids)->get();

        foreach ($links as $link) {
            match ($action) {
                'activate' => $link->update(['is_active' => true]),
                'deactivate' => $link->update(['is_active' => false]),
                'delete' => $link->delete(),
            };
        }

        return ['affected' => $links->count(), 'requested' => count($ids)];
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
        $slugWasGenerated = empty($data['slug']);
        if ($slugWasGenerated) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        $link = $this->createWithSlugCollisionRetry($data, $slugWasGenerated);

        // Senha do link: write-only e fora do mass-assignment (password_hash
        // não é fillable de propósito). Só o hash bcrypt toca o banco; o texto
        // puro nunca é persistido nem logado.
        if ($linkDTO->password !== null && $linkDTO->password !== '') {
            $link->password_hash = Hash::make($linkDTO->password);
            $link->save();
        }

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

        // Senha do link (write-only, fora do mass-assignment): presente com
        // valor => troca pelo novo hash; presente como null/vazio => remove;
        // ausente => não mexe. O save() dispara a invalidação do cache de slug
        // (password_hash está na lista de relevância de Link::booted()), então
        // a proteção vale imediatamente no hot path /r/{slug}.
        if ($linkDTO->hasPasswordField()) {
            $link->password_hash = ($linkDTO->password !== null && $linkDTO->password !== '')
                ? Hash::make($linkDTO->password)
                : null;
            $link->save();
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
        $slugWasGenerated = empty($data['slug']);
        if ($slugWasGenerated) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        $link = $this->createWithSlugCollisionRetry($data, $slugWasGenerated);

        // Claim-your-link: só o link que nasce ANÔNIMO ganha token. Um shorten
        // feito com JWT válido já sai com dono — token ali seria uma segunda
        // via de posse sem nenhum uso.
        if (empty($data['user_id'])) {
            $this->issueClaimToken($link);
        }

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
     * Emite o token de reivindicação de um link recém-criado anônimo.
     *
     * Gera 40 chars aleatórios (`Str::random`, CSPRNG — ~238 bits de entropia,
     * brute force inviável mesmo sem o rate limit), persiste APENAS o SHA-256 na
     * coluna `claim_token_hash` e devolve o token em claro pela propriedade
     * transiente {@see Link::$plainClaimToken}, de onde o
     * {@see \App\Http\Resources\PublicLinkResource} o serializa uma única vez.
     *
     * Segue o padrão de `password_hash`: a coluna está FORA do `$fillable` de
     * propósito (nenhum payload de cliente pode plantar um hash de claim), então
     * a atribuição é explícita seguida de `save()`. Esse `save()` extra não
     * suja o cache do slug — `claim_token_hash` não está na lista de relevância
     * de {@see Link::booted()} e, de todo modo, o link acabou de ser inserido.
     *
     * Sem expiração e sem rotação por decisão de escopo (YAGNI): o token vale
     * até o link ser reivindicado, quando o UPDATE do claim zera o hash e o
     * queima permanentemente.
     *
     * @param  Link  $link  Link recém-criado, ainda sem dono.
     */
    private function issueClaimToken(Link $link): void
    {
        $token = Str::random(40);

        $link->claim_token_hash = hash('sha256', $token);
        $link->save();

        $link->plainClaimToken = $token;
    }

    /**
     * Reivindica um link anônimo para um usuário, mediante o token de criação.
     *
     * Núcleo do claim-your-link. A troca de dono é um ÚNICO UPDATE condicional,
     * no padrão de claim at-most-once da casa (o mesmo dos `*_sent_at` de
     * retenção): as três condições que autorizam o claim viajam no `WHERE`, de
     * modo que o banco resolve a corrida sozinho — duas requisições simultâneas
     * com o mesmo token produzem exatamente um UPDATE de 1 linha e um de 0.
     * Nada de SELECT-then-UPDATE, que teria janela para duplo claim.
     *
     * Zerar `claim_token_hash` no mesmo UPDATE queima o token: o link deixa de
     * ser reivindicável para sempre, mesmo que alguém tenha guardado o valor.
     *
     * O histórico de cliques vem junto de graça — `clicks.link_id` aponta para
     * o link, não para o dono, então nada precisa ser migrado.
     *
     * DESAMBIGUAÇÃO DE FALHA (0 linhas afetadas): consultamos o estado do link
     * DEPOIS do UPDATE, nunca antes. `already_claimed` quando a linha existe e
     * já tem `user_id` — a informação é inócua (quem tem o token sabe que criou
     * o link) e o frontend precisa dela para descartar a pendência do
     * localStorage. Todo o resto (slug inexistente, token errado, link anônimo
     * antigo sem hash) colapsa em `invalid`, com o MESMO código de erro, para
     * não virar oráculo de enumeração de slugs.
     *
     * CACHE — decisão deliberada: invalidamos `link:slug:{slug}` explicitamente.
     * O UPDATE via `DB::table` não dispara eventos de model, logo o
     * {@see Link::booted()} não roda e o payload de
     * {@see Link::findActiveBySlugCached()} continuaria carregando o `user_id`
     * antigo (null) por até 10 min. Isso é inofensivo PARA O REDIRECT — o hot
     * path `/r/{slug}` só lê slug/is_active/expires_at/starts_in/click_limit/
     * password_hash/original_url/short_domain, nenhum deles tocado aqui. Mesmo
     * assim o `Cache::forget` fica: custa um DEL que acontece no máximo uma vez
     * na vida de cada link, e elimina a armadilha de um consumidor futuro do
     * modelo cacheado confiar num `user_id` mentiroso.
     *
     * @param  string  $slug  Slug do link a reivindicar.
     * @param  string  $claimToken  Token em claro devolvido no shorten de convidado.
     * @param  int  $userId  Usuário autenticado que passa a ser o dono.
     * @return array{status: 'claimed'|'already_claimed'|'invalid', link: Link|null} `link` só é preenchido em `claimed`.
     */
    public function claimLink(string $slug, string $claimToken, int $userId): array
    {
        $affected = DB::table('links')
            ->where('slug', $slug)
            ->whereNull('user_id')
            ->where('claim_token_hash', hash('sha256', $claimToken))
            ->update([
                'user_id' => $userId,
                'claim_token_hash' => null,
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            Cache::forget(Link::slugCacheKey($slug));

            $link = Link::where('slug', $slug)->first();

            \App\Logging\AppLogger::linkClaimed($link->id, $userId, $slug);

            return ['status' => 'claimed', 'link' => $link];
        }

        // 0 linhas: descobrir POR QUE, sem revelar mais do que o necessário.
        $existing = Link::where('slug', $slug)->first(['id', 'user_id']);

        if ($existing !== null && $existing->user_id !== null) {
            return ['status' => 'already_claimed', 'link' => null];
        }

        return ['status' => 'invalid', 'link' => null];
    }

    /**
     * Persists a new link, absorbing the slug TOCTOU race on the unique index.
     *
     * Between slugExists()/generateUniqueSlug() and the INSERT, a concurrent
     * request can claim the same slug; the DB unique index then raises a
     * {@see UniqueConstraintViolationException} that previously escaped as an
     * HTTP 500. Recovery depends on who chose the slug:
     *
     *   - AUTO-generated slug: generate a fresh slug and retry the INSERT
     *     exactly once (collision probability after one retry is negligible);
     *     a second consecutive violation is rethrown untouched.
     *   - CUSTOM (user-chosen) slug: never silently replaced — the violation
     *     is translated into the same InvalidArgumentException the pre-check
     *     raises, so the caller still answers "slug already in use".
     *
     * @param  array<string, mixed>  $data  Column map for links (slug already filled in).
     * @param  bool  $slugWasGenerated  True when $data['slug'] came from generateUniqueSlug().
     * @return Link The persisted link.
     *
     * @throws \InvalidArgumentException When a user-chosen slug lost the race.
     * @throws UniqueConstraintViolationException When the single retry also collided.
     */
    private function createWithSlugCollisionRetry(array $data, bool $slugWasGenerated): Link
    {
        try {
            return $this->linkRepository->create($data);
        } catch (UniqueConstraintViolationException $e) {
            if (! $slugWasGenerated) {
                throw new \InvalidArgumentException('Slug personalizado já está em uso.', 0, $e);
            }

            \App\Logging\AppLogger::event('app', 'warning', 'link.slug_collision_retry', [
                'collided_slug' => $data['slug'],
            ]);

            $data['slug'] = $this->generateUniqueSlug();

            return $this->linkRepository->create($data);
        }
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
