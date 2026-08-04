<?php

namespace App\Services\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Logging\AppLogger;
use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use App\Models\UserSubdomain;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        return $this->formatPublic($page);
    }

    /**
     * {@inheritDoc}
     */
    public function getPublicBySubdomain(string $subdomain): ?array
    {
        $sub = UserSubdomain::where('subdomain', strtolower($subdomain))->where('status', 'active')->first();
        if (! $sub) {
            return null;
        }

        $page = BioPage::where('subdomain_id', $sub->id)->where('is_active', true)->first();
        if (! $page) {
            return null;
        }

        return $this->formatPublic($page);
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
        $page = BioPage::where('user_id', $userId)->first();
        $isNew = $page === null;

        // Must run BEFORE $page is replaced with a new instance below (for
        // $isNew) — the check needs the EXISTING row's current subdomain_id
        // to tell a legacy page apart from one that already has an
        // association. Throws before any mutation, so a rejected request
        // never touches the DB.
        $this->assertSubdomainProvidedWhenRequired($page, $isNew, $data);

        // Subdomain-first: o editor nao envia handle. Criacao deriva do label
        // do subdominio associado (com sufixo em colisao/reservado); update
        // sem handle mantem o atual — nunca re-deriva, nem quando o
        // subdominio troca. Handle explicito (API) segue aceito.
        if (array_key_exists('handle', $data)) {
            $handle = strtolower($data['handle']);
        } elseif ($isNew) {
            $handle = $this->deriveHandleFromSubdomain($userId, $data);
        } else {
            $handle = $page->handle;
        }

        $handleChanged = ! $isNew && $page->handle !== $handle;

        if ($isNew) {
            $page = new BioPage(['user_id' => $userId]);
        }

        $page->fill([
            'handle' => $handle,
            'title' => $data['title'],
            // array_key_exists, não ??: PUT parcial (campo ausente) preserva a
            // bio salva; só `bio: null` explícito limpa — mesma semântica dos
            // demais campos opcionais abaixo.
            'bio' => array_key_exists('bio', $data) ? $data['bio'] : ($page->bio ?? null),
            'theme' => $data['theme'] ?? ($page->theme ?? 'dark'),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($page->is_active ?? true),
        ]);

        // subdomain_id is handled outside fill()/$fillable on purpose — it
        // goes through ownership/active-status validation, never blind mass
        // assignment. Absent from $data: leave the current association (if
        // any) untouched — assertSubdomainProvidedWhenRequired() above
        // already guarantees that's only reachable when one already exists.
        // Present as an int: must be an ACTIVE subdomain owned by $userId,
        // mirroring LinkService::resolveShortDomain()'s check for links.
        // Present as `null` never reaches here — rejected above; the bio
        // page's subdomain, once required, can no longer be detached via
        // update, only replaced with another active one.
        if (array_key_exists('subdomain_id', $data)) {
            $sub = UserSubdomain::where('id', $data['subdomain_id'])
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->first();

            if ($sub === null) {
                throw new \InvalidArgumentException('Subdomínio inválido, inativo ou não pertence ao usuário.');
            }

            $page->subdomain_id = $sub->id;
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
     * Deriva um handle livre a partir do label do subdominio associado.
     *
     * Base = label do subdominio (ja lowercase). Se a base for invalida no
     * formato de handle, estiver na blocklist (`config('bio.reserved_handles')`)
     * ou ja pertencer a OUTRO usuario, tenta `{base}-1`, `{base}-2`, ...
     * truncando a base quando preciso para caber no limite de 30 chars.
     *
     * @param  int  $userId  Dono da pagina.
     * @param  array<string, mixed>  $data  Payload validado do upsert (subdomain_id garantido presente na criacao).
     * @return string Handle livre e valido.
     *
     * @throws \InvalidArgumentException Se o subdominio nao for ativo/do usuario.
     */
    private function deriveHandleFromSubdomain(int $userId, array $data): string
    {
        $sub = UserSubdomain::where('id', $data['subdomain_id'] ?? 0)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if ($sub === null) {
            throw new \InvalidArgumentException('Subdomínio inválido, inativo ou não pertence ao usuário.');
        }

        $base = strtolower($sub->subdomain);

        if ($this->handleUsable($base, $userId)) {
            return $base;
        }

        for ($n = 1; $n < 100; $n++) {
            $suffix = '-'.$n;
            $candidate = substr($base, 0, 30 - strlen($suffix)).$suffix;

            if ($this->handleUsable($candidate, $userId)) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException('Não foi possível derivar um identificador livre para a página.');
    }

    /**
     * Um candidato a handle e utilizavel: formato valido, fora da blocklist e
     * nao pertencente a outro usuario.
     */
    private function handleUsable(string $candidate, int $userId): bool
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $candidate)) {
            return false;
        }

        if (in_array($candidate, config('bio.reserved_handles', []), true)) {
            return false;
        }

        return ! BioPage::where('handle', $candidate)
            ->where('user_id', '<>', $userId)
            ->exists();
    }

    /**
     * Enforce the product rule that every bio page must have an associated
     * subdomain — the subdomain IS the page's identity (decision recorded
     * 2026-07-27). `bio_pages.subdomain_id` stays nullable in the schema
     * (pre-existing/legacy pages may still lack one), but {@see self::upsert()}
     * never lets a request through that would create or knowingly re-save a
     * page without one:
     *
     *   - CREATE ($isNew true): `subdomain_id` must be present and non-null.
     *   - UPDATE, `subdomain_id` explicitly sent as `null`: always rejected —
     *     detaching the subdomain via update is no longer allowed.
     *   - UPDATE, `subdomain_id` absent from the payload: the "leave the
     *     current association untouched" convenience only applies once the
     *     page already has one — a legacy page (`$page->subdomain_id` still
     *     null) must supply one on its very next save.
     *   - UPDATE, `subdomain_id` absent, page already has an association:
     *     allowed — see {@see self::upsert()}'s own handling right after this
     *     call, which leaves `$page->subdomain_id` untouched in that case.
     *
     * @param  ?BioPage  $page  For updates: the EXISTING row fetched from the
     *                          DB before any fill() — its current
     *                          `subdomain_id` is what determines "legacy".
     *                          Null (and ignored) for creates.
     * @param  bool  $isNew  Whether {@see self::upsert()} is about to create a
     *                       brand-new bio page for this user.
     * @param  array<string, mixed>  $data  Validated request payload.
     *
     * @throws \InvalidArgumentException When the rule above is violated —
     *                                   message surfaced as 422 by
     *                                   {@see \App\Http\Controllers\Bio\BioPageController::upsert()}.
     */
    private function assertSubdomainProvidedWhenRequired(?BioPage $page, bool $isNew, array $data): void
    {
        $hasKey = array_key_exists('subdomain_id', $data);
        $value = $hasKey ? $data['subdomain_id'] : null;

        $violates = $isNew
            ? $value === null
            : (($hasKey && $value === null) || (! $hasKey && $page?->subdomain_id === null));

        if ($violates) {
            throw new \InvalidArgumentException(
                'Sua página precisa de um endereço personalizado. Associe um subdomínio ativo para continuar.'
            );
        }
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

        // display defaults to 'item' when absent. social_platform is only
        // ever persisted alongside 'icon' — CreateBioPageItemRequest's
        // required_if already guarantees $data has a whitelisted value in
        // that case; any value submitted alongside 'item' is dropped here to
        // keep the "non-null only when icon" invariant true from the moment
        // the row is created, not just at the validation layer.
        $display = $data['display'] ?? BioPageItem::DISPLAY_ITEM;
        $socialPlatform = $display === BioPageItem::DISPLAY_ICON ? ($data['social_platform'] ?? null) : null;

        $item = BioPageItem::create([
            'bio_page_id' => $page->id,
            'link_id' => $link->id,
            'label' => $label,
            'position' => $nextPosition,
            'is_active' => true,
            'display' => $display,
            'social_platform' => $socialPlatform,
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

        $item->fill(array_intersect_key($data, array_flip(['label', 'is_active', 'display'])));

        // Invariant enforcement, evaluated on the EFFECTIVE display (already
        // applied by fill() above, whether this request touched it or not):
        //   - still/now 'icon' + $data explicitly sends social_platform →
        //     take the new value.
        //   - still/now 'icon' + $data doesn't mention social_platform →
        //     leave the item's existing value untouched (e.g. a label-only
        //     edit of an already-icon item).
        //   - effective display is 'item' → always null, regardless of what
        //     $data contains — an item-displayed row never carries a
        //     dangling platform.
        if ($item->display === BioPageItem::DISPLAY_ICON) {
            if (array_key_exists('social_platform', $data)) {
                $item->social_platform = $data['social_platform'];
            }
        } else {
            $item->social_platform = null;
        }

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
     * {@inheritDoc}
     */
    public function uploadAvatar(int $userId, UploadedFile $file, ?UploadedFile $thumb = null): array
    {
        $page = BioPage::where('user_id', $userId)->first();
        if (! $page) {
            throw new \InvalidArgumentException('Página bio ainda não foi criada.');
        }

        $disk = config('bio.avatar_disk', 'public');
        $this->deleteAvatarFile($page, $disk);

        // Random, non-enumerable filename — deliberately not derived from
        // $userId or the bio page id, so knowing/guessing either never
        // yields a working avatar URL.
        $filename = Str::random(40).'.'.$file->extension();
        $path = $file->storeAs('bio-avatars', $filename, $disk);

        $page->avatar_path = $path;
        $page->avatar_url = Storage::disk($disk)->url($path);

        // Miniatura (quando o cliente mandou): mesmo prefixo aleatório com
        // sufixo _thumb — original vira o Open Graph, thumb vira o círculo
        // da página. Upload antigo sem thumb zera os campos (o replace acima
        // já apagou o arquivo anterior via deleteAvatarFile).
        if ($thumb !== null) {
            $thumbPath = $thumb->storeAs(
                'bio-avatars',
                Str::beforeLast($filename, '.').'_thumb.'.$thumb->extension(),
                $disk,
            );
            $page->avatar_thumb_path = $thumbPath;
            $page->avatar_thumb_url = Storage::disk($disk)->url($thumbPath);
        } else {
            $page->avatar_thumb_path = null;
            $page->avatar_thumb_url = null;
        }

        $page->save();

        AppLogger::event('app', 'info', 'bio.avatar_uploaded', [
            'user_id' => $userId,
            'bio_page_id' => $page->id,
        ]);

        return $this->formatManagement($page->fresh());
    }

    /**
     * {@inheritDoc}
     */
    public function removeAvatar(int $userId): array
    {
        $page = BioPage::where('user_id', $userId)->first();
        if (! $page) {
            throw new \InvalidArgumentException('Página bio ainda não foi criada.');
        }

        $disk = config('bio.avatar_disk', 'public');
        $hadAvatar = $this->deleteAvatarFile($page, $disk);

        $page->avatar_path = null;
        $page->avatar_url = null;
        $page->avatar_thumb_path = null;
        $page->avatar_thumb_url = null;
        $page->save();

        if ($hadAvatar) {
            AppLogger::event('app', 'info', 'bio.avatar_removed', [
                'user_id' => $userId,
                'bio_page_id' => $page->id,
            ]);
        }

        return $this->formatManagement($page->fresh());
    }

    /**
     * Delete the bio page's currently stored avatar file from disk, if any.
     *
     * Always operates against the currently configured `$disk` — the only
     * disk this codebase ever stores or deletes avatar files on within a
     * single request/response cycle. If `avatar_path` was written under a
     * since-changed `bio.avatar_disk` value, this silently no-ops instead of
     * reaching across disks (deleting a path that does not exist on the
     * current disk is not an error for Laravel's Storage facade). Migrating
     * existing files after a disk change is an explicit operational step,
     * not something this method attempts.
     *
     * @return bool Whether the page had a stored avatar path to delete.
     */
    private function deleteAvatarFile(BioPage $page, string $disk): bool
    {
        // A thumb acompanha o ciclo de vida do original: replace/remove do
        // avatar sempre apaga as duas cópias.
        if ($page->avatar_thumb_path !== null) {
            Storage::disk($disk)->delete($page->avatar_thumb_path);
        }

        if ($page->avatar_path === null) {
            return false;
        }

        Storage::disk($disk)->delete($page->avatar_path);

        return true;
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
     * Build the public-shape array for a bio page — shared by
     * {@see self::getPublicByHandle()} and {@see self::getPublicBySubdomain()}
     * so both lookup paths return byte-identical shapes for the same page.
     *
     * Only active items are included. Never exposes `user_id`,
     * `subdomain_id`, `link_id`, or `original_url` — see PublicBioController.
     *
     * @return array{handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, avatar_thumb_url: ?string, url: string, items: array<int, array{id: int, label: string, url: string, display: string, social_platform: ?string}>}
     */
    private function formatPublic(BioPage $page): array
    {
        $items = $page->items()->where('is_active', true)->with('link.preview')->get();

        return [
            'handle' => $page->handle,
            'title' => $page->title,
            'bio' => $page->bio,
            'theme' => $page->theme,
            'avatar_url' => $page->avatar_url,
            'avatar_thumb_url' => $page->avatar_thumb_url,
            'url' => $this->computeUrl($page),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'label' => $item->label,
                'url' => $item->link->getShortedUrl(),
                // Favicon do destino (pipeline de previews): o botão público
                // mostra para onde o clique leva — sinal de confiança.
                'favicon_url' => $item->link->preview?->favicon_url,
                // SÓ o host (sem www) — path/query do original_url seguem
                // privados; o host se revela de todo jeito ao clicar.
                'destination_host' => $this->destinationHost($item->link->original_url),
                // Variante de renderização: 'icon' compõe a linha de ícones
                // sociais acima dos botões; 'item' é o botão full-width de
                // sempre. social_platform só é não-nulo junto de 'icon'.
                'display' => $item->display,
                'social_platform' => $item->social_platform,
            ])->values()->all(),
        ];
    }

    /**
     * Build the management-shape array for a single bio page item.
     *
     * `favicon_url` comes from the link's async-fetched preview
     * (link_previews) — the editor list's visual anchor; null until the
     * FetchLinkPreviewJob has run for that link.
     *
     * @return array{id: int, link_id: int, label: string, position: int, is_active: bool, display: string, social_platform: ?string, url: string, clicks: int, favicon_url: ?string}
     */
    private function formatItem(BioPageItem $item): array
    {
        return [
            'id' => $item->id,
            'link_id' => $item->link_id,
            'label' => $item->label,
            'position' => $item->position,
            'is_active' => $item->is_active,
            'display' => $item->display,
            'social_platform' => $item->social_platform,
            'url' => $item->link->getShortedUrl(),
            'clicks' => $item->link->clicks,
            'favicon_url' => $item->link->preview?->favicon_url,
            'destination_host' => $this->destinationHost($item->link->original_url),
        ];
    }

    /**
     * Host do destino final de um link, sem o prefixo www — a segunda linha
     * do botão da bio ("esse clique leva a github.com"). Null se a URL for
     * inparseável (nunca deveria: original_url é validada na criação).
     *
     * Decisão de privacidade (2026-07-29): expor o HOST público é aceitável —
     * qualquer visitante o vê ao clicar; path e query seguem privados. O
     * teste test_response_never_leaks_user_id_or_original_url guarda isso.
     */
    private function destinationHost(string $originalUrl): ?string
    {
        $host = parse_url($originalUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Build the management-shape array for a bio page (GET /api/bio, PUT /api/bio).
     *
     * Unlike the public shape, this includes `id`, `is_active`, and per-item
     * `link_id`/`position`/`is_active`/`clicks` — everything the editor UI
     * needs, none of which is exposed on the public endpoint.
     *
     * @return array{id: int, handle: string, title: string, bio: ?string, theme: string, avatar_url: ?string, avatar_thumb_url: ?string, is_active: bool, items: array<int, array<string, mixed>>}
     */
    private function formatManagement(BioPage $page): array
    {
        $items = $page->items()->with('link.preview')->get();

        return [
            'id' => $page->id,
            'handle' => $page->handle,
            'title' => $page->title,
            'bio' => $page->bio,
            'theme' => $page->theme,
            'avatar_url' => $page->avatar_url,
            'avatar_thumb_url' => $page->avatar_thumb_url,
            'is_active' => $page->is_active,
            'subdomain_id' => $page->subdomain_id,
            'url' => $this->computeUrl($page),
            'items' => $items->map(fn (BioPageItem $item) => $this->formatItem($item))->values()->all(),
        ];
    }
}
