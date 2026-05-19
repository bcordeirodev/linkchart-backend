<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Subdomain claimed by an authenticated user.
 *
 * One record per user (enforced by UNIQUE on user_id). A released subdomain
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
     * Find the active subdomain for a given user, served from cache.
     *
     * Returns null if the user has no active subdomain.
     * Cache key: subdomain:user:{id}, TTL: 600 s.
     */
    public static function findByUserCached(int $userId): ?self
    {
        return Cache::remember(
            self::userCacheKey($userId),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('user_id', $userId)->where('status', 'active')->first()
        );
    }

    /**
     * Cache key for lookup by subdomain label.
     */
    public static function subdomainCacheKey(string $subdomain): string
    {
        return 'subdomain:' . $subdomain;
    }

    /**
     * Cache key for lookup by user ID.
     */
    public static function userCacheKey(int $userId): string
    {
        return 'subdomain:user:' . $userId;
    }

    /**
     * Invalidate both cache entries for this record.
     */
    public function invalidateCache(): void
    {
        Cache::forget(self::subdomainCacheKey($this->subdomain));
        Cache::forget(self::userCacheKey($this->user_id));
    }

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
