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
    /** New destination URL; null means keep the existing value. */
    public readonly ?string $original_url;

    /** New human-readable label; null means keep the existing value. */
    public readonly ?string $title;

    /**
     * New slug for the short URL; null means keep the existing slug.
     * Uniqueness is enforced by the service layer before persistence.
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
        ?string $utm_content = null
    ) {
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
    }

    /**
     * Build an UpdateLinkDTO from a validated HTTP request.
     *
     * Only fields actually present in the request are populated; absent optional fields
     * default to null so they are excluded from {@see self::toArray()} and will not
     * overwrite existing database values. Special cases:
     * - `is_active`: uses `$request->has()` to distinguish "sent as false" from "not sent".
     * - `click_limit`: uses `$request->has()` to distinguish "sent as 0/null" from "not sent";
     *   a sent but falsy value is stored as null (clear the cap).
     * - UTM parameters: empty strings are coerced to null to avoid storing blank values.
     *
     * @param  Request  $request  HTTP request, typically validated by {@see \App\Http\Requests\UpdateLinkRequest}.
     * @return self Immutable partial-update DTO.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            original_url: $request->input('original_url'),
            title: $request->input('title'),
            slug: $request->input('slug'),
            description: $request->input('description'),
            expires_at: $request->input('expires_at'),
            is_active: $request->has('is_active') ? $request->boolean('is_active') : null,
            starts_in: $request->input('starts_in'),
            click_limit: $request->has('click_limit') ? ($request->input('click_limit') ? (int) $request->input('click_limit') : null) : null,
            utm_source: $request->input('utm_source') ?: null,
            utm_medium: $request->input('utm_medium') ?: null,
            utm_campaign: $request->input('utm_campaign') ?: null,
            utm_term: $request->input('utm_term') ?: null,
            utm_content: $request->input('utm_content') ?: null
        );
    }

    /**
     * Serialize only the non-null properties to an array for Eloquent mass-assignment.
     *
     * Null values are stripped via `array_filter` so that only the fields that were
     * explicitly provided in the request reach the database. This is what makes
     * partial updates safe: absent fields will not overwrite existing link data.
     *
     * @return array<string, mixed> Associative array with only the fields to update.
     */
    public function toArray(): array
    {
        return array_filter([
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
        ], fn ($value) => $value !== null);
    }

    /**
     * Check whether this DTO contains at least one field to update.
     *
     * Returns false when every property is null (i.e. the request body was empty or
     * all fields were omitted), allowing the service/controller to short-circuit and
     * return a validation error rather than issuing a no-op UPDATE query.
     *
     * @return bool True when `toArray()` produces at least one entry.
     */
    public function hasDataToUpdate(): bool
    {
        return ! empty($this->toArray());
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
