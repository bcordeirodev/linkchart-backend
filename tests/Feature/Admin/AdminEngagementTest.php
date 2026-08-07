<?php

namespace Tests\Feature\Admin;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getEngagement: ativação (% com ≥1 link não-demo), retorno na 1ª semana
 * (proxy por criação de link), distribuição de links e WAU/MAU por
 * last_login_at.
 */
class AdminEngagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $jwt;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->forceFill(['is_admin' => true])->saveQuietly();
        $this->jwt = auth()->guard('api')->login($this->admin);
    }

    /** @return array{Authorization: string} */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->jwt}"];
    }

    public function test_activation_counts_users_with_at_least_one_non_demo_link(): void
    {
        $active = User::factory()->create();
        User::factory()->create(); // sem link
        Link::factory()->create(['user_id' => $active->id, 'is_demo' => false]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())
            ->assertOk()->json('data');

        // 3 usuários (admin incluso), 1 com link → 33.3
        $this->assertSame(33.3, $json['activation_pct']);
    }

    public function test_links_distribution_buckets(): void
    {
        $one = User::factory()->create();
        $many = User::factory()->create();
        Link::factory()->create(['user_id' => $one->id, 'is_demo' => false]);
        Link::factory()->count(7)->create(['user_id' => $many->id, 'is_demo' => false]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())->json('data');
        $buckets = collect($json['links_distribution'])->pluck('users', 'bucket');

        $this->assertSame(1, $buckets['0']);   // admin
        $this->assertSame(1, $buckets['1']);
        $this->assertSame(0, $buckets['2-5']);
        $this->assertSame(1, $buckets['6+']);
    }

    public function test_wau_mau_from_last_login_at(): void
    {
        $recent = User::factory()->create();
        $recent->forceFill(['last_login_at' => now()->subDays(2)])->saveQuietly();
        $stale = User::factory()->create();
        $stale->forceFill(['last_login_at' => now()->subDays(20)])->saveQuietly();
        User::factory()->create(); // nunca logou

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())->json('data');

        $this->assertSame(1, $json['wau']);
        $this->assertSame(2, $json['mau']);
        $this->assertNotNull($json['login_tracking_since']);
    }
}
