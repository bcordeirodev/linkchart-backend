<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

/**
 * Shortened link created by an authenticated user (or seeded as a demo).
 *
 * Central entity of the application. A link maps a unique slug to an original
 * URL and controls access via activity flag, date window, and click limit.
 * The public redirect hot path hits this model via findActiveBySlugCached();
 * the denormalised `clicks` counter is updated directly via DB::table()->increment()
 * (bypassing model events) to avoid cache churn.
 *
 * Fillable: id, slug, original_url, title, description, user_id, expires_at,
 *           starts_in, is_active, is_demo, clicks, click_limit, utm_source,
 *           utm_medium, utm_campaign, utm_term, utm_content, created_at,
 *           updated_at, health_status, health_checked_at, short_domain.
 *
 * Casts: expires_at → datetime, starts_in → datetime, is_active → boolean,
 *        is_demo → boolean, health_checked_at → datetime.
 *
 * Cache: findActiveBySlugCached() stores the model in the default cache driver
 *        (Redis in prod) for CACHE_TTL_SECONDS (600 s). Invalidation is handled
 *        by booted() on save of relevance fields and on delete.
 *
 * Observer: none (cache invalidation is self-registered in booted()).
 *
 * @property int $id
 * @property string $slug URL-safe unique identifier used in /r/{slug}.
 * @property string $original_url Destination URL (text, can be arbitrarily long).
 * @property string|null $title Optional human-readable title.
 * @property string|null $description Optional description; nullable text.
 * @property int|null $user_id Owner; nullable for public (anonymous) links.
 * @property \Illuminate\Support\Carbon|null $expires_at Hard expiry — link stops redirecting after this timestamp; null = never expires.
 * @property \Illuminate\Support\Carbon|null $starts_in Scheduled activation — link will not redirect before this timestamp; null = active from creation.
 * @property bool $is_active Soft on/off switch; false makes findActiveBySlugCached() return null.
 * @property bool $is_demo True for demo links seeded via SeedDemoLinkJob; excluded from quota calculations.
 * @property int $clicks Denormalised click counter; incremented via a raw DB query, NOT via model events.
 * @property int|null $click_limit Maximum number of clicks allowed; null = unlimited.
 * @property string|null $utm_source UTM source tag to append to the original URL on redirect.
 * @property string|null $utm_medium UTM medium tag.
 * @property string|null $utm_campaign UTM campaign tag.
 * @property string|null $utm_term UTM term tag.
 * @property string|null $utm_content UTM content tag.
 * @property string $health_status Enum-like: 'unknown' | 'healthy' | 'broken'. Default 'unknown'. Populated by FetchLinkPreviewJob.
 * @property \Illuminate\Support\Carbon|null $health_checked_at Timestamp of the most recent health check; null until first check.
 * @property string|null $short_domain Full hostname, e.g. "acme.linkcharts.com.br"; null uses the default redirect URL.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User|null        $user    Owning user; null for anonymous links.
 * @property-read \App\Models\LinkPreview|null $preview Open Graph / favicon metadata fetched asynchronously.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tag> $tags User-defined tags attached to this link.
 *
 * Note: the clicks() relationship method and the $clicks int column share the same name.
 * Always access the click-event collection via $link->clicks() (the relationship method)
 * to avoid ambiguity. The $clicks property above refers to the denormalised int counter.
 */
class Link extends Model
{
    use HasFactory, Notifiable;

    public const CACHE_TTL_SECONDS = 600;

    protected $fillable = [
        'id',
        'slug',
        'original_url',
        'title',
        'description',
        'user_id',
        'expires_at',
        'starts_in',
        'is_active',
        'is_demo',
        'clicks',
        'click_limit',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'created_at',
        'updated_at',
        'health_status',
        'health_checked_at',
        'short_domain',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'starts_in' => 'datetime',
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'health_checked_at' => 'datetime',
    ];

    /**
     * The user who owns this link (belongsTo User).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Individual click events recorded for this link (hasMany Click).
     */
    public function clicks()
    {
        return $this->hasMany(Click::class);
    }

    /**
     * Open Graph / favicon preview metadata for this link (hasOne LinkPreview).
     */
    public function preview()
    {
        return $this->hasOne(LinkPreview::class);
    }

    /**
     * User-defined tags attached to this link (belongsToMany Tag via link_tag).
     *
     * Not included in the cache-invalidation relevance list in booted() below —
     * tags do not affect the public redirect hot path, only the authenticated
     * dashboard view, so syncing tags never needs to bust the slug cache.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Returns true when expires_at is set and the current time is past it.
     *
     * A null expires_at means the link never expires and this method returns false.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    /**
     * Returns true when a click_limit is configured and the denormalised
     * clicks counter has reached or exceeded it.
     *
     * When click_limit is null the link is unlimited and this always returns false.
     */
    public function hasReachedClickLimit(): bool
    {
        return $this->click_limit !== null && $this->clicks >= $this->click_limit;
    }

    /**
     * Returns the full public short URL for this link.
     *
     * When short_domain is set (link was created after user activated a subdomain),
     * uses that hostname with the scheme derived from config('app.redirect_url') so
     * the custom-domain URL is always consistent with the default redirect base URL.
     * Falls back to the global redirect_url for links with no custom domain.
     */
    public function getShortedUrl(): string
    {
        $redirectUrl = config('app.redirect_url', 'http://localhost:8000');

        if ($this->short_domain) {
            $scheme = parse_url($redirectUrl, PHP_URL_SCHEME) ?? 'https';

            return "{$scheme}://{$this->short_domain}/{$this->slug}";
        }

        return $redirectUrl.'/'.$this->slug;
    }

    /**
     * Find an active Link by slug, served from the application cache.
     *
     * Cache key: static::slugCacheKey($slug) — see method below.
     * TTL: 600 seconds (10 minutes).
     * Storage: default cache driver (Redis in prod, array in tests).
     * Invalidation: managed by static::booted() — see below.
     *
     * Used by the public redirect hot path (RedirectController) to avoid
     * hitting Postgres on every redirect. Click peaks rely on this cache.
     *
     * @return self|null Null if no row matches OR the row exists but is not active.
     */
    public static function findActiveBySlugCached(string $slug): ?self
    {
        return Cache::remember(
            self::slugCacheKey($slug),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('slug', $slug)->where('is_active', true)->first()
        );
    }

    /**
     * Return the cache key used to store/retrieve a Link by its slug.
     *
     * Format: "link:slug:{slug}". Both findActiveBySlugCached() and booted()
     * must use this method to guarantee consistent key construction.
     */
    public static function slugCacheKey(string $slug): string
    {
        return 'link:slug:'.$slug;
    }

    /**
     * Register model events.
     *
     * Cache invalidation strategy: on save, only when one of the relevance fields
     * changed:
     *     ['slug', 'is_active', 'expires_at', 'starts_in', 'original_url', 'click_limit']
     *
     * This avoids spurious cache churn from unrelated saves (e.g. the
     * denormalised `clicks` column being incremented by LinkTrackingService
     * does NOT invalidate the slug cache — that increment uses a direct
     * DB::table->increment query that bypasses model events).
     *
     * When the slug itself changes, the previous-slug cache entry is also forgotten.
     *
     * On delete: always invalidates.
     *
     * Do NOT change the relevance field list without coordinated review —
     * it's a Hard Rule invariant of the consolidation plan.
     */
    protected static function booted(): void
    {
        static::saved(function (self $link): void {
            if (! $link->wasRecentlyCreated && ! $link->wasChanged(['slug', 'is_active', 'expires_at', 'starts_in', 'original_url', 'click_limit'])) {
                return;
            }

            Cache::forget(self::slugCacheKey($link->slug));

            $originalSlug = $link->getOriginal('slug');
            if ($originalSlug && $originalSlug !== $link->slug) {
                Cache::forget(self::slugCacheKey($originalSlug));
            }
        });

        static::deleted(function (self $link): void {
            Cache::forget(self::slugCacheKey($link->slug));
        });
    }
}
