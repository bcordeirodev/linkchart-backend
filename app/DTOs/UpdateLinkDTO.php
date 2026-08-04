<?php

namespace App\DTOs;

use Illuminate\Http\Request;

/**
 * Input DTO for authenticated link updates (PUT /api/links/{id}).
 *
 * Built by {@see \App\Http\Requests\UpdateLinkRequest} (a standard Laravel `Request`)
 * via the static factory {@see self::fromRequest()}, then passed to
 * {@see \App\Services\Links\LinkService::updateLink()}, which delegates persistence
 * to {@see \App\Repositories\LinkRepository::update()}.
 *
 * All properties are nullable: the DTO represents a partial update. Only fields that
 * are present in the HTTP request are set; absent fields default to null and are
 * excluded from the update payload by {@see self::toArray()}. This prevents inadvertent
 * overwriting of existing link data. The object is immutable from construction.
 */
class UpdateLinkDTO
{
    /**
     * Fields that {@see self::toArray()} may emit, in DB-column order.
     * Used to intersect against the request keys so that "field present but
     * null/empty" (clear the value) is distinguished from "field absent" (keep).
     *
     * `slug` is listed here because it is the DB column {@see self::toArray()}
     * emits, but it never matches through this generic intersect — the wire
     * field is `custom_slug` — so {@see self::fromRequest()} tracks its
     * presence explicitly instead.
     */
    private const UPDATABLE_FIELDS = [
        'original_url', 'title', 'slug', 'description', 'expires_at',
        'is_active', 'starts_in', 'click_limit',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    /**
     * Names of the fields that were present in the source request.
     *
     * When non-null, {@see self::toArray()} emits exactly these fields (including
     * those whose value is null), which is what lets a request clear a nullable
     * column such as `click_limit` or a UTM parameter. When null (the DTO was
     * constructed directly rather than via {@see self::fromRequest()}), toArray()
     * falls back to legacy "strip all nulls" behaviour for backward compatibility.
     *
     * @var array<int, string>|null
     */
    private readonly ?array $presentFields;

    /** New destination URL; null means keep the existing value. */
    public readonly ?string $original_url;

    /** New human-readable label; null means keep the existing value. */
    public readonly ?string $title;

    /**
     * New slug for the short URL; null means keep the existing slug.
     * Read from the request's `custom_slug` field — not `slug` — to match
     * {@see \App\Http\Requests\CreateLinkRequest} and the frontend form field
     * of the same name; `slug` here is only the DB column name. Uniqueness
     * (ignoring this link's own id) is enforced by
     * {@see \App\Http\Requests\UpdateLinkRequest} via a `unique:links,slug`
     * rule before this DTO is ever built.
     */
    public readonly ?string $slug;

    /** New internal description; null means keep the existing value. */
    public readonly ?string $description;

    /**
     * New expiry datetime string (ISO-8601 / any format accepted by Carbon).
     * Null means keep the existing expiry (or no expiry if none was set).
     */
    public readonly ?string $expires_at;

    /**
     * New active state; null means keep the existing state.
     * Distinguished from false: null signals "field not sent", false signals "deactivate".
     */
    public readonly ?bool $is_active;

    /**
     * New activation datetime string (ISO-8601 / any format accepted by Carbon).
     * Null means keep the existing value.
     */
    public readonly ?string $starts_in;

    /**
     * New click limit; null signals the field was not sent (keep existing).
     * To clear an existing limit the request must send an explicit zero or null value,
     * which the factory handles by treating falsy non-absent values as null.
     */
    public readonly ?int $click_limit;

    /** New UTM source; null means keep the existing value. */
    public readonly ?string $utm_source;

    /** New UTM medium; null means keep the existing value. */
    public readonly ?string $utm_medium;

    /** New UTM campaign; null means keep the existing value. */
    public readonly ?string $utm_campaign;

    /** New UTM term; null means keep the existing value. */
    public readonly ?string $utm_term;

    /** New UTM content; null means keep the existing value. */
    public readonly ?string $utm_content;

    /**
     * New complete set of tag IDs to sync onto the link, replacing the
     * current set; null means "tag_ids was not sent" (leave tags untouched).
     * Deliberately excluded from {@see self::toArray()} — tags are a
     * many-to-many relation, not a `links` column — and tracked separately
     * from {@see self::$presentFields} via {@see self::$tagIdsPresent} /
     * {@see self::hasTagIds()}, since presence must be distinguishable from
     * "sent as an empty array" (clear all tags).
     *
     * @var array<int, int>|null
     */
    public readonly ?array $tag_ids;

    /**
     * Whether `tag_ids` was present in the source request, independent of
     * {@see self::$presentFields} (which only drives {@see self::toArray()}
     * mass-assignment and never includes `tag_ids`). See {@see self::hasTagIds()}.
     */
    private readonly bool $tagIdsPresent;

    /**
     * New plain-text password for the link (write-only input); null both when
     * the field was absent AND when it was sent as null/empty ("remove the
     * password") — the two cases are told apart by {@see self::$passwordPresent}
     * / {@see self::hasPasswordField()}. Deliberately excluded from
     * {@see self::toArray()}: `password` is not a `links` column and the plain
     * text must never reach mass-assignment or logs.
     * {@see \App\Services\Links\LinkService::updateLink()} reads it directly
     * and stores only the bcrypt hash (or null) in `links.password_hash`.
     */
    public readonly ?string $password;

    /**
     * Whether `password` was present in the source request at all. Present
     * with a value = set/replace; present as null/empty = remove; absent =
     * leave untouched. See {@see self::hasPasswordField()}.
     */
    private readonly bool $passwordPresent;

    /**
     * @param  string|null  $original_url  New destination URL; null = unchanged.
     * @param  string|null  $title  New label; null = unchanged.
     * @param  string|null  $slug  New slug; null = unchanged.
     * @param  string|null  $description  New description; null = unchanged.
     * @param  string|null  $expires_at  New expiry datetime; null = unchanged.
     * @param  bool|null  $is_active  New active state; null = unchanged.
     * @param  string|null  $starts_in  New activation datetime; null = unchanged.
     * @param  int|null  $click_limit  New click cap; null = unchanged.
     * @param  string|null  $utm_source  New UTM source; null = unchanged.
     * @param  string|null  $utm_medium  New UTM medium; null = unchanged.
     * @param  string|null  $utm_campaign  New UTM campaign; null = unchanged.
     * @param  string|null  $utm_term  New UTM term; null = unchanged.
     * @param  string|null  $utm_content  New UTM content; null = unchanged.
     * @param  array<int, string>|null  $presentFields  Field names present in the source request; null = legacy null-stripping in toArray().
     * @param  array<int, int>|null  $tag_ids  New complete tag id set; null = tag_ids not sent (leave tags untouched).
     * @param  bool  $tagIdsPresent  Whether tag_ids was present in the source request.
     * @param  string|null  $password  New plain-text link password; null = remove (when present) or untouched (when absent).
     * @param  bool  $passwordPresent  Whether password was present in the source request.
     */
    public function __construct(
        ?string $original_url = null,
        ?string $title = null,
        ?string $slug = null,
        ?string $description = null,
        ?string $expires_at = null,
        ?bool $is_active = null,
        ?string $starts_in = null,
        ?int $click_limit = null,
        ?string $utm_source = null,
        ?string $utm_medium = null,
        ?string $utm_campaign = null,
        ?string $utm_term = null,
        ?string $utm_content = null,
        ?array $presentFields = null,
        ?array $tag_ids = null,
        bool $tagIdsPresent = false,
        ?string $password = null,
        bool $passwordPresent = false
    ) {
        $this->presentFields = $presentFields;
        $this->original_url = $original_url;
        $this->title = $title;
        $this->slug = $slug;
        $this->description = $description;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->starts_in = $starts_in;
        $this->click_limit = $click_limit;
        $this->utm_source = $utm_source;
        $this->utm_medium = $utm_medium;
        $this->utm_campaign = $utm_campaign;
        $this->utm_term = $utm_term;
        $this->utm_content = $utm_content;
        $this->tag_ids = $tag_ids;
        $this->tagIdsPresent = $tagIdsPresent;
        $this->password = $password;
        $this->passwordPresent = $passwordPresent;
    }

    /**
     * Build an UpdateLinkDTO from a validated HTTP request.
     *
     * Only fields actually present in the request are populated; absent optional fields
     * default to null so they are excluded from {@see self::toArray()} and will not
     * overwrite existing database values. Special cases:
     * - `is_active`: uses `$request->has()` to distinguish "sent as false" from "not sent".
     * - `click_limit`: a sent-but-falsy value (0 or null) is stored as null and,
     *   because the field is recorded in `$presentFields`, is emitted by toArray()
     *   so the existing cap is cleared. An absent field is not emitted.
     * - UTM parameters: empty strings are coerced to null; when the field was sent
     *   (present) this clears the stored value, otherwise it is left unchanged.
     *
     * @param  Request  $request  HTTP request, typically validated by {@see \App\Http\Requests\UpdateLinkRequest}.
     * @return self Immutable partial-update DTO.
     */
    public static function fromRequest(Request $request): self
    {
        // Only the keys actually present in the request body — this is what
        // distinguishes "clear this field" (present + null/empty) from
        // "leave it alone" (absent) in toArray().
        $presentFields = array_values(array_intersect(self::UPDATABLE_FIELDS, $request->keys()));

        // `slug` is a special case: the wire field is `custom_slug` (see the
        // $slug property doc), so it can never appear in $request->keys() and
        // the generic intersect above can't detect it. Tracked explicitly so
        // toArray() still emits `slug` — under the `slug` DB-column key it
        // already uses — whenever `custom_slug` was sent.
        if ($request->has('custom_slug') && ! in_array('slug', $presentFields, true)) {
            $presentFields[] = 'slug';
        }

        return new self(
            original_url: $request->input('original_url'),
            title: $request->input('title'),
            slug: $request->input('custom_slug'),
            description: $request->input('description'),
            expires_at: $request->input('expires_at'),
            is_active: $request->has('is_active') ? $request->boolean('is_active') : null,
            starts_in: $request->input('starts_in'),
            click_limit: $request->has('click_limit') ? ($request->input('click_limit') ? (int) $request->input('click_limit') : null) : null,
            utm_source: $request->input('utm_source') ?: null,
            utm_medium: $request->input('utm_medium') ?: null,
            utm_campaign: $request->input('utm_campaign') ?: null,
            utm_term: $request->input('utm_term') ?: null,
            utm_content: $request->input('utm_content') ?: null,
            presentFields: $presentFields,
            tag_ids: $request->has('tag_ids')
                ? array_map('intval', $request->input('tag_ids', []))
                : null,
            tagIdsPresent: $request->has('tag_ids'),
            password: $request->input('password') ?: null,
            passwordPresent: $request->has('password')
        );
    }

    /**
     * Serialize the fields to update for Eloquent mass-assignment.
     *
     * When the DTO was built from a request ({@see self::fromRequest()}), only the
     * fields present in that request are emitted — including those whose value is
     * null — so a partial update can both set and *clear* nullable columns
     * (e.g. send `click_limit: 0` to remove an existing cap). Absent fields are
     * never emitted and therefore never overwrite existing link data.
     *
     * When built directly (no `$presentFields`), it falls back to stripping nulls
     * for backward compatibility.
     *
     * @return array<string, mixed> Associative array with only the fields to update.
     */
    public function toArray(): array
    {
        $all = [
            'original_url' => $this->original_url,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'starts_in' => $this->starts_in,
            'click_limit' => $this->click_limit,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_term' => $this->utm_term,
            'utm_content' => $this->utm_content,
        ];

        // Direct construction: legacy behaviour, strip all nulls.
        if ($this->presentFields === null) {
            return array_filter($all, fn ($value) => $value !== null);
        }

        // Request-built: emit exactly the fields the client sent, so nullable
        // columns can be cleared by sending an explicit null/empty value. Only
        // the nullable columns (expires_at, starts_in, click_limit, utm_*) can
        // legitimately arrive as null here — UpdateLinkRequest validates the
        // NOT NULL columns (custom_slug, original_url, title, description) with
        // `sometimes|string|url` (no `nullable`), so a present-but-null value
        // for those fails validation upstream and never reaches this DTO.
        return array_intersect_key($all, array_flip($this->presentFields));
    }

    /**
     * Check whether this DTO contains at least one field to update.
     *
     * Returns false when every property is null (i.e. the request body was empty or
     * all fields were omitted), allowing the service/controller to short-circuit and
     * return a validation error rather than issuing a no-op UPDATE query.
     *
     * Also true when `tag_ids` was present, even if `toArray()` is otherwise
     * empty — a request containing only `tag_ids` is a legitimate partial
     * update (sync the link's tags) and must not be rejected as empty. The
     * same applies to `password`: setting or removing the link password is a
     * legitimate update even though it never goes through toArray().
     *
     * @return bool True when `toArray()` produces at least one entry, or tag_ids/password was sent.
     */
    public function hasDataToUpdate(): bool
    {
        return ! empty($this->toArray()) || $this->tagIdsPresent || $this->passwordPresent;
    }

    /**
     * Check whether `tag_ids` was present in the source request.
     *
     * Distinguishes "sync tags to an empty set" (present, empty array) from
     * "leave tags untouched" (absent). Used by
     * {@see \App\Services\Links\LinkService::updateLink()} to decide whether
     * to call `$link->tags()->sync()` at all.
     *
     * @return bool True if tag_ids was sent in the request that built this DTO.
     */
    public function hasTagIds(): bool
    {
        return $this->tagIdsPresent;
    }

    /**
     * Check whether `password` was present in the source request.
     *
     * Distinguishes the three update semantics consumed by
     * {@see \App\Services\Links\LinkService::updateLink()}:
     * present with a value (set/replace, `$password` non-null), present as
     * null/empty (remove, `$password` null), absent (leave untouched — this
     * method returns false).
     *
     * @return bool True if `password` was sent in the request that built this DTO.
     */
    public function hasPasswordField(): bool
    {
        return $this->passwordPresent;
    }

    /**
     * Check whether the stored URL is valid when one was provided.
     *
     * Returns true when `original_url` is null (field not sent — no URL to validate)
     * or when it passes PHP's built-in `FILTER_VALIDATE_URL`. Authoritative validation
     * is performed upstream; this method is a lightweight post-construction sanity check.
     *
     * @return bool True if no URL was provided, or the provided URL passes URL validation.
     */
    public function isValidUrl(): bool
    {
        return $this->original_url === null ||
               filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }
}
