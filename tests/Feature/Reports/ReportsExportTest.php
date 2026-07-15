<?php

namespace Tests\Feature\Reports;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/export/clicks (streamed CSV export).
 *
 * LGPD constraint under test: the exported CSV must NEVER include the `ip`
 * column — clicks-list already enforces the same rule for the per-link
 * endpoint, this extends it to the aggregated multi-link export.
 */
class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Streams the CSV response body into a plain string for assertions.
     *
     * @param  \Illuminate\Testing\TestResponse  $response  Response from a streamed download endpoint.
     * @return string Full CSV body.
     */
    private function streamedContent($response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return ob_get_clean();
    }

    /** CSV tem header correto, uma linha por clique do usuário, e NUNCA contém a coluna ip. */
    public function test_export_streams_csv_without_ip(): void
    {
        $user = $this->makeVerifiedUser();
        $link = Link::factory()->create(['user_id' => $user->id, 'title' => 'Meu Link', 'slug' => 'meu-link']);

        Click::factory()->create([
            'link_id' => $link->id,
            'ip' => '203.0.113.42',
            'country' => 'Brazil',
            'city' => 'Sao Paulo',
            'device' => 'mobile',
            'browser' => 'Chrome',
        ]);

        $response = $this->actingAs($user, 'api')->get('/api/reports/export/clicks');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $this->streamedContent($response);
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertSame(
            'data,link,slug,pais,cidade,dispositivo,navegador,so,origem,contexto,qualidade',
            trim($lines[0])
        );
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Meu Link', $lines[1]);
        $this->assertStringNotContainsString('203.0.113.42', $csv);
        $this->assertStringNotContainsString('ip', strtolower($lines[0]));
    }

    /** Cliques de outros usuários e de links demo não aparecem. */
    public function test_export_scopes_to_owner(): void
    {
        $user = $this->makeVerifiedUser();
        $other = $this->makeVerifiedUser();

        $ownLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $demoLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);

        Click::factory()->create(['link_id' => $ownLink->id]);
        Click::factory()->create(['link_id' => $demoLink->id]);
        Click::factory()->create(['link_id' => $otherLink->id]);

        $response = $this->actingAs($user, 'api')->get('/api/reports/export/clicks');

        $csv = $this->streamedContent($response);
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertCount(2, $lines);
    }
}
