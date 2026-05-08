<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\LinkTrackingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for Phase 2 tracking enrichment methods in LinkTrackingService.
 */
class LinkTrackingPhase2Test extends TestCase
{
    /**
     * Invokes a private method on LinkTrackingService via reflection.
     *
     * @param  string  $method  Method name
     * @param  array  $args  Arguments to pass
     */
    private function call(string $method, array $args): mixed
    {
        $r = new ReflectionClass(LinkTrackingService::class);
        $m = $r->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke(new LinkTrackingService, ...$args);
    }

    // --- enrichSeason ---

    public function test_january_is_summer_in_brazil(): void
    {
        $dt = new \DateTime('2026-01-15');
        $this->assertEquals('summer', $this->call('enrichSeason', ['BR', $dt]));
    }

    public function test_january_is_winter_in_germany(): void
    {
        $dt = new \DateTime('2026-01-15');
        $this->assertEquals('winter', $this->call('enrichSeason', ['DE', $dt]));
    }

    public function test_july_is_winter_in_brazil(): void
    {
        $dt = new \DateTime('2026-07-10');
        $this->assertEquals('winter', $this->call('enrichSeason', ['BR', $dt]));
    }

    public function test_july_is_summer_in_us(): void
    {
        $dt = new \DateTime('2026-07-10');
        $this->assertEquals('summer', $this->call('enrichSeason', ['US', $dt]));
    }

    // --- classifyConnectionType ---

    public function test_amazon_isp_is_datacenter(): void
    {
        $this->assertEquals('datacenter', $this->call('classifyConnectionType', ['Amazon.com Inc.']));
    }

    public function test_claro_isp_is_mobile(): void
    {
        $this->assertEquals('mobile', $this->call('classifyConnectionType', ['Claro S.A.']));
    }

    public function test_university_isp_is_education(): void
    {
        $this->assertEquals('education', $this->call('classifyConnectionType', ['University of Campinas']));
    }

    public function test_null_isp_is_unknown(): void
    {
        $this->assertEquals('unknown', $this->call('classifyConnectionType', [null]));
    }

    public function test_unknown_isp_is_residential(): void
    {
        $this->assertEquals('residential', $this->call('classifyConnectionType', ['Telemar Norte Leste S.A.']));
    }

    // --- deriveRenderingEngine ---

    public function test_chrome_is_blink(): void
    {
        $this->assertEquals('blink', $this->call('deriveRenderingEngine', ['Chrome']));
    }

    public function test_firefox_is_gecko(): void
    {
        $this->assertEquals('gecko', $this->call('deriveRenderingEngine', ['Firefox']));
    }

    public function test_safari_is_webkit(): void
    {
        $this->assertEquals('webkit', $this->call('deriveRenderingEngine', ['Safari']));
    }

    public function test_unknown_browser_is_unknown_engine(): void
    {
        $this->assertEquals('unknown', $this->call('deriveRenderingEngine', ['SomeBot']));
    }

    public function test_edge_is_blink(): void
    {
        $this->assertEquals('blink', $this->call('deriveRenderingEngine', ['Edge']));
    }
}
