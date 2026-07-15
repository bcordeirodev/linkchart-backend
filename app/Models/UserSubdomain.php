<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Subdomain claimed by an authenticated user.
 *
 * A user may hold several active records simultaneously, up to
 * `config('app.max_subdomains_per_user')` (enforced in
 * {@see \App\Http\Controllers\Subdomain\SubdomainController}, not at the DB
 * level — the `UNIQUE(user_id)` constraint was dropped in the
 * `allow_multiple_user_subdomains` migration). A released subdomain
 * (status = inactive) can be reclaimed by another user because the DB uniqueness
 * constraint is a partial index on (subdomain) WHERE status = 'active' in PostgreSQL.
 * Application-level validation via Rule::unique also enforces this.
 *
 * @property int $id
 * @property int $user_id
 * @property string $subdomain Label only, e.g. "acme". Never includes the domain suffix.
 * @property string $status 'active' | 'inactive'
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User $user
 */
class UserSubdomain extends Model
{
    /** @use HasFactory<\Database\Factories\UserSubdomainFactory> */
    use HasFactory;

    public const CACHE_TTL_SECONDS = 600;

    protected $fillable = ['user_id', 'subdomain', 'status'];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    /**
     * Owning user (belongsTo User).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find an active subdomain by label, served from cache.
     *
     * Returns null if no active record exists for the given label.
     * Null results are cached for the full TTL (600 s). On first claim,
     * the saved hook invalidates the key so the next read re-queries.
     * Cache key: subdomain:{label}, TTL: 600 s.
     */
    public static function findActiveCached(string $subdomain): ?self
    {
        return Cache::remember(
            self::subdomainCacheKey($subdomain),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('subdomain', $subdomain)->where('status', 'active')->first()
        );
    }

    /**
     * Find the "default" active subdomain for a given user, served from cache.
     *
     * Returns the oldest active subdomain (lowest id) so the result is
     * deterministic even when the user holds several active subdomains — this
     * is the one used as the implicit default when a link is created without
     * an explicit `subdomain_id` (see {@see \App\Services\Links\LinkService}).
     * Returns null if the user has no active subdomain. Null results are
     * cached for the full TTL (600 s). The saved hook invalidates the key
     * when the user claims or releases a subdomain.
     * Cache key: subdomain:user:{id}, TTL: 600 s.
     */
    public static function findByUserCached(int $userId): ?self
    {
        return Cache::remember(
            self::userCacheKey($userId),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('user_id', $userId)->where('status', 'active')->orderBy('id')->first()
        );
    }

    /**
     * All active subdomains of a user, ordered from oldest to newest, served from cache.
     *
     * Backs the `/api/subdomains` list endpoint. Cache is invalidated on any
     * `saved`/`deleted` event for a record belonging to this user (see
     * {@see self::booted()}), so claiming or releasing a subdomain is
     * reflected immediately.
     * Cache key: subdomain:user:{id}:all, TTL: 600 s.
     *
     * @return Collection<int, self>
     */
    public static function findAllActiveByUserCached(int $userId): Collection
    {
        return Cache::remember(
            self::userListCacheKey($userId),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('user_id', $userId)->where('status', 'active')->orderBy('id')->get()
        );
    }

    /**
     * Cache key for lookup by subdomain label.
     */
    public static function subdomainCacheKey(string $subdomain): string
    {
        return 'subdomain:'.$subdomain;
    }

    /**
     * Cache key for the user's default (oldest active) subdomain lookup.
     */
    public static function userCacheKey(int $userId): string
    {
        return 'subdomain:user:'.$userId;
    }

    /**
     * Cache key for the user's full list of active subdomains.
     */
    public static function userListCacheKey(int $userId): string
    {
        return 'subdomain:user:'.$userId.':all';
    }

    /**
     * Invalidate every cache entry derived from this record.
     */
    public function invalidateCache(): void
    {
        Cache::forget(self::subdomainCacheKey($this->subdomain));
        Cache::forget(self::userCacheKey($this->user_id));
        Cache::forget(self::userListCacheKey($this->user_id));
    }

    /**
     * Invalidate both cache dimensions on any write.
     *
     * Runs on `saved` (insert + update) and `deleted` so that the redirect
     * hot path never serves a stale active/inactive state beyond the
     * current request cycle.
     */
    protected static function booted(): void
    {
        static::saved(function (self $sub): void {
            $sub->invalidateCache();
        });

        static::deleted(function (self $sub): void {
            $sub->invalidateCache();
        });
    }
}
