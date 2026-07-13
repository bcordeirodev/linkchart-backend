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

    public function test_from_request_parses_categorical_dimensions(): void
    {
        $request = Request::create('/', 'GET', [
            'country' => 'Brazil',
            'device' => 'mobile',
            'channel' => 'social',
            'continent' => 'SA',
        ]);

        $filters = AnalyticsFilters::fromRequest($request);

        $this->assertSame('Brazil', $filters->country);
        $this->assertSame('mobile', $filters->device);
        $this->assertSame('social', $filters->channel);
        $this->assertSame('SA', $filters->continent);
    }

    public function test_cache_key_changes_with_each_dimension(): void
    {
        $base = new AnalyticsFilters;

        $this->assertNotSame($base->cacheKey(), (new AnalyticsFilters(country: 'Brazil'))->cacheKey());
        $this->assertNotSame($base->cacheKey(), (new AnalyticsFilters(device: 'mobile'))->cacheKey());
        $this->assertNotSame($base->cacheKey(), (new AnalyticsFilters(channel: 'social'))->cacheKey());
        $this->assertNotSame($base->cacheKey(), (new AnalyticsFilters(continent: 'SA'))->cacheKey());
    }

    public function test_channel_direct_also_matches_null_click_source(): void
    {
        $sql = (new AnalyticsFilters(channel: 'direct'))
            ->applyToQuery(\App\Models\Click::query())
            ->toSql();

        $this->assertStringContainsString('is null', strtolower($sql));
    }

    public function test_channel_other_than_direct_is_a_plain_equality(): void
    {
        $sql = (new AnalyticsFilters(channel: 'social'))
            ->applyToQuery(\App\Models\Click::query())
            ->toSql();

        $this->assertStringNotContainsString('is null', strtolower($sql));
        $this->assertStringContainsString('click_source', $sql);
    }

    public function test_apply_dimensions_omits_the_date_range(): void
    {
        $filters = new AnalyticsFilters(
            excludeBots: true,
            dateFrom: '2026-01-01',
            dateTo: '2026-01-31',
            device: 'mobile',
        );

        $sql = $filters->applyDimensions(\App\Models\Click::query())->toSql();

        $this->assertStringNotContainsString('created_at', $sql);
        $this->assertStringContainsString('device', $sql);
        $this->assertStringContainsString('is_bot', $sql);
    }

    public function test_prefix_qualifies_columns_for_joined_queries(): void
    {
        $sql = (new AnalyticsFilters(excludeBots: true, dateFrom: '2026-01-01'))
            ->applyToQuery(\App\Models\Click::query(), 'clicks.')
            ->toSql();

        $this->assertStringContainsString('"clicks"."created_at"', $sql);
        $this->assertStringContainsString('"clicks"."is_bot"', $sql);
    }
}
