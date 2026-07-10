<?php

namespace App\DTOs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Input DTO for authenticated link creation (POST /api/links).
 *
 * Built by {@see \App\Http\Requests\CreateLinkRequest} via the static factory
 * {@see self::fromRequest()}, then passed to {@see \App\Services\Links\LinkService::createLink()}.
 *
 * The object is immutable from construction: all properties are `readonly`. Validation
 * happens at the request layer before this DTO is instantiated, so callers may assume
 * the data is already clean. Downstream persistence is performed by the service layer,
 * which delegates to {@see \App\Repositories\LinkRepository::create()}.
 */
class CreateLinkDTO
{
    /** The destination URL that the short link will redirect visitors to. */
    public readonly string $original_url;

    /** ID of the authenticated user who owns this link; resolved from {@see \Illuminate\Support\Facades\Auth}. */
    public readonly int $user_id;

    /** Optional human-readable label shown in the dashboard. */
    public readonly ?string $title;

    /** Optional longer description for internal reference; not exposed publicly. */
    public readonly ?string $description;

    /**
     * Optional expiry datetime string (ISO-8601 / any format accepted by Carbon).
     * When set, the redirect will return 404 after this datetime.
     */
    public readonly ?string $expires_at;

    /** Whether the link is immediately active; defaults to true. */
    public readonly bool $is_active;

    /**
     * Optional activation datetime string (ISO-8601 / any format accepted by Carbon).
     * When set, the redirect will return 404 before this datetime.
     */
    public readonly ?string $starts_in;

    /**
     * Optional user-chosen slug for the short URL.
     * When null, {@see \App\Services\Links\LinkService} generates a unique slug.
     * Mapped to the `slug` column in {@see self::toArray()}.
     */
    public readonly ?string $custom_slug;

    /**
     * Optional maximum number of clicks before the link deactivates automatically.
     * Null means unlimited clicks.
     */
    public readonly ?int $click_limit;

    /** Optional UTM source parameter (e.g. "newsletter", "google"). */
    public readonly ?string $utm_source;

    /** Optional UTM medium parameter (e.g. "email", "cpc"). */
    public readonly ?string $utm_medium;

    /** Optional UTM campaign parameter (e.g. "spring_sale"). */
    public readonly ?string $utm_campaign;

    /** Optional UTM term parameter for paid search keywords. */
    public readonly ?string $utm_term;

    /** Optional UTM content parameter for A/B test differentiation. */
    public readonly ?string $utm_content;

    /**
     * Optional list of tag IDs to attach to the link on creation.
     *
     * Null means "no tag_ids field was sent" (create with no tags). An empty
     * array is meaningfully different only if the caller cares to distinguish
     * it, but both null and [] result in no tags being attached — see
     * {@see \App\Services\Links\LinkService::createLink()}. Ownership of each
     * id is verified in the service layer, not here; ids that do not belong
     * to `$user_id` are silently dropped. Deliberately excluded from
     * {@see self::toArray()} because tags are a relation, not a `links` column.
     *
     * @var array<int, int>|null
     */
    public readonly ?array $tag_ids;

    /**
     * @param  string  $original_url  Destination URL; must be a valid URL (validated upstream).
     * @param  int  $user_id  Authenticated user ID.
     * @param  string|null  $title  Human-readable label.
     * @param  string|null  $description  Internal description.
     * @param  string|null  $expires_at  Expiry datetime string; null means never expires.
     * @param  bool  $is_active  Whether to activate the link immediately.
     * @param  string|null  $starts_in  Activation datetime string; null means active from creation.
     * @param  string|null  $custom_slug  Desired slug; null triggers auto-generation.
     * @param  int|null  $click_limit  Maximum allowed clicks; null means unlimited.
     * @param  string|null  $utm_source  UTM source parameter.
     * @param  string|null  $utm_medium  UTM medium parameter.
     * @param  string|null  $utm_campaign  UTM campaign parameter.
     * @param  string|null  $utm_term  UTM term parameter.
     * @param  string|null  $utm_content  UTM content parameter.
     * @param  array<int, int>|null  $tag_ids  Candidate tag IDs to attach; null means none provided.
     */
    public function __construct(
        string $original_url,
        int $user_id,
        ?string $title = null,
        ?string $description = null,
        ?string $expires_at = null,
        bool $is_active = true,
        ?string $starts_in = null,
        ?string $custom_slug = null,
        ?int $click_limit = null,
        ?string $utm_source = null,
        ?string $utm_medium = null,
        ?string $utm_campaign = null,
        ?string $utm_term = null,
        ?string $utm_content = null,
        ?array $tag_ids = null
    ) {
        $this->original_url = $original_url;
        $this->user_id = $user_id;
        $this->title = $title;
        $this->description = $description;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->starts_in = $starts_in;
        $this->custom_slug = $custom_slug;
        $this->click_limit = $click_limit;
        $this->utm_source = $utm_source;
        $this->utm_medium = $utm_medium;
        $this->utm_campaign = $utm_campaign;
        $this->utm_term = $utm_term;
        $this->utm_content = $utm_content;
        $this->tag_ids = $tag_ids;
    }

    /**
     * Build a CreateLinkDTO from a validated {@see \App\Http\Requests\CreateLinkRequest}.
     *
     * Reads fields from the request after Laravel's form-request validation has already
     * run, so all type constraints and business rules are guaranteed. `user_id` is
     * resolved from the currently authenticated API guard. Empty string UTM parameters
     * are coerced to null so the service layer can omit them from persistence cleanly.
     * `click_limit`, when present and non-empty, is cast to int.
     *
     * @param  Request  $request  Validated HTTP request from {@see \App\Http\Requests\CreateLinkRequest}.
     * @return self Immutable DTO ready for {@see \App\Services\Links\LinkService::createLink()}.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            original_url: $request->input('original_url'),
            user_id: Auth::id(),
            title: $request->input('title'),
            description: $request->input('description'),
            expires_at: $request->input('expires_at'),
            is_active: $request->boolean('is_active', true),
            starts_in: $request->input('starts_in'),
            custom_slug: $request->input('custom_slug'),
            click_limit: $request->input('click_limit') ? (int) $request->input('click_limit') : null,
            utm_source: $request->input('utm_source') ?: null,
            utm_medium: $request->input('utm_medium') ?: null,
            utm_campaign: $request->input('utm_campaign') ?: null,
            utm_term: $request->input('utm_term') ?: null,
            utm_content: $request->input('utm_content') ?: null,
            tag_ids: $request->has('tag_ids')
                ? array_map('intval', $request->input('tag_ids', []))
                : null
        );
    }

    /**
     * Serialize the DTO to an array suitable for Eloquent mass-assignment.
     *
     * Null values are stripped via `array_filter` so optional fields that were not
     * provided do not overwrite model defaults. `custom_slug` is remapped to the
     * `slug` key expected by the `links` table; when null, the service layer generates
     * a unique slug before calling {@see \App\Repositories\LinkRepository::create()}.
     *
     * `tag_ids` is deliberately excluded — tags are a many-to-many relation
     * (`links.tags()`), not a `links` table column, so including it here would
     * either be dropped silently by Eloquent's mass-assignment or throw. The
     * service layer reads `$linkDTO->tag_ids` directly and syncs it via
     * `$link->tags()->sync()` after the row is created.
     *
     * @return array<string, mixed> Associative array with non-null link attributes.
     */
    public function toArray(): array
    {
        return array_filter([
            'original_url' => $this->original_url,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'starts_in' => $this->starts_in,
            'slug' => $this->custom_slug, // Será gerado se não fornecido
            'click_limit' => $this->click_limit,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_term' => $this->utm_term,
            'utm_content' => $this->utm_content,
        ], fn ($value) => $value !== null);
    }

    /**
     * Check whether the stored URL passes PHP's built-in URL filter.
     *
     * Note: this is a lightweight sanity check only. Authoritative validation is
     * performed by {@see \App\Http\Requests\CreateLinkRequest} before this DTO is built.
     *
     * @return bool True if `original_url` passes `FILTER_VALIDATE_URL`.
     */
    public function isValidUrl(): bool
    {
        return filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }
}
