<?php

namespace App\DTOs;

use App\Http\Requests\CreatePublicLinkRequest;

/**
 * Input DTO for anonymous (unauthenticated) link creation (POST /api/public/shorten).
 *
 * Built by {@see \App\Http\Requests\CreatePublicLinkRequest} via the static factory
 * {@see self::fromRequest()}, then consumed by
 * {@see \App\Http\Controllers\Links\PublicLinkController}. Unlike {@see CreateLinkDTO},
 * there is no authenticated user: `user_id` is always null and `is_active` is always
 * true. Rate-limited at 10 requests/minute per IP via the `public-shorten` limiter.
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
     * @param  int|null  $user_id  Always null; public links are not owned by any user.
     */
    public function __construct(
        public readonly string $original_url,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
        public readonly bool $is_active = true,
        public readonly ?int $user_id = null // Sempre null para links públicos
    ) {}

    /**
     * Build a CreatePublicLinkDTO from a validated {@see CreatePublicLinkRequest}.
     *
     * Reads `original_url` and `title` from the validated payload; maps `custom_slug`
     * to the `slug` property. `is_active` is hard-coded to true and `user_id` to null
     * for all public links — these values are not read from the request.
     *
     * @param  CreatePublicLinkRequest  $request  Validated HTTP request.
     * @return self Immutable DTO ready for controller use.
     */
    public static function fromRequest(CreatePublicLinkRequest $request): self
    {
        return new self(
            original_url: $request->validated('original_url'),
            title: $request->validated('title'),
            slug: $request->validated('custom_slug'),
            is_active: true, // Links públicos sempre ativos inicialmente
            user_id: null // Links públicos não têm usuário
        );
    }

    /**
     * Serialize the DTO to an array for direct database insertion.
     *
     * Unlike {@see CreateLinkDTO::toArray()}, null values are NOT stripped — the
     * resulting array is used for a raw `DB::table()->insert()` call and must include
     * all columns. `clicks` is initialized to 0 and timestamps are set to `now()`.
     *
     * @return array<string, mixed> Full column map for the `links` table.
     */
    public function toArray(): array
    {
        return [
            'original_url' => $this->original_url,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id, // null
            'clicks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
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
