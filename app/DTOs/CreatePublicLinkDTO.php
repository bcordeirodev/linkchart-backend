<?php

namespace App\DTOs;

use App\Http\Requests\CreatePublicLinkRequest;

/**
 * Input DTO for public link creation (POST /api/public/shorten).
 *
 * Built by {@see \App\Http\Requests\CreatePublicLinkRequest} via the static factory
 * {@see self::fromRequest()}, then consumed by
 * {@see \App\Http\Controllers\Links\PublicLinkController}. Unlike {@see CreateLinkDTO},
 * `is_active` is always true. `user_id` is null for guests; if a valid JWT token is
 * present the controller resolves it and passes it to `fromRequest()` so the link is
 * owned by that user. Rate-limited at 10 requests/minute per IP via the
 * `public-shorten` limiter.
 *
 * The object is immutable from construction (PHP 8.2 constructor promotion with
 * `readonly`). Validation is enforced by the form request before instantiation.
 */
class CreatePublicLinkDTO
{
    /**
     * @param  string  $original_url  Destination URL; must be a valid URL (validated upstream).
     * @param  string|null  $title  Optional human-readable label.
     * @param  string|null  $slug  Desired slug from `custom_slug` field; null triggers auto-generation.
     * @param  bool  $is_active  Always true for public links; kept for interface symmetry.
     * @param  int|null  $user_id  Authenticated user's ID if present; null for guests.
     */
    public function __construct(
        public readonly string $original_url,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
        public readonly bool $is_active = true,
        public readonly ?int $user_id = null
    ) {}

    /**
     * Build a CreatePublicLinkDTO from a validated {@see CreatePublicLinkRequest}.
     *
     * Reads `original_url` and `title` from the validated payload; maps `custom_slug`
     * to the `slug` property. `is_active` is hard-coded to true. `user_id` is null for
     * guests; pass the authenticated user's ID to associate the link with that account.
     *
     * @param  CreatePublicLinkRequest  $request  Validated HTTP request.
     * @param  int|null  $userId  Authenticated user's ID, or null for anonymous creation.
     * @return self Immutable DTO ready for controller use.
     */
    public static function fromRequest(CreatePublicLinkRequest $request, ?int $userId = null): self
    {
        return new self(
            original_url: $request->validated('original_url'),
            title: $request->validated('title'),
            slug: $request->validated('custom_slug'),
            is_active: true,
            user_id: $userId,
        );
    }

    /**
     * Serialize the DTO to an array for `Link::create()` mass assignment.
     *
     * Unlike {@see CreateLinkDTO::toArray()}, null values are NOT stripped.
     * The payload flows through LinkService::createPublicLink() into
     * LinkRepository::create() (Eloquent). `clicks` and the timestamps are
     * intentionally absent: they are not fillable on the Link model — the
     * database defaults `clicks` to 0 and Eloquent manages the timestamps.
     *
     * @return array<string, mixed> Column map for the `links` table.
     */
    public function toArray(): array
    {
        return [
            'original_url' => $this->original_url,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id,
        ];
    }

    /**
     * Check whether the stored URL passes PHP's built-in URL filter.
     *
     * @return bool True if `original_url` passes `FILTER_VALIDATE_URL`.
     */
    public function isValidUrl(): bool
    {
        return filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check whether this DTO contains the minimum data required to create a link.
     *
     * Returns true only when `original_url` is non-empty and passes URL validation.
     * Used as a guard in the controller before delegating to the service layer.
     *
     * @return bool True when the DTO holds a valid, non-empty destination URL.
     */
    public function hasValidData(): bool
    {
        return ! empty($this->original_url) && $this->isValidUrl();
    }
}
