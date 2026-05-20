<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Analytics\AnalyticsFilters;
use Illuminate\Http\Request;
use Tests\TestCase;

class AnalyticsFiltersTest extends TestCase
{
    public function test_defaults_are_null_and_false(): void
    {
        $filters = new AnalyticsFilters;

        $this->assertNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
        $this->assertFalse($filters->excludeBots);
    }

    public function test_from_request_parses_params(): void
    {
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'exclude_bots' => 'true',
        ]);

        $filters = AnalyticsFilters::fromRequest($request);

        $this->assertEquals('2026-01-01', $filters->dateFrom?->toDateString());
        $this->assertEquals('2026-01-31', $filters->dateTo?->toDateString());
        $this->assertTrue($filters->excludeBots);
    }

    public function test_from_request_with_no_params_uses_defaults(): void
    {
        $request = Request::create('/', 'GET', []);

        $filters = AnalyticsFilters::fromRequest($request);

        $this->assertNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
        $this->assertFalse($filters->excludeBots);
    }

    public function test_apply_to_query_adds_date_and_bot_constraints(): void
    {
        $filters = AnalyticsFilters::fromRequest(
            Request::create('/', 'GET', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'exclude_bots' => 'true',
            ])
        );

        // verify applyToQuery returns a builder (smoke-test only; SQL tested in feature tests)
        $query = \App\Models\Click::query();
        $result = $filters->applyToQuery($query);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }
}
