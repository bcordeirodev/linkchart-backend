<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\LinkTrackingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LinkTrackingPhase1Test extends TestCase
{
    private function call(string $method, array $args): mixed
    {
        $r = new ReflectionClass(LinkTrackingService::class);
        $m = $r->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(new LinkTrackingService(), ...$args);
    }

    // --- enrichNavigationContext ---

    public function test_browser_direct_from_sec_fetch_none(): void
    {
        $result = $this->call('enrichNavigationContext', [[
            'sec_fetch_site' => 'none', 'sec_fetch_mode' => 'navigate',
            'sec_fetch_dest' => 'document', 'ch_platform' => 'Windows',
            'ch_is_mobile' => false, 'save_data' => false, 'server_protocol' => 'HTTP/2',
        ]]);
        $this->assertEquals('browser_direct', $result['navigation_context']);
        $this->assertEquals('document', $result['fetch_dest']);
        $this->assertEquals('Windows', $result['ch_platform']);
        $this->assertFalse($result['is_data_saver']);
        $this->assertEquals('HTTP/2', $result['http_protocol']);
    }

    public function test_browser_referral_from_cross_site_navigate(): void
    {
        $result = $this->call('enrichNavigationContext', [[
            'sec_fetch_site' => 'cross-site', 'sec_fetch_mode' => 'navigate',
            'sec_fetch_dest' => 'document', 'ch_platform' => null,
            'ch_is_mobile' => null, 'save_data' => false, 'server_protocol' => null,
        ]]);
        $this->assertEquals('browser_referral', $result['navigation_context']);
    }

    public function test_in_app_webview_from_cross_site_no_cors(): void
    {
        $result = $this->call('enrichNavigationContext', [[
            'sec_fetch_site' => 'cross-site', 'sec_fetch_mode' => 'no-cors',
            'sec_fetch_dest' => null, 'ch_platform' => 'Android',
            'ch_is_mobile' => true, 'save_data' => true, 'server_protocol' => 'HTTP/1.1',
        ]]);
        $this->assertEquals('in_app_webview', $result['navigation_context']);
        $this->assertTrue($result['is_data_saver']);
    }

    public function test_api_programmatic_when_no_sec_fetch_headers(): void
    {
        $result = $this->call('enrichNavigationContext', [[
            'sec_fetch_site' => null, 'sec_fetch_mode' => null, 'sec_fetch_dest' => null,
            'ch_platform' => null, 'ch_is_mobile' => null, 'save_data' => false, 'server_protocol' => null,
        ]]);
        $this->assertEquals('api_programmatic', $result['navigation_context']);
    }

    public function test_data_saver_true_when_save_data_on(): void
    {
        $result = $this->call('enrichNavigationContext', [[
            'sec_fetch_site' => 'none', 'sec_fetch_mode' => 'navigate', 'sec_fetch_dest' => null,
            'ch_platform' => null, 'ch_is_mobile' => null, 'save_data' => true, 'server_protocol' => null,
        ]]);
        $this->assertTrue($result['is_data_saver']);
    }

    // --- parseAcceptLanguage ---

    public function test_parse_pt_br(): void
    {
        $result = $this->call('parseAcceptLanguage', ['pt-BR,pt;q=0.9,en;q=0.8']);
        $this->assertEquals('pt', $result['primary_language']);
        $this->assertEquals('BR', $result['language_region']);
    }

    public function test_parse_en_only(): void
    {
        $result = $this->call('parseAcceptLanguage', ['en']);
        $this->assertEquals('en', $result['primary_language']);
        $this->assertNull($result['language_region']);
    }

    public function test_parse_null_returns_nulls(): void
    {
        $result = $this->call('parseAcceptLanguage', [null]);
        $this->assertNull($result['primary_language']);
        $this->assertNull($result['language_region']);
    }
}
