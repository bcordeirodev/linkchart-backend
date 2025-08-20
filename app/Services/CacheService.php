<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de Cache
 *
 * Centraliza todas as operações de cache do sistema,
 * seguindo o princípio SRP e DRY.
 */
class CacheService
{
    // Prefixos para diferentes tipos de cache
    const PREFIX_LINK = 'link:';
    const PREFIX_USER_LINKS = 'user_links:';
    const PREFIX_SLUG = 'slug:';
    const PREFIX_ANALYTICS = 'analytics:';

    // TTL (Time To Live) em segundos
    const TTL_LINK = 3600; // 1 hora
    const TTL_USER_LINKS = 1800; // 30 minutos
    const TTL_SLUG = 7200; // 2 horas
    const TTL_ANALYTICS = 900; // 15 minutos

    /**
     * Armazena um link no cache.
     */
    public function cacheLink(string $id, $link): void
    {
        try {
            $key = self::PREFIX_LINK . $id;
            Cache::put($key, $link, self::TTL_LINK);

            Log::info('Link cached', ['id' => $id, 'key' => $key]);
        } catch (\Exception $e) {
            Log::error('Failed to cache link', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recupera um link do cache.
     */
    public function getCachedLink(string $id)
    {
        try {
            $key = self::PREFIX_LINK . $id;
            $link = Cache::get($key);

            if ($link) {
                Log::info('Link retrieved from cache', ['id' => $id]);
            }

            return $link;
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cached link', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Armazena links do usuário no cache.
     */
    public function cacheUserLinks(int $userId, $links): void
    {
        try {
            $key = self::PREFIX_USER_LINKS . $userId;
            Cache::put($key, $links, self::TTL_USER_LINKS);

            Log::info('User links cached', ['user_id' => $userId, 'count' => count($links)]);
        } catch (\Exception $e) {
            Log::error('Failed to cache user links', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recupera links do usuário do cache.
     */
    public function getCachedUserLinks(int $userId)
    {
        try {
            $key = self::PREFIX_USER_LINKS . $userId;
            $links = Cache::get($key);

            if ($links) {
                Log::info('User links retrieved from cache', ['user_id' => $userId]);
            }

            return $links;
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cached user links', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Armazena mapeamento slug -> link no cache.
     */
    public function cacheSlugMapping(string $slug, $link): void
    {
        try {
            $key = self::PREFIX_SLUG . $slug;
            Cache::put($key, $link, self::TTL_SLUG);

            Log::info('Slug mapping cached', ['slug' => $slug]);
        } catch (\Exception $e) {
            Log::error('Failed to cache slug mapping', [
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recupera link pelo slug do cache.
     */
    public function getCachedLinkBySlug(string $slug)
    {
        try {
            $key = self::PREFIX_SLUG . $slug;
            $link = Cache::get($key);

            if ($link) {
                Log::info('Link retrieved from cache by slug', ['slug' => $slug]);
            }

            return $link;
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cached link by slug', [
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Invalida cache relacionado a um link.
     */
    public function invalidateLinkCache(string $id, int $userId, string $slug = null): void
    {
        try {
            $keys = [
                self::PREFIX_LINK . $id,
                self::PREFIX_USER_LINKS . $userId,
            ];

            if ($slug) {
                $keys[] = self::PREFIX_SLUG . $slug;
            }

            Cache::forget($keys);

            Log::info('Link cache invalidated', [
                'id' => $id,
                'user_id' => $userId,
                'slug' => $slug,
                'keys' => $keys
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate link cache', [
                'id' => $id,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalida todo o cache do usuário.
     */
    public function invalidateUserCache(int $userId): void
    {
        try {
            $pattern = self::PREFIX_USER_LINKS . $userId;
            Cache::forget($pattern);

            Log::info('User cache invalidated', ['user_id' => $userId]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate user cache', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Armazena dados de analytics no cache.
     */
    public function cacheAnalytics(string $key, $data): void
    {
        try {
            $cacheKey = self::PREFIX_ANALYTICS . $key;
            Cache::put($cacheKey, $data, self::TTL_ANALYTICS);

            Log::info('Analytics cached', ['key' => $key]);
        } catch (\Exception $e) {
            Log::error('Failed to cache analytics', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recupera dados de analytics do cache.
     */
    public function getCachedAnalytics(string $key)
    {
        try {
            $cacheKey = self::PREFIX_ANALYTICS . $key;
            return Cache::get($cacheKey);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cached analytics', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Limpa todo o cache do sistema.
     */
    public function clearAllCache(): bool
    {
        try {
            Cache::flush();
            Log::info('All cache cleared');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear all cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Obtém estatísticas do cache.
     */
    public function getCacheStats(): array
    {
        try {
            // Implementação básica - pode ser expandida
            return [
                'status' => 'active',
                'driver' => config('cache.default'),
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get cache stats', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
