<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GeographicAnalyticsService filter support.
 *
 * Verifies that the geographic endpoint correctly applies continent and
 * date-range filters when computing country/state/city aggregations.
 */
class GeographicFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Clicks from other continents must be excluded when continent param is set.
     */
    public function test_continent_filter_scopes_results(): void
    {
        Click::factory()->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
            'latitude' => -23.5,
            'longitude' => -46.6,
        ]);
        Click::factory()->create([
            'link_id' => $this->link->id,
            'country' => 'Germany',
            'continent' => 'EU',
            'latitude' => 52.5,
            'longitude' => 13.4,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic?continent=SA");

        $response->assertOk();
        $countries = collect($response->json('data.top_countries'))->pluck('country');
        $this->assertContains('Brazil', $countries);
        $this->assertNotContains('Germany', $countries);
    }

    /**
     * Clicks before date_from must be excluded from geographic results.
     */
    public function test_date_from_scopes_geographic_data(): void
    {
        Click::factory()->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
            'created_at' => '2026-01-01',
        ]);
        Click::factory()->create([
            'link_id' => $this->link->id,
            'country' => 'Germany',
            'continent' => 'EU',
            'created_at' => '2026-03-01',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic?date_from=2026-03-01");

        $response->assertOk();
        $countries = collect($response->json('data.top_countries'))->pluck('country');
        $this->assertNotContains('Brazil', $countries);
    }

    /**
     * The `country` filter must scope the geographic payload to that country only,
     * and must not conflict with the DTO's continent handling.
     */
    public function test_country_filter_scopes_the_payload(): void
    {
        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
        ]);
        Click::factory()->count(7)->create([
            'link_id' => $this->link->id,
            'country' => 'United States',
            'continent' => 'NA',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic?country=Brazil");

        $response->assertOk();

        $countries = collect($response->json('data.top_countries'));
        $this->assertCount(1, $countries);
        $this->assertSame('Brazil', $countries->first()['country']);
    }

    /**
     * Without any filters all clicks must appear in geographic results.
     */
    public function test_no_filters_returns_all_data(): void
    {
        Click::factory()->count(4)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }
}
