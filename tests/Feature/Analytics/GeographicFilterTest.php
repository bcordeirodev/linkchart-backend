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

    /**
     * Regression test: the continents donut is the continent *selector* — the
     * frontend's ContinentBreakdown draws every continent and highlights the
     * active one via `activeContinentCode`. Applying the continent filter to
     * this breakdown collapses it to a single 100% slice and defeats that
     * highlight. `?continent=NA` must still return every continent (NA and
     * SA here), with percentages computed over the combined total.
     */
    public function test_continents_breakdown_ignores_the_continent_filter(): void
    {
        Click::factory()->count(10)->create([
            'link_id' => $this->link->id,
            'country' => 'United States',
            'continent' => 'NA',
        ]);
        Click::factory()->count(6)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic?continent=NA");

        $response->assertOk();

        $continents = collect($response->json('data.continents'))->keyBy('continent_code');
        $this->assertCount(2, $continents);

        $this->assertSame(10, $continents['NA']['clicks']);
        $this->assertSame(62.5, $continents['NA']['percentage']);

        $this->assertSame(6, $continents['SA']['clicks']);
        $this->assertSame(37.5, $continents['SA']['percentage']);
    }

    /**
     * The continents breakdown must still honour every other drill-down
     * dimension — it only special-cases `continent` itself. Filtering by
     * `device=mobile` must scope the continent split to mobile clicks only.
     */
    public function test_continents_breakdown_honours_other_dimensions(): void
    {
        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'country' => 'United States',
            'continent' => 'NA',
            'device' => 'mobile',
        ]);
        Click::factory()->count(9)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
            'device' => 'desktop',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/geographic?device=mobile");

        $response->assertOk();

        $continents = collect($response->json('data.continents'))->keyBy('continent_code');
        $this->assertCount(1, $continents);
        $this->assertSame(3, $continents['NA']['clicks']);
        // assertEquals, not assertSame: json_encode(100.0) serialises as `100`,
        // which json_decode() reads back as the int 100, not the float 100.0.
        $this->assertEquals(100.0, $continents['NA']['percentage']);
    }
}
