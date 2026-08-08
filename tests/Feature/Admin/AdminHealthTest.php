<?php

namespace Tests\Feature\Admin;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * getHealth: contagens de links por estado, failed_jobs por janela e
 * distribuição de quality_tier dos cliques (7d), sempre sem demo.
 * queue_depth não é assertado em valor (driver de fila do teste é sync) —
 * só que a chave existe.
 */
class AdminHealthTest extends TestCase
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

    public function test_health_reports_link_states_and_quality_tiers(): void
    {
        $user = User::factory()->create();
        $active = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'is_active' => true]);
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'is_active' => false]);
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'is_active' => true, 'health_status' => 'error']);
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => true, 'is_active' => true]); // fora

        Click::factory()->create(['link_id' => $active->id, 'quality_tier' => 'organic', 'created_at' => now()->subDay()]);
        Click::factory()->create(['link_id' => $active->id, 'quality_tier' => 'organic', 'created_at' => now()->subDay()]);
        Click::factory()->create(['link_id' => $active->id, 'quality_tier' => 'suspicious', 'created_at' => now()->subDay()]);

        $json = $this->getJson('/api/admin/health', $this->auth())->assertOk()->json('data');

        $this->assertSame(2, $json['links']['active']);
        $this->assertSame(1, $json['links']['inactive']);
        $this->assertSame(1, $json['links']['broken']);
        $this->assertArrayHasKey('queue_depth', $json);

        $tiers = collect($json['quality_tiers_7d'])->pluck('clicks', 'tier');
        $this->assertSame(2, (int) $tiers['organic']);
        $this->assertSame(1, (int) $tiers['suspicious']);
    }

    public function test_health_counts_failed_jobs_by_window(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a1', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours(2)],
            ['uuid' => 'a2', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(3)],
            ['uuid' => 'a3', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(30)],
        ]);

        $json = $this->getJson('/api/admin/health', $this->auth())->json('data');

        $this->assertSame(1, $json['failed_jobs_24h']);
        $this->assertSame(2, $json['failed_jobs_7d']);
    }
}
