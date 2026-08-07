<?php

namespace Tests\Feature\Admin;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getUsers: paginação, busca case-insensitive por nome/email, sort
 * whitelisted, contagens agregadas SEM links demo, contas demo fora.
 */
class AdminUsersListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $jwt;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'name' => 'Root Admin',
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

    public function test_excludes_demo_accounts_and_counts_only_non_demo_links(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        User::factory()->create(['id' => User::DEMO_ACCOUNT_IDS[0], 'name' => 'Demo']);

        Link::factory()->create(['user_id' => $ana->id, 'is_demo' => false, 'clicks' => 100]);
        Link::factory()->create(['user_id' => $ana->id, 'is_demo' => true, 'clicks' => 1247]);

        $json = $this->getJson('/api/admin/users', $this->auth())->assertOk()->json('data');

        // admin + ana; a conta 40 não aparece.
        $this->assertSame(2, $json['meta']['total']);

        $row = collect($json['items'])->firstWhere('email', 'ana@example.com');
        $this->assertSame(1, (int) $row['links_count']);
        $this->assertSame(100, (int) $row['total_clicks']);
    }

    public function test_search_matches_name_and_email_case_insensitive(): void
    {
        User::factory()->create(['name' => 'Beatriz Lima', 'email' => 'bia@example.com']);
        User::factory()->create(['name' => 'Carlos', 'email' => 'carlos@example.com']);

        $json = $this->getJson('/api/admin/users?q=BEATRIZ', $this->auth())->json('data');
        $this->assertSame(1, $json['meta']['total']);

        $json = $this->getJson('/api/admin/users?q=bia@', $this->auth())->json('data');
        $this->assertSame(1, $json['meta']['total']);
    }

    public function test_sorts_by_clicks_desc(): void
    {
        $low = User::factory()->create(['name' => 'Low']);
        $high = User::factory()->create(['name' => 'High']);
        Link::factory()->create(['user_id' => $low->id, 'is_demo' => false, 'clicks' => 1]);
        Link::factory()->create(['user_id' => $high->id, 'is_demo' => false, 'clicks' => 999]);

        $json = $this->getJson('/api/admin/users?sort=clicks&order=desc', $this->auth())->json('data');

        $this->assertSame('High', $json['items'][0]['name']);
    }

    public function test_rejects_non_whitelisted_sort(): void
    {
        $this->getJson('/api/admin/users?sort=password', $this->auth())->assertStatus(422);
    }

    public function test_paginates(): void
    {
        User::factory()->count(30)->create();

        $json = $this->getJson('/api/admin/users?per_page=10&page=2', $this->auth())->json('data');

        $this->assertCount(10, $json['items']);
        $this->assertSame(2, $json['meta']['current_page']);
        $this->assertSame(31, $json['meta']['total']); // 30 + admin
    }
}
