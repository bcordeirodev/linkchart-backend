<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Click;
use App\Services\Links\LinkTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Integration coverage for the full 3-phase click enrichment pipeline.
 *
 * Verifies that Phase 1 (navigation context, accept_language, ch_* headers),
 * Phase 2 (viral_rank, rendering_engine, connection_type, is_holiday, season),
 * and Phase 3 (quality_score, quality_tier, fingerprint_score) fields are written
 * to the clicks table by registrarCliqueFromPayload.
 *
 * Each test exercises a different enrichment path so gaps in DB coverage
 * surface as test failures rather than silent NULLs in production.
 */
class LinkTrackingCoverageTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    /**
     * Default payload covering all Phase 1 fields.
     */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'ip' => '8.8.8.8',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'referer' => null,
            'accept_language' => 'pt-BR,pt;q=0.9,en;q=0.8',
            'query_params' => [],
            'http_response_ms' => 55.0,
            'sec_fetch_site' => 'none',
            'sec_fetch_mode' => 'navigate',
            'sec_fetch_dest' => 'document',
            'ch_platform' => 'Windows',
            'ch_is_mobile' => false,
            'save_data' => false,
            'server_protocol' => 'HTTP/2',
            'request_id' => 'req_coverage_test',
        ], $overrides);
    }

    private function runJob(int $linkId, array $overrides = []): Click
    {
        (new ProcessLinkClickJob($linkId, $this->fullPayload($overrides)))
            ->handle(app(LinkTrackingService::class));

        return Click::where('link_id', $linkId)->latest()->first();
    }

    // -----------------------------------------------------------------------
    // Phase 1 — navigation context stored in the clicks row
    // -----------------------------------------------------------------------

    /**
     * navigation_context, fetch_dest, ch_platform, ch_is_mobile, is_data_saver,
     * http_protocol are all written to the click row via enrichNavigationContext.
     */
    public function test_phase1_navigation_fields_are_written_to_click_row(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'sec_fetch_site' => 'none',
            'sec_fetch_mode' => 'navigate',
            'sec_fetch_dest' => 'document',
            'ch_platform' => 'macOS',
            'ch_is_mobile' => false,
            'save_data' => true,
            'server_protocol' => 'HTTP/2',
        ]);

        $this->assertNotNull($click, 'click row must be created');
        $this->assertSame('browser_direct', $click->navigation_context);
        $this->assertSame('document', $click->fetch_dest);
        $this->assertSame('macOS', $click->ch_platform);
        $this->assertFalse((bool) $click->ch_is_mobile);
        $this->assertTrue((bool) $click->is_data_saver);
        $this->assertSame('HTTP/2', $click->http_protocol);
    }

    /**
     * in_app_webview context: cross-site + no-cors = social app browser.
     */
    public function test_phase1_in_app_webview_context_is_stored(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'sec_fetch_site' => 'cross-site',
            'sec_fetch_mode' => 'no-cors',
            'sec_fetch_dest' => 'empty',
            'ch_platform' => null,
            'ch_is_mobile' => null,
            'save_data' => false,
            'server_protocol' => null,
        ]);

        $this->assertSame('in_app_webview', $click->navigation_context);
    }

    /**
     * api_programmatic context: no Sec-Fetch headers at all.
     */
    public function test_phase1_api_programmatic_context_when_sec_fetch_absent(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'sec_fetch_site' => null,
            'sec_fetch_mode' => null,
            'sec_fetch_dest' => null,
        ]);

        $this->assertSame('api_programmatic', $click->navigation_context);
    }

    /**
     * primary_language and language_region are parsed from accept_language and stored.
     */
    public function test_phase1_accept_language_is_stored_and_parsed(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'accept_language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ]);

        $this->assertSame('pt-BR,pt;q=0.9,en;q=0.8', $click->accept_language);
        $this->assertSame('pt', $click->primary_language);
        $this->assertSame('BR', $click->language_region);
    }

    /**
     * accept_language and response_time are written from the performance data group.
     */
    public function test_phase1_response_time_and_accept_language_are_written(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'http_response_ms' => 123.456,
            'accept_language' => 'en-US,en;q=0.5',
        ]);

        $this->assertNotNull($click->response_time);
        $this->assertEqualsWithDelta(123.456, (float) $click->response_time, 0.01);
        $this->assertSame('en-US,en;q=0.5', $click->accept_language);
    }

    // -----------------------------------------------------------------------
    // Phase 2 — contextual intelligence
    // -----------------------------------------------------------------------

    /**
     * viral_rank and seconds_since_last_click are written from ClickVelocityService.
     */
    public function test_phase2_viral_rank_is_written(): void
    {
        $prevTs = now()->timestamp - 10;

        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($prevTs) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('rawCommand')->once()->andReturn(null);
                $cb($pipe);

                return [60, true, 200, true, (string) $prevTs]; // c5=60 → viral
            });

        $link = $this->makeLink();
        $click = $this->runJob($link->id);

        $this->assertSame('viral', $click->viral_rank);
        $this->assertNotNull($click->seconds_since_last_click);
        $this->assertGreaterThanOrEqual(9, $click->seconds_since_last_click);
    }

    /**
     * rendering_engine is derived from browser and stored.
     */
    public function test_phase2_rendering_engine_is_written_for_chrome(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ]);

        $this->assertSame('blink', $click->rendering_engine);
    }

    /**
     * rendering_engine is 'gecko' for Firefox.
     */
    public function test_phase2_rendering_engine_is_gecko_for_firefox(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0',
        ]);

        $this->assertSame('gecko', $click->rendering_engine);
    }

    /**
     * connection_type defaults to 'unknown' when GeoIP provides no ISP data
     * (which is the case for GeoLite2-City without the ASN database).
     */
    public function test_phase2_connection_type_is_unknown_when_isp_unavailable(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id);

        // GeoIP free tier does not include ISP — always 'unknown'
        $this->assertSame('unknown', $click->connection_type);
    }

    /**
     * season is computed and stored for supported country codes.
     * Uses localhost IP (which returns null isoCode) — season should be null.
     */
    public function test_phase2_season_is_null_for_localhost_ip(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, ['ip' => '127.0.0.1']);

        $this->assertNull($click->season);
        $this->assertNull($click->is_holiday);
    }

    // -----------------------------------------------------------------------
    // Phase 3 — quality scoring
    // -----------------------------------------------------------------------

    /**
     * quality_score, quality_tier, and fingerprint_score are written by Phase 3.
     *
     * Asserts that all three Phase 3 fields are persisted (non-null) and that
     * quality_tier is consistent with quality_score according to the documented
     * thresholds (≥ 80 = organic, 50–79 = suspicious, < 50 = likely_fraud).
     * The exact score is not asserted because return-visitor detection and
     * Redis state can vary across test ordering.
     */
    public function test_phase3_quality_fields_are_written_for_organic_click(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'ip' => '203.0.113.99', // TEST-NET-3 — unique to this test
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'ch_platform' => 'Windows',
            'ch_is_mobile' => false,
            'sec_fetch_site' => 'none',
            'sec_fetch_mode' => 'navigate',
            'accept_language' => 'en-US',
        ]);

        $score = (int) $click->quality_score;

        $this->assertNotNull($click->quality_score, 'quality_score must be written');
        $this->assertNotNull($click->quality_tier, 'quality_tier must be written');
        $this->assertNotNull($click->fingerprint_score, 'fingerprint_score must be written');
        $this->assertContains($click->quality_tier, ['organic', 'suspicious', 'likely_fraud']);

        // Tier must be consistent with score — verify the boundary logic.
        $expectedTier = match (true) {
            $score >= 80 => 'organic',
            $score >= 50 => 'suspicious',
            default => 'likely_fraud',
        };
        $this->assertSame($expectedTier, $click->quality_tier, "quality_tier must match score {$score}");
    }

    /**
     * A bot user-agent results in quality_score=0 and likely_fraud tier.
     */
    public function test_phase3_bot_ua_yields_zero_score_and_likely_fraud(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ]);

        // is_bot=true → quality_score=0, quality_tier='likely_fraud'
        $this->assertSame(0, (int) $click->quality_score);
        $this->assertSame('likely_fraud', $click->quality_tier);
    }

    /**
     * api_programmatic context without ch_platform should score below 80 (suspicious/fraud).
     */
    public function test_phase3_api_programmatic_without_hints_is_downgraded(): void
    {
        $link = $this->makeLink();

        $click = $this->runJob($link->id, [
            'sec_fetch_site' => null,
            'sec_fetch_mode' => null,
            'sec_fetch_dest' => null,
            'ch_platform' => null,
            'ch_is_mobile' => null,
            'accept_language' => null,
        ]);

        // api_programmatic (-15) + no ch_platform (-10) + no accept_language (-5) = 70 → suspicious
        $this->assertLessThan(80, $click->quality_score);
        $this->assertNotSame('organic', $click->quality_tier);
    }

    /**
     * fingerprint_score increases when ch_is_mobile contradicts UA is_mobile flag.
     */
    public function test_phase3_ch_mobile_inconsistency_increases_fingerprint(): void
    {
        $link = $this->makeLink();

        // Desktop Chrome UA but ch_is_mobile=true (inconsistency)
        $click = $this->runJob($link->id, [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'ch_is_mobile' => true,
            'ch_platform' => 'Windows',
        ]);

        $this->assertGreaterThan(0, $click->fingerprint_score);
    }

    // -----------------------------------------------------------------------
    // Redis unavailability graceful degradation
    // -----------------------------------------------------------------------

    /**
     * When Redis throws, viral_rank defaults to 'cold' and the click is still saved.
     */
    public function test_phase2_redis_failure_degrades_gracefully_to_cold_rank(): void
    {
        Redis::shouldReceive('pipeline')
            ->once()
            ->andThrow(new \RuntimeException('Redis connection refused'));

        $link = $this->makeLink();
        $click = $this->runJob($link->id);

        $this->assertNotNull($click, 'click row must still be created when Redis is down');
        $this->assertSame('cold', $click->viral_rank);
        $this->assertNull($click->seconds_since_last_click);
    }

    // -----------------------------------------------------------------------
    // UTM capture via RedirectController payload keys
    // -----------------------------------------------------------------------

    /**
     * The 'query_params' key in the payload carries UTM params to the job.
     * Verifies the full chain from payload → LinkUtm row.
     */
    public function test_utm_query_params_are_captured_from_payload_key(): void
    {
        $link = $this->makeLink();

        (new ProcessLinkClickJob($link->id, $this->fullPayload([
            'query_params' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'brand2026',
                'utm_term' => 'linkcharts',
                'utm_content' => 'banner_a',
            ],
        ])))->handle(app(LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $utm = \App\Models\LinkUtm::where('click_id', $click->id)->first();

        $this->assertNotNull($utm);
        $this->assertSame('google', $utm->utm_source);
        $this->assertSame('cpc', $utm->utm_medium);
        $this->assertSame('brand2026', $utm->utm_campaign);
        $this->assertSame('linkcharts', $utm->utm_term);
        $this->assertSame('banner_a', $utm->utm_content);
    }

    // -----------------------------------------------------------------------
    // Temporal data written
    // -----------------------------------------------------------------------

    /**
     * hour_of_day, day_of_week, is_weekend, is_business_hours are all stored.
     */
    public function test_temporal_enrichment_fields_are_written(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id);

        $this->assertNotNull($click->hour_of_day);
        $this->assertNotNull($click->day_of_week);
        $this->assertNotNull($click->is_weekend);
        $this->assertNotNull($click->is_business_hours);
        $this->assertNotNull($click->day_of_month);
        $this->assertNotNull($click->month);
        $this->assertNotNull($click->year);
    }

    // -----------------------------------------------------------------------
    // Geographic data written
    // -----------------------------------------------------------------------

    /**
     * When IP is 127.0.0.1 (localhost), geo fields fall back to 'localhost'.
     * No ISO code means is_holiday and season are null.
     */
    public function test_localhost_ip_stores_localhost_geo_defaults(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id, ['ip' => '127.0.0.1']);

        $this->assertSame('localhost', $click->country);
        $this->assertSame('localhost', $click->city);
        $this->assertNull($click->iso_code);
        $this->assertNull($click->season);
        $this->assertNull($click->is_holiday);
    }

    // -----------------------------------------------------------------------
    // Behavior data written
    // -----------------------------------------------------------------------

    /**
     * is_return_visitor and session_clicks are written on every click.
     */
    public function test_behavior_fields_are_written(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id);

        $this->assertNotNull($click->is_return_visitor);
        $this->assertNotNull($click->session_clicks);
        $this->assertGreaterThanOrEqual(1, $click->session_clicks);
    }

    /**
     * social_platform is detected from Instagram referer.
     */
    public function test_social_platform_is_written_for_instagram_referer(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id, [
            'referer' => 'https://l.instagram.com/?u=https%3A%2F%2Fexample.com',
        ]);

        $this->assertSame('instagram', $click->social_platform);
    }

    /**
     * social_platform is null when referer is a non-social domain.
     */
    public function test_social_platform_is_null_for_non_social_referer(): void
    {
        $link = $this->makeLink();
        $click = $this->runJob($link->id, [
            'referer' => 'https://myblog.example.com/post',
        ]);

        $this->assertNull($click->social_platform);
    }
}
