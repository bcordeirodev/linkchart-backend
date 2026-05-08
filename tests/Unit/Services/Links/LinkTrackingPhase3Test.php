<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\LinkTrackingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the Phase 3 click quality scoring method.
 */
class LinkTrackingPhase3Test extends TestCase
{
    /**
     * Invokes a private method on LinkTrackingService via reflection.
     */
    private function call(string $method, array $args): mixed
    {
        $r = new ReflectionClass(LinkTrackingService::class);
        $m = $r->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke(new LinkTrackingService, ...$args);
    }

    /**
     * Returns a base set of clean-click fields for quality scoring tests.
     *
     * @return array<string, mixed>
     */
    private function baseFields(): array
    {
        return [
            'is_bot' => false,
            'connection_type' => 'residential',
            'navigation_context' => 'browser_direct',
            'seconds_since_last_click' => 30,
            'session_clicks' => 2,
            'ch_is_mobile' => false,
            'is_mobile' => false,
            'browser' => 'Chrome',
            'ch_platform' => 'Windows',
            'accept_language' => 'pt-BR',
            'is_return_visitor' => false,
        ];
    }

    public function test_organic_score_for_clean_click(): void
    {
        $result = $this->call('calculateQualityScore', [$this->baseFields()]);
        $this->assertGreaterThanOrEqual(80, $result['quality_score']);
        $this->assertEquals('organic', $result['quality_tier']);
        $this->assertEquals(0, $result['fingerprint_score']);
    }

    public function test_bot_click_results_in_zero_score(): void
    {
        $fields = array_merge($this->baseFields(), ['is_bot' => true]);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertEquals(0, $result['quality_score']);
        $this->assertEquals('likely_fraud', $result['quality_tier']);
    }

    public function test_datacenter_connection_reduces_score(): void
    {
        $fields = array_merge($this->baseFields(), ['connection_type' => 'datacenter']);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertLessThan(80, $result['quality_score']);
    }

    public function test_api_programmatic_without_hints_reduces_score(): void
    {
        $fields = array_merge($this->baseFields(), [
            'navigation_context' => 'api_programmatic',
            'ch_platform' => null,
        ]);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertLessThan(80, $result['quality_score']);
    }

    public function test_flood_pattern_reduces_score(): void
    {
        $fields = array_merge($this->baseFields(), [
            'seconds_since_last_click' => 1,
            'session_clicks' => 15,
        ]);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertLessThan(70, $result['quality_score']);
    }

    public function test_ch_mobile_inconsistency_increases_fingerprint_score(): void
    {
        $fields = array_merge($this->baseFields(), [
            'ch_is_mobile' => true,
            'is_mobile' => false,
        ]);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertGreaterThan(0, $result['fingerprint_score']);
    }

    public function test_tiers_are_set_correctly(): void
    {
        $fields = array_merge($this->baseFields(), [
            'connection_type' => 'datacenter',
            'navigation_context' => 'api_programmatic',
            'ch_platform' => null,
        ]);
        $result = $this->call('calculateQualityScore', [$fields]);
        $this->assertContains($result['quality_tier'], ['suspicious', 'likely_fraud']);
    }
}
