<?php

namespace Tests\Feature;

use App\Models\Click;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class PublicAnalyticsTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    public function test_basic_analytics_returns_browser_breakdown(): void
    {
        $link = $this->makeLink(['clicks' => 2]);

        Click::factory()->create([
            'link_id' => $link->id,
            'browser' => 'Chrome',
            'day_of_week' => 1,
        ]);
        Click::factory()->create([
            'link_id' => $link->id,
            'browser' => 'Safari',
            'day_of_week' => 3,
        ]);

        $response = $this->getJson('/api/public/analytics/'.$link->slug);

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_analytics', true);

        $browsers = $response->json('data.charts.audience.browser_breakdown');
        $this->assertNotNull($browsers, 'browser_breakdown deve estar presente em charts.audience');
        $this->assertCount(2, $browsers);

        $names = collect($browsers)->pluck('browser')->all();
        $this->assertContains('Chrome', $names);
        $this->assertContains('Safari', $names);
    }

    public function test_basic_analytics_returns_clicks_by_day_of_week_with_7_entries(): void
    {
        $link = $this->makeLink(['clicks' => 3]);

        Click::factory()->create(['link_id' => $link->id, 'day_of_week' => 1]);
        Click::factory()->create(['link_id' => $link->id, 'day_of_week' => 1]);
        Click::factory()->create(['link_id' => $link->id, 'day_of_week' => 5]);

        $response = $this->getJson('/api/public/analytics/'.$link->slug);

        $response->assertStatus(200);

        $dowData = $response->json('data.charts.temporal.clicks_by_day_of_week');
        $this->assertNotNull($dowData, 'clicks_by_day_of_week deve estar presente em charts.temporal');
        $this->assertCount(7, $dowData, 'Deve retornar exatamente 7 entradas (Dom-Sáb)');

        $monday = collect($dowData)->firstWhere('day', 1);
        $this->assertSame(2, $monday['clicks'], 'Segunda-feira deve ter 2 cliques');

        $tuesday = collect($dowData)->firstWhere('day', 2);
        $this->assertSame(0, $tuesday['clicks'], 'Terça-feira deve ter 0 cliques');
    }

    public function test_basic_analytics_returns_200_for_active_link_without_clicks(): void
    {
        $link = $this->makeLink(['clicks' => 0]);

        $response = $this->getJson('/api/public/analytics/'.$link->slug);

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_analytics', false);
        $response->assertJsonMissing(['charts']);
    }
}
