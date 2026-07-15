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

    protected function setUp(): void
    {
        parent::setUp();
        // Cache::remember caches null results too; without a flush, state left
        // over from a previous test in the same process can leak in.
        Cache::flush();
    }

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

    /** Lista todos os ativos do usuário em ordem de criação, com cache. */
    public function test_find_all_active_by_user_cached_returns_ordered_list(): void
    {
        $user = User::factory()->create();
        $second = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'beta']);
        $first = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        // An inactive record for the same user must never appear in the list.
        UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'gone']);

        $result = UserSubdomain::findAllActiveByUserCached($user->id);

        $this->assertCount(2, $result);
        $this->assertEquals([$second->id, $first->id], $result->pluck('id')->all());
    }

    /** Salvar qualquer subdomínio do usuário invalida a chave de lista. */
    public function test_saving_invalidates_list_cache(): void
    {
        $user = User::factory()->create();
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        // Warm up the list cache.
        UserSubdomain::findAllActiveByUserCached($user->id);
        $this->assertTrue(Cache::has("subdomain:user:{$user->id}:all"));

        $sub->update(['status' => 'inactive']);

        $this->assertFalse(Cache::has("subdomain:user:{$user->id}:all"));
    }

    /** Com 2 ativos, findByUserCached retorna o mais antigo (default determinístico). */
    public function test_find_by_user_cached_returns_oldest_active(): void
    {
        $user = User::factory()->create();
        $oldest = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'beta']);

        $result = UserSubdomain::findByUserCached($user->id);

        $this->assertInstanceOf(UserSubdomain::class, $result);
        $this->assertEquals($oldest->id, $result->id);
    }
}
