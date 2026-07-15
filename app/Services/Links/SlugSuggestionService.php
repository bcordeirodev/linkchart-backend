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

    /**
     * Cap for a *derived* base slug. Far below {@see MAX_LENGTH} (which is only
     * the hard ceiling a user-typed slug must respect): an og:title can run for
     * a whole sentence, and a 100-character slug defeats the purpose of a short
     * link. The cut lands on a word boundary, never mid-word.
     */
    private const MAX_BASE_LENGTH = 48;

    /**
     * Connector / filler words that carry no identity in a slug — articles,
     * prepositions, conjunctions, possessives and common question words, in both
     * UI languages (en + pt-BR). Used two ways: dropped *anywhere* when a title is
     * shortened to its keywords (see {@see shortenToSignificantWords}), and
     * trimmed from the *tail* of a URL-derived slug (see
     * {@see trimTrailingStopwords}) so it never reads as if it got cut off.
     */
    private const STOPWORDS = [
        // en — articles, prepositions, conjunctions
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'is',
        'of', 'on', 'or', 'the', 'to', 'with', 'are', 'was', 'be',
        // en — possessives, demonstratives, question/filler words
        'my', 'your', 'his', 'her', 'its', 'our', 'their', 'this', 'that',
        'these', 'those', 'what', 'how', 'you',
        // pt-BR — articles, prepositions, conjunctions
        'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'no', 'o',
        'os', 'para', 'por', 'um', 'uma', 'as',
        // pt-BR — possessives, question/filler words
        'que', 'como', 'se', 'seu', 'sua', 'seus', 'suas', 'meu', 'minha',
        'meus', 'minhas', 'ao', 'aos', 'ou',
    ];

    /**
     * Maximum number of significant words kept when a title is shortened to a
     * slug. A shortener slug should read like a short name, not a whole sentence.
     */
    private const MAX_WORDS = 3;

    /** Characters reserved for the `-token` collision suffix (hyphen + 4 chars). */
    private const TOKEN_RESERVE = 5;

    /** Max `base-token` attempts before falling back to a fully random slug. */
    private const MAX_TOKEN_ATTEMPTS = 8;

    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LinkPreviewService $previewService,
    ) {}

    /**
     * Suggest a single available slug for the given target URL, alongside the
     * page's og:title.
     *
     * The preview is fetched once here and reused both for slug derivation and
     * for the returned `og_title`, so a public (unauthenticated) caller can fill
     * the link title and slug from a single request without a second metadata
     * round-trip.
     *
     * @param  string  $url  The target URL (with or without scheme).
     * @return array{slug: string, og_title: string|null} A normalized,
     *                                                    currently-available slug
     *                                                    and the page's og:title.
     */
    public function suggestForUrl(string $url): array
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $ogTitle = $this->fetchOgTitle($normalizedUrl);
        $base = $this->deriveBase($normalizedUrl, $ogTitle);

        return [
            'slug' => $this->resolveAvailable($base),
            'og_title' => $ogTitle,
        ];
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
     * Fetch the target page's og:title, best-effort. Preview failures are
     * swallowed (returns null) so slug resolution always proceeds.
     */
    private function fetchOgTitle(string $url): ?string
    {
        try {
            return $this->previewService->fetchPreview($url)['og_title'] ?? null;
        } catch (\Throwable) {
            // Preview fetch is best-effort — caller falls back to URL derivation.
            return null;
        }
    }

    /**
     * Derive a base slug, preferring the (already-fetched) og:title and falling
     * back to the URL's last path segment, then its host label. Returns null when
     * nothing usable can be produced (caller then uses a random slug).
     */
    private function deriveBase(string $url, ?string $ogTitle): ?string
    {
        if ($ogTitle) {
            // A title is prose — shorten it to its first few keywords. A URL path
            // (below) is already slug-like, so it is kept whole (only length- and
            // tail-trimmed) to respect what the destination site chose.
            $fromTitle = $this->normalize($ogTitle, limitWords: true);
            if ($fromTitle !== null) {
                return $fromTitle;
            }
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
     *
     * @param  string  $value  The raw source string (title, path segment, host).
     * @param  bool  $limitWords  When true, the value is treated as prose and
     *                            shortened to its first {@see MAX_WORDS} significant
     *                            words (stopwords dropped anywhere). When false it is
     *                            kept whole, only length- and tail-trimmed.
     */
    private function normalize(string $value, bool $limitWords = false): ?string
    {
        // Turn every separator run into a space *before* slugifying. Str::slug
        // deletes punctuation rather than splitting on it, so "anthropics/claude"
        // would otherwise collapse into the non-word "anthropicsclaude".
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        $slug = Str::slug($words);

        if ($limitWords) {
            $slug = $this->shortenToSignificantWords($slug);
        }

        $slug = $this->truncateAtWordBoundary($slug, self::MAX_BASE_LENGTH);
        $slug = $this->trimTrailingStopwords($slug);
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
     * Reduce a slugified title to its first {@see MAX_WORDS} significant words,
     * dropping {@see STOPWORDS} wherever they appear. If every word is a stopword
     * (e.g. a title like "The One"), the original words are used so the result is
     * never empty — the caller's reserved/min-length checks still apply.
     *
     * @param  string  $slug  An already-slugified (hyphen-separated) string.
     * @return string The slug reduced to at most MAX_WORDS words.
     */
    private function shortenToSignificantWords(string $slug): string
    {
        $parts = array_values(array_filter(explode('-', $slug), fn ($p) => $p !== ''));

        $significant = array_values(
            array_filter($parts, fn ($p) => ! in_array($p, self::STOPWORDS, true))
        );

        $words = ! empty($significant) ? $significant : $parts;

        return implode('-', array_slice($words, 0, self::MAX_WORDS));
    }

    /**
     * Cut a hyphenated slug down to at most $max characters without splitting a
     * word: drop the trailing partial segment. A single first word longer than
     * $max is kept as-is (hard-cut) — there is no boundary to fall back to.
     *
     * @param  string  $slug  An already-slugified (hyphen-separated) string.
     * @param  int  $max  Maximum length in characters.
     * @return string The truncated slug.
     */
    private function truncateAtWordBoundary(string $slug, int $max): string
    {
        if (strlen($slug) <= $max) {
            return $slug;
        }

        $cut = substr($slug, 0, $max);
        $lastHyphen = strrpos($cut, '-');

        return $lastHyphen === false || $lastHyphen === 0
            ? $cut
            : substr($cut, 0, $lastHyphen);
    }

    /**
     * Drop trailing connector words (see {@see STOPWORDS}) so a truncated title
     * doesn't leave the slug hanging on "…-is-an". The first segment is always
     * kept, even if it is itself a stopword.
     *
     * @param  string  $slug  An already-slugified (hyphen-separated) string.
     * @return string The slug without trailing connector words.
     */
    private function trimTrailingStopwords(string $slug): string
    {
        $parts = explode('-', $slug);

        while (count($parts) > 1 && in_array(end($parts), self::STOPWORDS, true)) {
            array_pop($parts);
        }

        return implode('-', $parts);
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
