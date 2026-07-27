<?php

namespace Tests\Feature\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use App\Models\User;
use App\Services\Analytics\DashboardAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterization test freezing the COMPLETE payload of
 * {@see DashboardAnalyticsService::getLinkDashboardAnalytics()}.
 *
 * The frontend dashboard consumes this payload directly, so its shape and
 * values must stay byte-identical while the duplicated aggregations
 * (geographic, audience, temporal) are replaced by delegation to the domain
 * analytics services. The dataset below deliberately exercises:
 *
 *  - several countries/states/cities with distinct click counts (no ordering
 *    ties — ORDER BY count DESC tie-breaking is not portable across drivers);
 *  - a localhost click and a NULL-country click (excluded from geo data);
 *  - a heatmap point with coordinates but NULL city (city-name fallback);
 *  - NULL device / browser / os (COALESCE 'Unknown' buckets and exclusions);
 *  - NULL hour_of_day / day_of_week (fallback to created_at extraction);
 *  - a day_of_week column value that DISAGREES with created_at (the stored
 *    column must win via COALESCE);
 *  - NULL accept_language plus an unmapped BCP-47 tag (passthrough);
 *  - NULL viral_rank (folded into 'cold') and NULL quality_tier ('unscored');
 *  - in-app-webview clicks on iOS and Android (social IAB card);
 *  - UTM rows including one with NULL utm_source (excluded);
 *  - a bot click and out-of-window clicks (exercised by the filtered payload);
 *  - a decoy link whose clicks must never leak into the aggregations.
 *
 * All response_time values are chosen so that every unrounded AVG() in the
 * payload is exactly representable, keeping floats byte-identical between
 * SQLite and PostgreSQL.
 *
 * The golden snapshots were captured from the pre-refactor implementation on
 * PostgreSQL (the production driver) and must NOT be regenerated from the
 * refactored code — that would defeat the purpose of the characterization.
 */
class DashboardCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    private DashboardAnalyticsService $service;

    /** Carbon locale ativo antes do teste, restaurado no tearDown. */
    private string $previousCarbonLocale;

    /** Coordinates/labels of the four heatmap location groups (G1–G4). */
    private const GEO_SAO_PAULO = [
        'country' => 'Brazil', 'iso_code' => 'BR', 'currency' => 'BRL',
        'state' => 'SP', 'state_name' => 'São Paulo', 'city' => 'São Paulo',
        'postal_code' => '01000-000', 'latitude' => -23.55, 'longitude' => -46.63,
        'timezone' => 'America/Sao_Paulo', 'continent' => 'SA',
    ];

    private const GEO_RIO = [
        'country' => 'Brazil', 'iso_code' => 'BR', 'currency' => 'BRL',
        'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'city' => 'Rio de Janeiro',
        'postal_code' => '20000-000', 'latitude' => -22.9, 'longitude' => -43.2,
        'timezone' => 'America/Sao_Paulo', 'continent' => 'SA',
    ];

    private const GEO_NEW_YORK = [
        'country' => 'United States', 'iso_code' => 'US', 'currency' => 'USD',
        'state' => 'NY', 'state_name' => 'New York', 'city' => 'New York',
        'postal_code' => '10001', 'latitude' => 40.71, 'longitude' => -74.0,
        'timezone' => 'America/New_York', 'continent' => 'NA',
    ];

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');

        // Ambiente hostil de propósito: o payload não pode depender do locale
        // global do processo (lição do refactor temporal de 2026-07-27, onde o
        // pt_BR vazado pelo CI deslocava semanas derivadas de startOfWeek()).
        $this->previousCarbonLocale = Carbon::getLocale();
        Carbon::setLocale('pt_BR');

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create([
            'user_id' => $this->user->id,
            'slug' => 'dash-char',
            'title' => 'Dashboard Characterization Link',
            'original_url' => 'https://example.com/target',
            'description' => null,
            'is_active' => true,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->service = app(DashboardAnalyticsService::class);
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setLocale($this->previousCarbonLocale);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Creates a click with every analytics-relevant column explicitly pinned.
     *
     * The Click factory randomises country/city/device/browser/day_of_week,
     * which would make the frozen payload non-deterministic. This helper
     * neutralises every column the dashboard payload aggregates and lets each
     * row override only what it needs.
     *
     * @param  array<string, mixed>  $overrides  Column overrides for this click.
     * @return Click The persisted click.
     */
    private function makeClick(array $overrides): Click
    {
        return Click::factory()->create(array_merge([
            'link_id' => $this->link->id,
            'ip' => '10.0.0.250',
            'user_agent' => 'UA-test',
            'referer' => null,
            'country' => null,
            'city' => null,
            'state' => null,
            'state_name' => null,
            'postal_code' => null,
            'iso_code' => null,
            'currency' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'continent' => null,
            'device' => null,
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'accept_language' => null,
            'response_time' => null,
            'hour_of_day' => null,
            'day_of_week' => null,
            'is_weekend' => false,
            'is_business_hours' => false,
            'is_mobile' => false,
            'is_bot' => false,
            'session_clicks' => 1,
            'viral_rank' => null,
            'quality_tier' => null,
            'navigation_context' => null,
            'created_at' => '2026-07-01 00:00:00',
        ], $overrides));
    }

    /**
     * Seeds the full characterization dataset (15 clicks + UTM rows) plus a
     * decoy click on another link (must be excluded by link scoping).
     *
     * Click counts per group are strictly distinct within every ORDER BY
     * count DESC aggregation — both for the unfiltered payload and for the
     * filtered one (exclude_bots + 2026-06-01→2026-07-15) — because tie
     * ordering is not portable across SQLite and PostgreSQL.
     */
    private function seedClicks(): void
    {
        // c1 — São Paulo, desktop/Chrome/Windows 11, business hours, viral, UTM newsletter.
        $c1 = $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.1', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'pt-BR,pt;q=0.9', 'response_time' => 100,
            'hour_of_day' => 9, 'day_of_week' => 2, 'is_business_hours' => true, 'session_clicks' => 2,
            'viral_rank' => 'viral', 'quality_tier' => 'organic', 'navigation_context' => 'navigate',
            'created_at' => '2026-07-01 09:15:00',
        ]);
        // c2 — São Paulo, mobile Android in-app webview (IAB Android), viral, UTM newsletter.
        $c2 = $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.2', 'device' => 'mobile', 'is_mobile' => true,
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Android', 'os_version' => '14',
            'accept_language' => 'pt-BR,pt;q=0.8', 'response_time' => 200,
            'hour_of_day' => 14, 'day_of_week' => 2,
            'viral_rank' => 'viral', 'quality_tier' => 'organic', 'navigation_context' => 'in_app_webview',
            'created_at' => '2026-07-01 14:30:00',
        ]);
        // c3 — Rio, mobile iOS in-app webview (IAB iOS), weekend, trending, UTM twitter.
        $c3 = $this->makeClick(self::GEO_RIO + [
            'ip' => '10.0.0.3', 'device' => 'mobile', 'is_mobile' => true,
            'browser' => 'Safari', 'browser_version' => '17.4', 'os' => 'iOS', 'os_version' => '17.4',
            'accept_language' => 'pt-BR', 'response_time' => 300,
            'hour_of_day' => 20, 'day_of_week' => 6, 'is_weekend' => true, 'session_clicks' => 3,
            'viral_rank' => 'trending', 'quality_tier' => 'suspicious', 'navigation_context' => 'in_app_webview',
            'created_at' => '2026-07-04 20:45:00',
        ]);
        // c4 — New York, desktop/Firefox/Windows 10, weekend, likely_fraud, UTM twitter.
        $c4 = $this->makeClick(self::GEO_NEW_YORK + [
            'ip' => '10.0.0.4', 'device' => 'desktop',
            'browser' => 'Firefox', 'browser_version' => '127.0', 'os' => 'Windows', 'os_version' => '10',
            'accept_language' => 'en-US,en;q=0.9', 'response_time' => 400,
            'hour_of_day' => 22, 'day_of_week' => 7, 'is_weekend' => true,
            'quality_tier' => 'likely_fraud',
            'created_at' => '2026-07-05 22:10:00',
        ]);
        // c5 — coordinates with NULL city (heatmap city fallback), NULL hour/dow
        // (COALESCE fallback: 18h, Wednesday), warming, UTM newsletter.
        $c5 = $this->makeClick([
            'ip' => '10.0.0.5', 'country' => 'United States', 'iso_code' => 'US', 'currency' => 'USD',
            'latitude' => 37.75, 'longitude' => -122.42,
            'timezone' => 'America/Los_Angeles', 'continent' => 'NA',
            'device' => 'tablet', 'browser' => 'Chrome', 'browser_version' => '126.0',
            'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'en-US', 'response_time' => 100,
            'viral_rank' => 'warming', 'navigation_context' => 'navigate',
            'created_at' => '2026-07-08 18:05:00',
        ]);
        // c6 — Portugal/Lisbon without coordinates (top lists only), NULL response_time.
        $c6 = $this->makeClick([
            'ip' => '10.0.0.6', 'country' => 'Portugal', 'iso_code' => 'PT', 'currency' => 'EUR',
            'city' => 'Lisbon', 'timezone' => 'Europe/Lisbon', 'continent' => 'EU',
            'device' => 'mobile', 'is_mobile' => true,
            'browser' => 'Safari', 'browser_version' => '17.4', 'os' => 'Android', 'os_version' => '14',
            'accept_language' => 'pt,en;q=0.5',
            'hour_of_day' => 7, 'day_of_week' => 4,
            'quality_tier' => 'organic',
            'created_at' => '2026-07-09 07:40:00',
        ]);
        // c7 — localhost click, everything else NULL, out of the filtered window.
        $this->makeClick([
            'ip' => '127.0.0.1', 'country' => 'localhost', 'response_time' => 100,
            'hour_of_day' => 9, 'day_of_week' => 5, 'is_business_hours' => true,
            'created_at' => '2026-05-25 09:00:00',
        ]);
        // c8 — NULL country, unmapped Accept-Language tag (passthrough), duplicate IP of c1.
        $this->makeClick([
            'ip' => '10.0.0.1', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'x-klingon', 'response_time' => 300,
            'hour_of_day' => 11, 'day_of_week' => 5, 'is_business_hours' => true,
            'navigation_context' => 'navigate',
            'created_at' => '2026-07-10 11:20:00',
        ]);
        // c9 — São Paulo, out of the filtered window (May), UTM blog.
        $c9 = $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.7', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'pt-BR', 'response_time' => 200,
            'hour_of_day' => 10, 'day_of_week' => 3, 'is_business_hours' => true, 'session_clicks' => 4,
            'quality_tier' => 'organic',
            'created_at' => '2026-05-20 10:00:00',
        ]);
        // c10 — bot click in São Paulo (included unfiltered, excluded by exclude_bots).
        $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.8', 'is_bot' => true,
            'os' => 'Windows', 'os_version' => '11',
            'hour_of_day' => 3, 'day_of_week' => 1,
            'created_at' => '2026-07-03 03:30:00',
        ]);
        // c11 — Rio, mobile iOS, weekend, trending.
        $this->makeClick(self::GEO_RIO + [
            'ip' => '10.0.0.9', 'device' => 'mobile', 'is_mobile' => true,
            'browser' => 'Safari', 'browser_version' => '17.4', 'os' => 'iOS', 'os_version' => '17.4',
            'accept_language' => 'pt', 'response_time' => 100,
            'hour_of_day' => 20, 'day_of_week' => 6, 'is_weekend' => true,
            'viral_rank' => 'trending', 'quality_tier' => 'suspicious',
            'created_at' => '2026-07-06 20:15:00',
        ]);
        // c12 — Rio, tablet iOS, weekend, NULL accept_language.
        $this->makeClick(self::GEO_RIO + [
            'ip' => '10.0.0.10', 'device' => 'tablet',
            'browser' => 'Safari', 'browser_version' => '17.4', 'os' => 'iOS', 'os_version' => '17.4',
            'response_time' => 150,
            'hour_of_day' => 15, 'day_of_week' => 7, 'is_weekend' => true,
            'created_at' => '2026-07-07 15:00:00',
        ]);
        // c13 — New York, most recent viral_rank click (defines current_rank),
        // NULL response_time, day_of_week column disagreeing with created_at (Sunday).
        $this->makeClick(self::GEO_NEW_YORK + [
            'ip' => '10.0.0.11', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'en-US',
            'hour_of_day' => 22, 'day_of_week' => 1,
            'viral_rank' => 'viral',
            'created_at' => '2026-07-12 22:30:00',
        ]);
        // c14 — São Paulo, June (inside the filtered window).
        $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.12', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'accept_language' => 'pt-BR', 'response_time' => 100,
            'hour_of_day' => 16, 'day_of_week' => 1,
            'quality_tier' => 'organic',
            'created_at' => '2026-06-15 16:10:00',
        ]);
        // c15 — São Paulo, June, NULL accept_language.
        $this->makeClick(self::GEO_SAO_PAULO + [
            'ip' => '10.0.0.13', 'device' => 'desktop',
            'browser' => 'Chrome', 'browser_version' => '126.0', 'os' => 'Windows', 'os_version' => '11',
            'response_time' => 50,
            'hour_of_day' => 16, 'day_of_week' => 2,
            'created_at' => '2026-06-16 16:20:00',
        ]);

        // UTM rows (utm_top_sources): newsletter ×3, twitter ×2, blog ×1 (out of
        // window) and one NULL utm_source row that must be excluded.
        LinkUtm::create(['click_id' => $c1->id, 'utm_source' => 'newsletter']);
        LinkUtm::create(['click_id' => $c2->id, 'utm_source' => 'newsletter']);
        LinkUtm::create(['click_id' => $c5->id, 'utm_source' => 'newsletter']);
        LinkUtm::create(['click_id' => $c3->id, 'utm_source' => 'twitter']);
        LinkUtm::create(['click_id' => $c4->id, 'utm_source' => 'twitter']);
        LinkUtm::create(['click_id' => $c9->id, 'utm_source' => 'blog']);
        LinkUtm::create(['click_id' => $c6->id, 'utm_source' => null]);

        // Decoy click on a different link — must not appear in any aggregate.
        $otherLink = Link::factory()->create(['user_id' => $this->user->id, 'slug' => 'dash-char-decoy']);
        $decoy = Click::factory()->create(array_merge(self::GEO_SAO_PAULO, [
            'link_id' => $otherLink->id,
            'ip' => '10.0.9.9', 'user_agent' => 'UA-test', 'referer' => null,
            'device' => 'mobile', 'browser' => 'Chrome', 'browser_version' => '126.0',
            'os' => 'Android', 'os_version' => '14',
            'accept_language' => 'pt-BR', 'response_time' => 999,
            'hour_of_day' => 9, 'day_of_week' => 4, 'is_weekend' => false,
            'is_business_hours' => true, 'is_mobile' => true, 'is_bot' => false,
            'session_clicks' => 9, 'viral_rank' => 'viral', 'quality_tier' => 'organic',
            'navigation_context' => 'in_app_webview',
            'created_at' => '2026-07-02 09:00:00',
        ]));
        LinkUtm::create(['click_id' => $decoy->id, 'utm_source' => 'newsletter']);
    }

    /**
     * Freezes the complete dashboard payload for a link with clicks (no filters).
     */
    public function test_dashboard_payload_is_frozen(): void
    {
        $this->seedClicks();

        $payload = $this->service->getLinkDashboardAnalytics($this->link->id);

        $this->assertGolden('dashboard_payload', $this->normalizeEnvironment($payload));
    }

    /**
     * Freezes the payload with active filters (exclude_bots + date range),
     * proving the filter state is propagated into every aggregation.
     */
    public function test_dashboard_payload_is_frozen_with_filters(): void
    {
        $this->seedClicks();

        $filters = new AnalyticsFilters(
            excludeBots: true,
            dateFrom: '2026-06-01 00:00:00',
            dateTo: '2026-07-15 00:00:00',
        );

        $payload = $this->service->getLinkDashboardAnalytics($this->link->id, $filters);

        $this->assertGolden('dashboard_payload_filtered', $this->normalizeEnvironment($payload));
    }

    /**
     * Freezes the payload for a link that exists but has zero clicks
     * (empty branches of every aggregation, 24×0 hour buckets etc.).
     */
    public function test_dashboard_payload_is_frozen_for_link_without_clicks(): void
    {
        $payload = $this->service->getLinkDashboardAnalytics($this->link->id);

        $this->assertGolden('dashboard_payload_empty', $this->normalizeEnvironment($payload));
    }

    /**
     * Freezes the empty-dashboard payload returned for a nonexistent link id.
     */
    public function test_dashboard_payload_is_frozen_for_missing_link(): void
    {
        $payload = $this->service->getLinkDashboardAnalytics(999999);

        $this->assertGolden('dashboard_payload_missing_link', $this->normalizeEnvironment($payload));
    }

    /**
     * Replaces environment-dependent leaves of link_info with pinned values,
     * asserting their real values first.
     *
     *  - id: PostgreSQL sequences are not rolled back by RefreshDatabase's
     *    transaction, so the autoincrement depends on which tests ran before.
     *  - short_url: depends on config('app.redirect_url') / APP env.
     *  - created_at: a Carbon instance — objects can never be assertSame'd
     *    against a snapshot literal, so it is serialised to a string.
     *
     * @param  array<string, mixed>  $payload  Raw service payload.
     * @return array<string, mixed> Payload safe to compare against the golden file.
     */
    private function normalizeEnvironment(array $payload): array
    {
        if (is_array($payload['link_info'] ?? null)) {
            $this->assertSame($this->link->id, $payload['link_info']['id']);
            $this->assertStringEndsWith('/'.$this->link->slug, $payload['link_info']['short_url']);

            $payload['link_info']['id'] = 1;
            $payload['link_info']['short_url'] = 'https://short.test/'.$this->link->slug;
            $payload['link_info']['created_at'] = $payload['link_info']['created_at']?->format('Y-m-d H:i:s');
        }

        return $payload;
    }

    /**
     * Pins the `percentage` leaves of audience browsers/operating_systems to 0.0.
     *
     * Known driver divergence in the PRE-refactor dashboard implementation:
     * on SQLite it hardcoded `percentage => 0.0` for these two distributions,
     * while on PostgreSQL it computes real percentages via window functions.
     * The golden snapshot is captured on PostgreSQL (production driver), so on
     * SQLite this neutralisation is applied to BOTH sides of the comparison:
     * percentages stay fully frozen on PostgreSQL, and every other leaf
     * (rows, ordering, versions, clicks) stays frozen on both drivers.
     *
     * @param  array<string, mixed>  $payload  Payload or golden snapshot.
     * @return array<string, mixed> Payload with neutralised percentage leaves.
     */
    private function neutralizeSqliteDriverGaps(array $payload): array
    {
        foreach (['browsers', 'operating_systems'] as $key) {
            if (! isset($payload['audience_data'][$key])) {
                continue;
            }

            $payload['audience_data'][$key] = array_map(function (array $row): array {
                $row['percentage'] = 0.0;

                return $row;
            }, $payload['audience_data'][$key]);
        }

        return $payload;
    }

    /**
     * Asserts the payload against a golden snapshot captured from the
     * pre-refactor implementation.
     *
     * Follows the repo golden-snapshot convention (see
     * AdvancedTemporalCharacterizationTest): a var_export'ed PHP array under
     * tests/Feature/__snapshots__/. It must NOT be regenerated from refactored
     * code — doing so would erase the frozen reference.
     *
     * @param  string  $name  Snapshot basename without extension.
     * @param  array<string, mixed>  $payload  Normalised payload to compare.
     */
    private function assertGolden(string $name, array $payload): void
    {
        $path = __DIR__.'/../__snapshots__/'.$name.'.php';

        $this->assertFileExists($path, 'Golden snapshot missing — it freezes the pre-refactor payload and must not be deleted.');

        $expected = require $path;

        if (DB::connection()->getDriverName() === 'sqlite') {
            $expected = $this->neutralizeSqliteDriverGaps($expected);
            $payload = $this->neutralizeSqliteDriverGaps($payload);
        }

        $this->assertSame($expected, $payload);
    }
}
