<?php

namespace App\Services\Links;

use App\Contracts\Repositories\LinkRepositoryInterface;
use Illuminate\Support\Str;

/**
 * Resolves a single, verified, available slug suggestion for an arbitrary URL.
 *
 * This replaces the previous client-side approach where the frontend looped
 * `base`, `base-2`, `base-3` … (one HTTP round-trip per candidate) until it
 * found a free slug. The whole resolution now happens server-side in a single
 * request, using cheap DB existence checks instead of N network calls.
 *
 * Resolution strategy:
 *   1. Derive a human-friendly base slug from the target page's og:title
 *      (via {@see LinkPreviewService}), falling back to the URL path/host.
 *   2. If the base is free, return it as-is.
 *   3. Otherwise append a short random token (`base-k3f9`) — never a sequential
 *      counter — and re-check, retrying a few times.
 *   4. As a last resort, return a fully random unique slug.
 *
 * Used by the public (unauthenticated) shortener via PublicLinkController.
 */
class SlugSuggestionService
{
    /**
     * Reserved slugs that must never be suggested. Mirrors the `not_in` rule in
     * {@see \App\Http\Requests\CreatePublicLinkRequest} so a suggestion can
     * always be submitted to the create endpoint without tripping validation.
     */
    private const RESERVED = [
        'api', 'admin', 'www', 'mail', 'ftp', 'app', 'web',
        'public', 'short', 'link', 'url', 'r', 'health',
    ];

    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 100;

    /** Characters reserved for the `-token` collision suffix (hyphen + 4 chars). */
    private const TOKEN_RESERVE = 5;

    /** Max `base-token` attempts before falling back to a fully random slug. */
    private const MAX_TOKEN_ATTEMPTS = 8;

    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LinkPreviewService $previewService,
    ) {}

    /**
     * Suggest a single available slug for the given target URL.
     *
     * @param  string  $url  The target URL (with or without scheme).
     * @return string A normalized, currently-available slug.
     */
    public function suggestForUrl(string $url): string
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $base = $this->deriveBase($normalizedUrl);

        return $this->resolveAvailable($base);
    }

    /**
     * Trim and prefix `https://` when the input has no scheme, so downstream
     * `parse_url` and preview fetches behave consistently.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /**
     * Derive a base slug, preferring the page's og:title and falling back to the
     * URL's last path segment, then its host label. Returns null when nothing
     * usable can be produced (caller then uses a random slug).
     */
    private function deriveBase(string $url): ?string
    {
        try {
            $title = $this->previewService->fetchPreview($url)['og_title'] ?? null;
            if ($title) {
                $fromTitle = $this->normalize($title);
                if ($fromTitle !== null) {
                    return $fromTitle;
                }
            }
        } catch (\Throwable) {
            // Preview fetch is best-effort — fall through to URL-based derivation.
        }

        return $this->deriveFromUrl($url);
    }

    /**
     * Derive a base slug from the URL itself: last meaningful path segment
     * (extension stripped), then the host label without `www.`.
     */
    private function deriveFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if (! empty($segments)) {
            $last = urldecode((string) end($segments));
            $last = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $last);
            $fromPath = $this->normalize((string) $last);
            if ($fromPath !== null) {
                return $fromPath;
            }
        }

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^www\./i', '', $host);
        $hostLabel = explode('.', $host)[0] ?? '';

        return $this->normalize($hostLabel);
    }

    /**
     * Normalize an arbitrary string into a public-safe slug, or null when the
     * result is too short or a reserved word.
     */
    private function normalize(string $value): ?string
    {
        $slug = Str::slug($value);
        $slug = substr($slug, 0, self::MAX_LENGTH);
        $slug = trim($slug, '-');

        if (strlen($slug) < self::MIN_LENGTH) {
            return null;
        }

        if (in_array($slug, self::RESERVED, true)) {
            return null;
        }

        return $slug;
    }

    /**
     * Return the base if free; otherwise append a short random token and retry;
     * finally fall back to a fully random unique slug.
     */
    private function resolveAvailable(?string $base): string
    {
        if ($base === null) {
            return $this->randomUniqueSlug();
        }

        if (! $this->linkRepository->slugExists($base)) {
            return $base;
        }

        $prefix = substr($base, 0, self::MAX_LENGTH - self::TOKEN_RESERVE);
        $prefix = trim($prefix, '-');

        for ($i = 0; $i < self::MAX_TOKEN_ATTEMPTS; $i++) {
            $candidate = $prefix.'-'.strtolower(Str::random(4));
            if (! $this->linkRepository->slugExists($candidate)) {
                return $candidate;
            }
        }

        return $this->randomUniqueSlug();
    }

    /**
     * Generate a random lowercase alphanumeric slug guaranteed to be unique.
     */
    private function randomUniqueSlug(int $length = 6): string
    {
        do {
            $slug = strtolower(Str::random($length));
        } while ($this->linkRepository->slugExists($slug));

        return $slug;
    }
}
