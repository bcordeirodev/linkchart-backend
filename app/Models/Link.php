<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

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
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'starts_in' => 'datetime',
        'is_active' => 'boolean',
        'health_checked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function clicks()
    {
        return $this->hasMany(Click::class);
    }

    public function preview()
    {
        return $this->hasOne(LinkPreview::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    public function hasReachedClickLimit(): bool
    {
        return $this->click_limit !== null && $this->clicks >= $this->click_limit;
    }

    public function getRemainingClicks(): ?int
    {
        if ($this->click_limit === null) {
            return null; // Ilimitado
        }

        return max(0, $this->click_limit - $this->clicks);
    }

    public function getShortedUrl(): string
    {
        // URL encurtada aponta para o back-end (rota web com preview + redirect)
        // Esta rota serve HTML com Open Graph para preview em redes sociais
        $backendUrl = env('REDIRECT_URL', 'http://localhost:8000');

        return "{$backendUrl}/r/{$this->slug}";
    }

    public static function findActiveBySlugCached(string $slug): ?self
    {
        return Cache::remember(
            self::slugCacheKey($slug),
            self::CACHE_TTL_SECONDS,
            fn () => self::where('slug', $slug)->where('is_active', true)->first()
        );
    }

    public static function slugCacheKey(string $slug): string
    {
        return 'link:slug:'.$slug;
    }

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
