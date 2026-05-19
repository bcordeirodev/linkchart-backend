<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserSubdomainCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_active_cached_returns_model_when_active(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->create([
            'user_id' => $user->id,
            'subdomain' => 'acme',
            'status' => 'active',
        ]);

        $result = UserSubdomain::findActiveCached('acme');

        $this->assertInstanceOf(UserSubdomain::class, $result);
        $this->assertEquals('acme', $result->subdomain);
    }

    public function test_find_active_cached_returns_null_when_inactive(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->create([
            'user_id' => $user->id,
            'subdomain' => 'acme',
            'status' => 'inactive',
        ]);

        $result = UserSubdomain::findActiveCached('acme');

        $this->assertNull($result);
    }

    public function test_find_by_user_cached_returns_active_subdomain(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->create([
            'user_id' => $user->id,
            'subdomain' => 'acme',
            'status' => 'active',
        ]);

        $result = UserSubdomain::findByUserCached($user->id);

        $this->assertInstanceOf(UserSubdomain::class, $result);
        $this->assertEquals('acme', $result->subdomain);
    }

    public function test_find_by_user_cached_returns_null_when_user_has_no_active_subdomain(): void
    {
        $user = User::factory()->create();
        UserSubdomain::factory()->inactive()->create([
            'user_id' => $user->id,
            'subdomain' => 'acme',
        ]);

        $result = UserSubdomain::findByUserCached($user->id);

        $this->assertNull($result);
    }

    public function test_cache_is_invalidated_on_save(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create([
            'user_id' => $user->id,
            'subdomain' => 'acme',
            'status' => 'active',
        ]);

        // Warm up cache
        UserSubdomain::findActiveCached('acme');
        UserSubdomain::findByUserCached($user->id);

        // Both cache keys should exist
        $this->assertTrue(Cache::has(UserSubdomain::subdomainCacheKey('acme')));
        $this->assertTrue(Cache::has(UserSubdomain::userCacheKey($user->id)));

        // Trigger save event
        $sub->update(['status' => 'inactive']);

        $this->assertFalse(Cache::has(UserSubdomain::subdomainCacheKey('acme')));
        $this->assertFalse(Cache::has(UserSubdomain::userCacheKey($user->id)));
    }
}
