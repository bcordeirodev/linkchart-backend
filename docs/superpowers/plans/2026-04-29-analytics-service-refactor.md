# Analytics Service Refactor (Wave 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `LinkAnalyticsService` (1697 lines, 8 domains) into a `LinkAnalyticsOrchestrator` + 5 focused services + Insights strategy pattern + `UserAgentParser` support class.

**Architecture:** Each extracted service owns its query logic and public API. `LinkAnalyticsOrchestrator` is a thin fan-out that delegates to module services. `AnalyticsController` injects the orchestrator — its public method signatures stay identical so no frontend changes are needed. `$queryAfter` property anti-pattern replaced by `$since: ?\Carbon\Carbon` parameter threading in `DashboardAnalyticsService`.

**Tech Stack:** PHP 8.2, Laravel 12, PHPUnit (SQLite in-memory), PostgreSQL 15 (prod). All commands run inside Docker: `docker-compose exec app`.

---

## File Map

**Create:**
```
app/Services/Analytics/Support/UserAgentParser.php
app/Services/Analytics/DashboardAnalyticsService.php
app/Services/Analytics/GeographicAnalyticsService.php
app/Services/Analytics/TemporalAnalyticsService.php
app/Services/Analytics/AudienceAnalyticsService.php
app/Services/Analytics/Insights/InsightGeneratorInterface.php
app/Services/Analytics/Insights/InsightGeneratorRegistry.php
app/Services/Analytics/Insights/Generators/GeographicInsightGenerator.php
app/Services/Analytics/Insights/Generators/DeviceInsightGenerator.php
app/Services/Analytics/Insights/Generators/TemporalInsightGenerator.php
app/Services/Analytics/Insights/Generators/PerformanceInsightGenerator.php
app/Services/Analytics/Insights/Generators/DiversityInsightGenerator.php
app/Services/Analytics/Insights/Generators/SecurityInsightGenerator.php
app/Services/Analytics/Insights/Generators/EngagementInsightGenerator.php
app/Services/Analytics/Insights/Generators/RetentionInsightGenerator.php
app/Services/Analytics/InsightsAnalyticsService.php
app/Services/Analytics/LinkAnalyticsOrchestrator.php
app/Contracts/Analytics/DashboardAnalyticsInterface.php
app/Contracts/Analytics/GeographicAnalyticsInterface.php
app/Contracts/Analytics/TemporalAnalyticsInterface.php
app/Contracts/Analytics/AudienceAnalyticsInterface.php
app/Contracts/Analytics/InsightsAnalyticsInterface.php
tests/Feature/Analytics/AnalyticsStructureTest.php
```

**Modify:**
```
database/factories/ClickFactory.php           — day_of_week ISO 1-7
app/Http/Controllers/Analytics/AnalyticsController.php — inject orchestrator
app/Providers/AppServiceProvider.php           — bind interfaces
```

**Delete (Task 8 only, after full suite passes):**
```
app/Services/Analytics/LinkAnalyticsService.php
app/Services/Analytics/UserAgentAnalyticsService.php
```

---

### Task 1: Snapshot Tests Foundation

**Files:**
- Modify: `database/factories/ClickFactory.php:23`
- Create: `tests/Feature/Analytics/AnalyticsStructureTest.php`

- [ ] **Step 1: Fix ClickFactory day_of_week**

In `database/factories/ClickFactory.php` line 23, change:
```php
'day_of_week' => $this->faker->numberBetween(0, 6),
```
to:
```php
'day_of_week' => $this->faker->numberBetween(1, 7),
```

- [ ] **Step 2: Run existing suite — confirm no regressions**

```bash
docker-compose exec app ./vendor/bin/phpunit
```
Expected: all PASS.

- [ ] **Step 3: Create AnalyticsStructureTest**

Create `tests/Feature/Analytics/AnalyticsStructureTest.php`:
```php
<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Services\Analytics\LinkAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class AnalyticsStructureTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private function seedClicks(int $linkId, int $count = 3): void
    {
        Click::factory()->count($count)->create(['link_id' => $linkId]);
    }

    public function test_click_factory_produces_iso_day_of_week(): void
    {
        $link   = $this->makeLink();
        $clicks = Click::factory()->count(30)->create(['link_id' => $link->id]);

        foreach ($clicks as $click) {
            $this->assertGreaterThanOrEqual(1, $click->day_of_week);
            $this->assertLessThanOrEqual(7, $click->day_of_week);
        }
    }

    public function test_comprehensive_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getComprehensiveLinkAnalytics($link->id);

        $this->assertTrue($result['has_data']);
        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('geographic', $result);
        $this->assertArrayHasKey('temporal', $result);
        $this->assertArrayHasKey('audience', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('total_clicks', $result['overview']);
        $this->assertArrayHasKey('unique_visitors', $result['overview']);
    }

    public function test_dashboard_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('temporal_data', $result);
        $this->assertArrayHasKey('geographic_data', $result);
        $this->assertArrayHasKey('audience_data', $result);
        $this->assertArrayHasKey('total_clicks', $result['summary']);
    }

    public function test_dashboard_hours_filter_reduces_click_count(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subHours(2)]);
        Click::factory()->count(5)->create(['link_id' => $link->id, 'created_at' => now()->subDays(7)]);

        $allTime = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 0);
        $last24h = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 24);

        $this->assertSame(7, $allTime['summary']['total_clicks']);
        $this->assertSame(2, $last24h['summary']['total_clicks']);
    }

    public function test_geographic_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

        $this->assertArrayHasKey('top_countries', $result);
        $this->assertArrayHasKey('top_states', $result);
        $this->assertArrayHasKey('top_cities', $result);
    }

    public function test_temporal_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkTemporalAnalytics($link->id);

        $this->assertArrayHasKey('clicks_by_hour', $result);
        $this->assertArrayHasKey('clicks_by_day_of_week', $result);
        $this->assertCount(24, $result['clicks_by_hour']);
        $this->assertCount(7, $result['clicks_by_day_of_week']);
    }

    public function test_audience_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkAudienceAnalytics($link->id);

        $this->assertArrayHasKey('device_breakdown', $result);
        $this->assertArrayHasKey('browser_breakdown', $result);
        $this->assertArrayHasKey('os_breakdown', $result);
    }

    public function test_insights_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkInsightsAnalytics($link->id);

        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('analytics_data', $result);
        $this->assertArrayHasKey('total_insights', $result['summary']);
        $this->assertArrayHasKey('retention', $result['analytics_data']);
        $this->assertArrayHasKey('session_depth', $result['analytics_data']);
        $this->assertArrayHasKey('traffic_sources', $result['analytics_data']);
    }
}
```

- [ ] **Step 4: Run structure tests**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter AnalyticsStructureTest
```
Expected: all PASS (baseline locked in).

- [ ] **Step 5: Commit**

```bash
git add database/factories/ClickFactory.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "test(analytics): snapshot structure tests + fix ClickFactory day_of_week to ISO 1-7"
```

---

### Task 2: UserAgentParser Support Class

**Files:**
- Create: `app/Services/Analytics/Support/UserAgentParser.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/Analytics/AnalyticsStructureTest.php` (before the closing `}`):
```php
use App\Services\Analytics\Support\UserAgentParser; // add to imports at top

public function test_ua_parser_identifies_chrome(): void
{
    $parser = new UserAgentParser();
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
    $this->assertSame('Chrome', $parser->extractBrowser($ua));
}

public function test_ua_parser_identifies_android(): void
{
    $parser = new UserAgentParser();
    $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36';
    $this->assertSame('Android', $parser->extractOS($ua));
}

public function test_ua_parser_extracts_primary_language(): void
{
    $parser = new UserAgentParser();
    $this->assertSame('Português (Brasil)', $parser->extractPrimaryLanguage('pt-BR,pt;q=0.9,en;q=0.8'));
    $this->assertNull($parser->extractPrimaryLanguage(null));
}
```

- [ ] **Step 2: Run — confirm FAIL (class not found)**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_ua_parser"
```
Expected: FAIL.

- [ ] **Step 3: Create UserAgentParser**

Create `app/Services/Analytics/Support/UserAgentParser.php`:
```php
<?php

namespace App\Services\Analytics\Support;

class UserAgentParser
{
    public function extractBrowser(?string $ua): string
    {
        if (!$ua) return 'Outros';

        if (preg_match('/Chrome\/[\d.]+/', $ua) && !preg_match('/Edg\/[\d.]+/', $ua) && !preg_match('/OPR\/[\d.]+/', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/Firefox\/[\d.]+/', $ua)) return 'Firefox';
        if (preg_match('/Safari\/[\d.]+/', $ua) && !preg_match('/Chrome\//', $ua)) return 'Safari';
        if (preg_match('/Edg\/[\d.]+/', $ua)) return 'Edge';
        if (preg_match('/Opera\/[\d.]+/', $ua) || preg_match('/OPR\/[\d.]+/', $ua)) return 'Opera';

        return 'Outros';
    }

    public function extractOS(?string $ua): string
    {
        if (!$ua) return 'Outros';

        if (preg_match('/Windows NT [\d.]+/', $ua)) return 'Windows';
        if (preg_match('/Mac OS X [\d._]+/', $ua) || preg_match('/Macintosh/', $ua)) return 'macOS';
        if (preg_match('/Android [\d.]+/', $ua)) return 'Android';
        if (preg_match('/iPhone OS [\d._]+/', $ua) || preg_match('/iOS [\d._]+/', $ua)) return 'iOS';
        if (preg_match('/Linux/', $ua)) return 'Linux';

        return 'Outros';
    }

    public function extractPrimaryLanguage(?string $acceptLanguage): ?string
    {
        if (!$acceptLanguage) return null;

        $lang = trim(explode(';', explode(',', $acceptLanguage)[0])[0]);

        $map = [
            'pt-BR' => 'Português (Brasil)', 'pt' => 'Português',
            'en'    => 'English',            'en-US' => 'English (US)',
            'es'    => 'Español',            'fr' => 'Français',
            'de'    => 'Deutsch',            'it' => 'Italiano',
            'zh'    => '中文',               'ja' => '日本語',
            'ko'    => '한국어',             'ar' => 'العربية',
            'ru'    => 'Русский',
        ];

        return $map[$lang] ?? $lang;
    }
}
```

- [ ] **Step 4: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_ua_parser"
```
Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/Support/UserAgentParser.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): add UserAgentParser support class — consolidates UA extraction from 2 services"
```

---

### Task 3: DashboardAnalyticsService

**Files:**
- Create: `app/Services/Analytics/DashboardAnalyticsService.php`

- [ ] **Step 1: Write failing tests**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\DashboardAnalyticsService; // add to imports

public function test_dashboard_service_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy  = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id);
    $service = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($service));
    $this->assertSame(array_keys($legacy['summary']), array_keys($service['summary']));
}

public function test_dashboard_service_since_filter_works(): void
{
    $link = $this->makeLink();
    Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subHours(2)]);
    Click::factory()->count(5)->create(['link_id' => $link->id, 'created_at' => now()->subDays(7)]);

    $allTime = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 0);
    $last24h = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 24);

    $this->assertSame(7, $allTime['summary']['total_clicks']);
    $this->assertSame(2, $last24h['summary']['total_clicks']);
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_dashboard_service"
```

- [ ] **Step 3: Create DashboardAnalyticsService**

Create `app/Services/Analytics/DashboardAnalyticsService.php`. This extracts `getLinkDashboardAnalytics` from `LinkAnalyticsService`, replacing every `->when($this->queryAfter, ...)` with `->when($since, ...)` where `$since` is threaded as `?\Carbon\Carbon`:

```php
<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array
    {
        $since = $hours > 0 ? now()->subHours($hours) : null;
        $link  = Link::find($linkId);

        if (!$link) {
            return $this->emptyDashboard();
        }

        $totalClicks = $this->countClicks($linkId, $since);
        $unique      = $this->countUnique($linkId, $since);
        $countries   = $this->countCountries($linkId, $since);

        return [
            'summary' => [
                'total_clicks'        => $totalClicks,
                'total_links'         => 1,
                'active_links'        => $link->is_active ? 1 : 0,
                'unique_visitors'     => $unique,
                'success_rate'        => $this->estimateSuccessRate($linkId, $since),
                'avg_response_time'   => $this->estimateResponseTime($linkId, $since),
                'countries_reached'   => $countries,
                'links_with_traffic'  => $totalClicks > 0 ? 1 : 0,
            ],
            'link_info' => [
                'id'           => $link->id,
                'title'        => $link->title,
                'short_url'    => $link->short_url,
                'original_url' => $link->original_url,
                'clicks'       => $totalClicks,
                'is_active'    => $link->is_active,
                'created_at'   => $link->created_at,
            ],
            'temporal_data' => [
                'clicks_by_hour'        => $this->getClicksByHour($linkId, $since),
                'clicks_by_day_of_week' => $this->getClicksByDayOfWeek($linkId, $since),
            ],
            'geographic_data' => [
                'top_countries' => $this->getTopCountries($linkId, $since),
                'top_cities'    => $this->getTopCities($linkId, $since),
            ],
            'audience_data' => [
                'device_breakdown' => $this->getDeviceBreakdown($linkId, $since),
            ],
        ];
    }

    private function emptyDashboard(): array
    {
        return [
            'summary'         => ['total_clicks' => 0, 'total_links' => 1, 'active_links' => 0, 'unique_visitors' => 0, 'success_rate' => 0, 'avg_response_time' => 0, 'countries_reached' => 0, 'links_with_traffic' => 0],
            'link_info'       => null,
            'temporal_data'   => ['clicks_by_hour' => [], 'clicks_by_day_of_week' => []],
            'geographic_data' => ['top_countries' => [], 'top_cities' => []],
            'audience_data'   => ['device_breakdown' => []],
        ];
    }

    private function countClicks(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->count();
    }

    private function countUnique(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->distinct('ip')->count();
    }

    private function countCountries(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->distinct('country')->count();
    }

    private function estimateSuccessRate(int $linkId, ?Carbon $since): float
    {
        $total = $this->countClicks($linkId, $since);
        if ($total === 0) return 100.0;

        $ok = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('response_time')->where('response_time', '<', 5000)
            ->count();

        return round(($ok / $total) * 100, 2);
    }

    private function estimateResponseTime(int $linkId, ?Carbon $since): float
    {
        return (float) (DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('response_time')
            ->avg('response_time') ?? 0);
    }

    private function getClicksByHour(int $linkId, ?Carbon $since): array
    {
        $sqlite    = DB::connection()->getDriverName() === 'sqlite';
        $hourExpr  = $sqlite
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("{$hourExpr} as hour, count(*) as clicks")
            ->groupByRaw($hourExpr)
            ->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'clicks' => (int) ($rows->get($h)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getClicksByDayOfWeek(int $linkId, ?Carbon $since): array
    {
        $sqlite  = DB::connection()->getDriverName() === 'sqlite';
        $dowExpr = $sqlite
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("{$dowExpr} as dow, count(*) as clicks")
            ->groupByRaw($dowExpr)->get()->keyBy('dow');

        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => (int) ($rows->get($d)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getTopCountries(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    private function getTopCities(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('city, country, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('city')
            ->groupBy('city', 'country')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['city' => $r->city, 'country' => $r->country, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getDeviceBreakdown(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('device')
            ->groupBy('device')->orderBy('clicks', 'desc')->get()
            ->map(fn($r) => ['device' => $r->device, 'clicks' => (int) $r->clicks])
            ->toArray();
    }
}
```

- [ ] **Step 4: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_dashboard_service"
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/DashboardAnalyticsService.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): extract DashboardAnalyticsService — \$queryAfter replaced by \$since param threading"
```

---

### Task 4: GeographicAnalyticsService

**Files:**
- Create: `app/Services/Analytics/GeographicAnalyticsService.php`

- [ ] **Step 1: Write failing test**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\GeographicAnalyticsService; // add to imports

public function test_geographic_service_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy  = app(LinkAnalyticsService::class)->getLinkGeographicAnalytics($link->id);
    $service = app(GeographicAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($service));
}

public function test_geographic_service_heatmap_returns_valid_structure(): void
{
    $link = $this->makeLink();
    Click::factory()->count(2)->create([
        'link_id'   => $link->id,
        'latitude'  => -23.5,
        'longitude' => -46.6,
        'country'   => 'Brazil',
    ]);

    $result = app(GeographicAnalyticsService::class)->getHeatmapData($link->id);

    $this->assertIsArray($result);
    if (count($result) > 0) {
        $this->assertArrayHasKey('lat', $result[0]);
        $this->assertArrayHasKey('lng', $result[0]);
        $this->assertArrayHasKey('clicks', $result[0]);
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_geographic_service"
```

- [ ] **Step 3: Create GeographicAnalyticsService**

Create `app/Services/Analytics/GeographicAnalyticsService.php`:
```php
<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

class GeographicAnalyticsService
{
    public function getLinkGeographicAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return ['top_countries' => [], 'top_states' => [], 'top_cities' => []];
        }

        return [
            'top_countries' => $this->getTopCountriesOptimized($linkId),
            'top_states'    => $this->getTopStatesOptimized($linkId),
            'top_cities'    => $this->getTopCitiesOptimized($linkId),
        ];
    }

    public function getHeatmapData(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->where('link_id', $linkId)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotNull('country')->where('country', '!=', 'localhost')->where('country', '!=', '')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')->get()
            ->map(fn($r) => [
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'city'       => $r->city ?: 'Cidade Desconhecida',
                'country'    => $r->country,
                'clicks'     => (int) $r->clicks,
                'iso_code'   => $r->iso_code,
                'currency'   => $r->currency,
                'state_name' => $r->state_name,
                'continent'  => $r->continent,
                'timezone'   => $r->timezone,
                'last_click' => $r->last_click,
            ])
            ->toArray();
    }

    private function getTopCountriesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    private function getTopStatesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('country, state, state_name, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['country' => $r->country, 'state' => $r->state, 'state_name' => $r->state_name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getTopCitiesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('city, country, state, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('city')
            ->groupBy('city', 'country', 'state')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['city' => $r->city, 'country' => $r->country, 'state' => $r->state, 'clicks' => (int) $r->clicks])
            ->toArray();
    }
}
```

- [ ] **Step 4: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_geographic_service"
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/GeographicAnalyticsService.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): extract GeographicAnalyticsService (heatmap + geo methods)"
```

---

### Task 5: TemporalAnalyticsService

**Files:**
- Create: `app/Services/Analytics/TemporalAnalyticsService.php`

Consolidates `getLinkTemporalAnalytics` + temporal privates from `LinkAnalyticsService` **and** `getAdvancedTemporalAnalytics` + privates from `UserAgentAnalyticsService`. After Task 8, the controller calls `getAdvancedTemporalAnalytics` on this service directly.

- [ ] **Step 1: Write failing tests**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\TemporalAnalyticsService; // add to imports

public function test_temporal_service_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy  = app(LinkAnalyticsService::class)->getLinkTemporalAnalytics($link->id);
    $service = app(TemporalAnalyticsService::class)->getLinkTemporalAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($service));
    $this->assertCount(24, $service['clicks_by_hour']);
    $this->assertCount(7, $service['clicks_by_day_of_week']);
}

public function test_temporal_service_advanced_has_required_keys(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $result = app(TemporalAnalyticsService::class)->getAdvancedTemporalAnalytics($link->id);

    $this->assertArrayHasKey('peak_analysis', $result);
    $this->assertArrayHasKey('timezone_analysis', $result);
    $this->assertArrayHasKey('weekly_trends', $result);
    $this->assertArrayHasKey('monthly_trends', $result);
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_temporal_service"
```

- [ ] **Step 3: Create TemporalAnalyticsService**

Create `app/Services/Analytics/TemporalAnalyticsService.php`:
```php
<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

class TemporalAnalyticsService
{
    public function getLinkTemporalAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return ['clicks_by_hour' => [], 'clicks_by_day_of_week' => []];
        }

        return [
            'clicks_by_hour'          => $this->getClicksByHour($linkId),
            'clicks_by_day_of_week'   => $this->getClicksByDayOfWeek($linkId),
            'hourly_patterns_local'   => $this->getHourlyPatternsLocal($linkId),
            'weekend_vs_weekday'      => $this->getWeekendVsWeekday($linkId),
            'business_hours_analysis' => $this->getBusinessHoursAnalysis($linkId),
        ];
    }

    public function getAdvancedTemporalAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();

        return [
            'hourly_patterns'  => $this->getHourlyPatterns($clicks),
            'daily_patterns'   => $this->getDailyPatterns($clicks),
            'weekly_trends'    => $this->getWeeklyTrends($clicks),
            'monthly_trends'   => $this->getMonthlyTrends($clicks),
            'peak_analysis'    => $this->getPeakAnalysis($clicks),
            'timezone_analysis' => $this->getTimezoneAnalysis($clicks),
        ];
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function getClicksByHour(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->selectRaw("{$expr} as hour, count(*) as clicks")
            ->groupByRaw($expr)->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'clicks' => (int) ($rows->get($h)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getClicksByDayOfWeek(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows  = DB::table('clicks')->where('link_id', $linkId)
            ->selectRaw("{$expr} as dow, count(*) as clicks")
            ->groupByRaw($expr)->get()->keyBy('dow');

        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => (int) ($rows->get($d)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getHourlyPatternsLocal(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('hour_of_day, COUNT(*) as clicks, AVG(response_time) as avg_response_time, COUNT(DISTINCT ip) as unique_visitors')
            ->where('link_id', $linkId)->whereNotNull('hour_of_day')
            ->groupBy('hour_of_day')->orderBy('hour_of_day')->get()
            ->map(fn($r) => ['hour' => (int) $r->hour_of_day, 'clicks' => (int) $r->clicks, 'avg_response_time' => round((float) $r->avg_response_time, 2), 'unique_visitors' => (int) $r->unique_visitors])
            ->toArray();
    }

    private function getWeekendVsWeekday(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows     = DB::table('clicks')->where('link_id', $linkId)->selectRaw("({$expr}) as dow, count(*) as clicks")->groupByRaw($expr)->get();
        $weekday  = $rows->whereIn('dow', [1, 2, 3, 4, 5])->sum('clicks');
        $weekend  = $rows->whereIn('dow', [6, 7])->sum('clicks');
        $total    = $weekday + $weekend;

        return [
            'weekday' => ['clicks' => $weekday, 'percentage' => $total > 0 ? round($weekday / $total * 100, 2) : 0],
            'weekend' => ['clicks' => $weekend, 'percentage' => $total > 0 ? round($weekend / $total * 100, 2) : 0],
        ];
    }

    private function getBusinessHoursAnalysis(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows     = DB::table('clicks')->where('link_id', $linkId)->selectRaw("{$expr} as h, count(*) as clicks")->groupByRaw($expr)->get();
        $business = $rows->whereBetween('h', [9, 17])->sum('clicks');
        $after    = $rows->sum('clicks') - $business;
        $total    = $business + $after;

        return [
            'business_hours' => ['clicks' => $business, 'percentage' => $total > 0 ? round($business / $total * 100, 2) : 0],
            'after_hours'    => ['clicks' => $after,    'percentage' => $total > 0 ? round($after / $total * 100, 2) : 0],
        ];
    }

    // Advanced methods migrated from UserAgentAnalyticsService

    private function getHourlyPatterns($clicks): array
    {
        $patterns = array_fill(0, 24, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            if ($h >= 0 && $h <= 23) $patterns[$h]++;
        }
        $result = [];
        for ($h = 0; $h < 24; $h++) $result[] = ['hour' => $h, 'clicks' => $patterns[$h]];
        return $result;
    }

    private function getDailyPatterns($clicks): array
    {
        $patterns = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($d >= 1 && $d <= 7) $patterns[$d] = ($patterns[$d] ?? 0) + 1;
        }
        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => $patterns[$d]];
        return $result;
    }

    private function getWeeklyTrends($clicks): array
    {
        $weekly = [];
        foreach ($clicks as $click) {
            $w = $click->created_at->startOfWeek()->format('Y-W');
            $weekly[$w] = ($weekly[$w] ?? 0) + 1;
        }
        ksort($weekly);
        return array_map(fn($w, $n) => ['week' => $w, 'clicks' => $n], array_keys($weekly), $weekly);
    }

    private function getMonthlyTrends($clicks): array
    {
        $monthly = [];
        foreach ($clicks as $click) {
            $m = $click->created_at->format('Y-m');
            $monthly[$m] = ($monthly[$m] ?? 0) + 1;
        }
        ksort($monthly);
        return array_map(fn($m, $n) => ['month' => $m, 'clicks' => $n], array_keys($monthly), $monthly);
    }

    private function getPeakAnalysis($clicks): array
    {
        $hourly = array_fill(0, 24, 0);
        $daily  = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($h >= 0 && $h <= 23) $hourly[$h]++;
            if ($d >= 1 && $d <= 7)  $daily[$d] = ($daily[$d] ?? 0) + 1;
        }
        $peakHour = array_search(max($hourly), $hourly);
        $peakDay  = array_search(max($daily), $daily);
        $names    = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        return [
            'peak_hour'        => $peakHour,
            'peak_day'         => $peakDay,
            'peak_day_name'    => $names[$peakDay] ?? 'Desconhecido',
            'peak_hour_clicks' => $hourly[$peakHour] ?? 0,
            'peak_day_clicks'  => $daily[$peakDay]   ?? 0,
        ];
    }

    private function getTimezoneAnalysis($clicks): array
    {
        $tzs = [];
        foreach ($clicks as $click) {
            $tz = $click->timezone ?? 'Unknown';
            $tzs[$tz] = ($tzs[$tz] ?? 0) + 1;
        }
        arsort($tzs);
        return array_map(fn($tz, $n) => ['name' => $tz, 'clicks' => $n], array_keys($tzs), $tzs);
    }
}
```

- [ ] **Step 4: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_temporal_service"
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/TemporalAnalyticsService.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): extract TemporalAnalyticsService (consolidates LinkAnalyticsService + UserAgentAnalyticsService temporal logic)"
```

---

### Task 6: AudienceAnalyticsService

**Files:**
- Create: `app/Services/Analytics/AudienceAnalyticsService.php`

- [ ] **Step 1: Write failing test**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\AudienceAnalyticsService; // add to imports

public function test_audience_service_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy  = app(LinkAnalyticsService::class)->getLinkAudienceAnalytics($link->id);
    $service = app(AudienceAnalyticsService::class)->getLinkAudienceAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($service));
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_audience_service"
```

- [ ] **Step 3: Create AudienceAnalyticsService**

Create `app/Services/Analytics/AudienceAnalyticsService.php`:
```php
<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Support\UserAgentParser;
use Illuminate\Support\Facades\DB;

class AudienceAnalyticsService
{
    public function __construct(private readonly UserAgentParser $uaParser) {}

    public function getLinkAudienceAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return ['device_breakdown' => []];
        }

        return [
            'device_breakdown'   => $this->getDeviceBreakdown($linkId),
            'browser_breakdown'  => $this->getBrowserBreakdown($linkId),
            'os_breakdown'       => $this->getOSBreakdown($linkId),
            'browsers'           => $this->getBrowserDistribution($linkId),
            'operating_systems'  => $this->getOSDistribution($linkId),
            'device_performance' => $this->getDevicePerformance($linkId),
            'languages'          => $this->getLanguageDistribution($linkId),
        ];
    }

    private function getDeviceBreakdown(int $linkId): array
    {
        $total = Click::where('link_id', $linkId)->count();
        return DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('device')
            ->groupBy('device')->orderBy('clicks', 'desc')->get()
            ->map(fn($r) => ['device' => ucfirst($r->device), 'clicks' => (int) $r->clicks, 'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0])
            ->toArray();
    }

    private function getBrowserBreakdown(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('browser, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('browser')
            ->groupBy('browser')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['browser' => ucfirst($r->browser), 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getOSBreakdown(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('os, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('os')
            ->groupBy('os')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['os' => $r->os, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getBrowserDistribution(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('browser, browser_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['browser' => $r->browser, 'version' => $r->browser_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    private function getOSDistribution(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('os, os_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)->whereNotNull('os')
            ->groupBy('os', 'os_version')->orderBy('clicks', 'desc')->limit(15)->get()
            ->map(fn($r) => ['os' => $r->os, 'version' => $r->os_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    private function getDevicePerformance(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('device, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time, COUNT(*) as total_clicks')
            ->where('link_id', $linkId)->whereNotNull('device')->whereNotNull('response_time')
            ->groupBy('device')->orderBy('avg_response_time', 'asc')->get()
            ->map(fn($r) => ['device' => $r->device, 'avg_response_time' => round((float) $r->avg_response_time, 2), 'min_response_time' => round((float) $r->min_response_time, 2), 'max_response_time' => round((float) $r->max_response_time, 2), 'total_clicks' => (int) $r->total_clicks])
            ->toArray();
    }

    private function getLanguageDistribution(int $linkId): array
    {
        $clicks = DB::table('clicks')->select('accept_language')
            ->where('link_id', $linkId)->whereNotNull('accept_language')->get();

        $counts = [];
        foreach ($clicks as $click) {
            $lang = $this->uaParser->extractPrimaryLanguage($click->accept_language);
            if ($lang) $counts[$lang] = ($counts[$lang] ?? 0) + 1;
        }

        arsort($counts);
        $total = array_sum($counts);

        return array_slice(
            array_map(fn($lang, $n) => ['language' => $lang, 'clicks' => $n, 'percentage' => round($n / $total * 100, 2)], array_keys($counts), $counts),
            0, 10
        );
    }
}
```

- [ ] **Step 4: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_audience_service"
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AudienceAnalyticsService.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): extract AudienceAnalyticsService — injects UserAgentParser"
```

---

### Task 7: InsightsAnalyticsService + Strategy Pattern

**Files:**
- Create: `app/Services/Analytics/Insights/InsightGeneratorInterface.php`
- Create: `app/Services/Analytics/Insights/InsightGeneratorRegistry.php`
- Create: `app/Services/Analytics/Insights/Generators/{8 generators}.php`
- Create: `app/Services/Analytics/InsightsAnalyticsService.php`

- [ ] **Step 1: Write failing tests**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\InsightsAnalyticsService;     // add to imports
use App\Services\Analytics\Insights\InsightGeneratorRegistry; // add to imports

public function test_insights_service_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy  = app(LinkAnalyticsService::class)->getLinkInsightsAnalytics($link->id);
    $service = app(InsightsAnalyticsService::class)->getLinkInsightsAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($service));
    $this->assertSame(array_keys($legacy['summary']), array_keys($service['summary']));
    $this->assertSame(array_keys($legacy['analytics_data']), array_keys($service['analytics_data']));
}

public function test_insight_registry_produces_valid_insight_shapes(): void
{
    $link = $this->makeLink();
    Click::factory()->count(10)->create(['link_id' => $link->id, 'country' => 'Brazil']);

    $registry = app(InsightGeneratorRegistry::class);
    $insights = $registry->generate($link->id, 10);

    $this->assertIsArray($insights);
    foreach ($insights as $insight) {
        $this->assertArrayHasKey('type', $insight);
        $this->assertArrayHasKey('title', $insight);
        $this->assertArrayHasKey('priority', $insight);
        $this->assertArrayHasKey('confidence', $insight);
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_insights"
```

- [ ] **Step 3: Create interface and registry**

Create `app/Services/Analytics/Insights/InsightGeneratorInterface.php`:
```php
<?php

namespace App\Services\Analytics\Insights;

interface InsightGeneratorInterface
{
    /** Returns insight array or null if condition not met */
    public function generate(int $linkId, int $totalClicks): ?array;
}
```

Create `app/Services/Analytics/Insights/InsightGeneratorRegistry.php`:
```php
<?php

namespace App\Services\Analytics\Insights;

class InsightGeneratorRegistry
{
    /** @var InsightGeneratorInterface[] */
    private array $generators = [];

    public function register(InsightGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;
    }

    public function generate(int $linkId, int $totalClicks): array
    {
        $insights = [];
        foreach ($this->generators as $gen) {
            $insight = $gen->generate($linkId, $totalClicks);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }
        return $insights;
    }
}
```

- [ ] **Step 4: Create the 8 generators**

Create `app/Services/Analytics/Insights/Generators/GeographicInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class GeographicInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $top = DB::table('clicks')->selectRaw('country, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country')->orderBy('clicks', 'desc')->first();

        if (!$top) return null;
        $pct = round(($top->clicks / $totalClicks) * 100, 1);

        return ['type' => 'geographic', 'title' => 'Mercado Principal', 'description' => "O {$top->country} representa {$pct}% dos seus cliques.", 'priority' => $pct > 50 ? 'high' : 'medium', 'actionable' => true, 'confidence' => 0.9, 'impact_score' => 8, 'recommendation' => 'Crie campanhas direcionadas para este país.'];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/DeviceInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class DeviceInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $top = DB::table('clicks')->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('device')
            ->groupBy('device')->orderBy('clicks', 'desc')->first();

        if (!$top) return null;
        $pct = round(($top->clicks / $totalClicks) * 100, 1);

        return ['type' => 'audience', 'title' => 'Dispositivo Dominante', 'description' => "{$pct}% dos acessos são de {$top->device}.", 'priority' => $pct > 70 ? 'high' : 'medium', 'actionable' => true, 'confidence' => 0.9, 'impact_score' => 7, 'recommendation' => "Otimize sua página de destino para {$top->device}."];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/TemporalInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class TemporalInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $expr   = $sqlite
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $peak = DB::table('clicks')->selectRaw("{$expr} as hour, COUNT(*) as clicks")
            ->where('link_id', $linkId)->groupByRaw($expr)->orderBy('clicks', 'desc')->first();

        if (!$peak) return null;

        return ['type' => 'temporal', 'title' => 'Horário de Pico', 'description' => "A maioria dos cliques ocorre às {$peak->hour}h.", 'priority' => 'medium', 'actionable' => true, 'confidence' => 0.85, 'impact_score' => 6, 'recommendation' => "Publique conteúdo às {$peak->hour}h para maximizar alcance."];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/PerformanceInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class PerformanceInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $avg = (float) DB::table('clicks')->where('link_id', $linkId)->whereNotNull('response_time')->avg('response_time');
        if ($avg <= 0) return null;

        $slow = $avg > 500;
        return ['type' => 'performance', 'title' => $slow ? 'Velocidade de Resposta Lenta' : 'Boa Performance de Resposta', 'description' => "Tempo médio de resposta: {$avg}ms.", 'priority' => $slow ? 'high' : 'low', 'actionable' => $slow, 'confidence' => 0.8, 'impact_score' => $slow ? 7 : 3, 'recommendation' => $slow ? 'Otimize sua infraestrutura.' : 'Continue monitorando.'];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/DiversityInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class DiversityInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $countries = Click::where('link_id', $linkId)->whereNotNull('country')->where('country', '!=', 'localhost')->distinct('country')->count();
        if ($countries <= 5) return null;

        return ['type' => 'geographic', 'title' => 'Alcance Internacional', 'description' => "Seu link alcançou {$countries} países diferentes.", 'priority' => $countries > 10 ? 'high' : 'medium', 'actionable' => true, 'confidence' => 0.85, 'impact_score' => 8, 'recommendation' => 'Considere expandir para mercados internacionais com maior tráfego.'];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/SecurityInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class SecurityInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $n = DB::table('clicks')->selectRaw('ip, COUNT(*) as c')->where('link_id', $linkId)->groupBy('ip')->havingRaw('COUNT(*) > 50')->get()->count();
        if ($n === 0) return null;

        return ['type' => 'security', 'title' => 'Atividade Suspeita Detectada', 'description' => "Detectamos {$n} IP(s) com atividade anormalmente alta.", 'priority' => 'high', 'actionable' => true, 'confidence' => 0.7, 'impact_score' => 5, 'recommendation' => 'Analise os IPs com maior atividade.'];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/EngagementInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class EngagementInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $recent = Click::where('link_id', $linkId)->where('created_at', '>=', now()->subDays(7))->count();
        $old    = Click::where('link_id', $linkId)->where('created_at', '<', now()->subDays(7))->where('created_at', '>=', now()->subDays(14))->count();

        if ($old === 0) return null;
        $rate = round((($recent - $old) / $old) * 100, 1);
        if (abs($rate) <= 20) return null;

        return ['type' => 'engagement', 'title' => $rate > 0 ? 'Crescimento Acelerado' : 'Declínio no Engajamento', 'description' => $rate > 0 ? "Cliques cresceram {$rate}% na última semana." : 'Cliques diminuíram ' . abs($rate) . '% na última semana.', 'priority' => abs($rate) > 50 ? 'high' : 'medium', 'actionable' => $rate < 0, 'confidence' => 0.8, 'impact_score' => abs($rate) > 50 ? 9 : 6, 'recommendation' => $rate > 0 ? 'Analise o que funcionou e replique.' : 'Revise conteúdo e canais de distribuição.'];
    }
}
```

Create `app/Services/Analytics/Insights/Generators/RetentionInsightGenerator.php`:
```php
<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class RetentionInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $total   = Click::where('link_id', $linkId)->distinct('ip')->count('ip');
        if ($total === 0) return null;

        $return  = Click::where('link_id', $linkId)->where('is_return_visitor', true)->distinct('ip')->count('ip');
        $rate    = round(($return / $total) * 100, 1);

        return ['type' => 'retention', 'title' => 'Taxa de Retenção', 'description' => "Taxa de visitantes recorrentes: {$rate}%.", 'priority' => $rate < 15 ? 'high' : ($rate >= 25 ? 'low' : 'medium'), 'actionable' => $rate < 25, 'confidence' => 0.85, 'impact_score' => $rate < 15 ? 8 : 5, 'recommendation' => $rate < 25 ? 'Implemente newsletters ou conteúdo serializado.' : 'Continue criando conteúdo de qualidade.', 'data_points' => ['total_visitors' => $total, 'return_visitors' => $return, 'return_visitor_rate' => $rate]];
    }
}
```

- [ ] **Step 5: Create InsightsAnalyticsService**

Create `app/Services/Analytics/InsightsAnalyticsService.php`:
```php
<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Insights\InsightGeneratorRegistry;
use App\Services\Analytics\Insights\Generators\GeographicInsightGenerator;
use App\Services\Analytics\Insights\Generators\DeviceInsightGenerator;
use App\Services\Analytics\Insights\Generators\TemporalInsightGenerator;
use App\Services\Analytics\Insights\Generators\PerformanceInsightGenerator;
use App\Services\Analytics\Insights\Generators\DiversityInsightGenerator;
use App\Services\Analytics\Insights\Generators\SecurityInsightGenerator;
use App\Services\Analytics\Insights\Generators\EngagementInsightGenerator;
use App\Services\Analytics\Insights\Generators\RetentionInsightGenerator;
use Illuminate\Support\Facades\DB;

class InsightsAnalyticsService
{
    private InsightGeneratorRegistry $registry;

    public function __construct()
    {
        $this->registry = new InsightGeneratorRegistry();
        foreach ([
            new GeographicInsightGenerator(),
            new DeviceInsightGenerator(),
            new TemporalInsightGenerator(),
            new PerformanceInsightGenerator(),
            new DiversityInsightGenerator(),
            new SecurityInsightGenerator(),
            new EngagementInsightGenerator(),
            new RetentionInsightGenerator(),
        ] as $gen) {
            $this->registry->register($gen);
        }
    }

    public function getLinkInsightsAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);
        $totalClicks = Click::where('link_id', $linkId)->count();

        $analyticsData = [
            'retention'       => $this->getReturnVisitorRate($linkId),
            'session_depth'   => $this->getSessionDepthAnalysis($linkId),
            'traffic_sources' => $this->getTrafficSourceAnalysis($linkId),
        ];

        if ($totalClicks === 0) {
            return ['insights' => [], 'summary' => ['total_insights' => 0, 'high_priority' => 0, 'actionable_insights' => 0, 'avg_confidence' => 0], 'analytics_data' => $analyticsData, 'generated_at' => now()->toISOString()];
        }

        $insights = $this->registry->generate($linkId, $totalClicks);

        return [
            'insights'       => $insights,
            'summary'        => [
                'total_insights'      => count($insights),
                'high_priority'       => count(array_filter($insights, fn($i) => $i['priority'] === 'high')),
                'actionable_insights' => count(array_filter($insights, fn($i) => $i['actionable'])),
                'avg_confidence'      => count($insights) > 0 ? round(array_sum(array_column($insights, 'confidence')) / count($insights), 2) : 0,
            ],
            'analytics_data' => $analyticsData,
            'generated_at'   => now()->toISOString(),
        ];
    }

    private function getReturnVisitorRate(int $linkId): array
    {
        $total  = Click::where('link_id', $linkId)->distinct('ip')->count('ip');
        $return = Click::where('link_id', $linkId)->where('is_return_visitor', true)->distinct('ip')->count('ip');
        $rate   = $total > 0 ? round(($return / $total) * 100, 1) : 0;

        return ['total_visitors' => $total, 'return_visitors' => $return, 'new_visitors' => max(0, $total - $return), 'return_visitor_rate' => $rate, 'benchmark_comparison' => $rate >= 25 ? 'acima da média' : ($rate >= 15 ? 'na média' : 'abaixo da média')];
    }

    private function getSessionDepthAnalysis(int $linkId): array
    {
        $clicks  = Click::where('link_id', $linkId)->count();
        $unique  = Click::where('link_id', $linkId)->distinct('ip')->count('ip');
        return ['avg_clicks_per_visitor' => $unique > 0 ? round($clicks / $unique, 2) : 0, 'total_sessions' => $unique];
    }

    private function getTrafficSourceAnalysis(int $linkId): array
    {
        $sources  = DB::table('clicks')->selectRaw('click_source as source, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('click_source')
            ->groupBy('click_source')->orderBy('clicks', 'desc')->get();

        $total    = $sources->sum('clicks');
        $channels = [];
        foreach ($sources as $s) {
            $ch = match($s->source) {
                'social', 'search', 'direct', 'email', 'referral' => $s->source,
                default => 'other',
            };
            $channels[$ch] = ($channels[$ch] ?? 0) + $s->clicks;
        }

        return array_map(fn($ch, $n) => ['source' => $ch, 'clicks' => $n, 'percentage' => $total > 0 ? round($n / $total * 100, 2) : 0], array_keys($channels), $channels);
    }
}
```

- [ ] **Step 6: Run — confirm PASS**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_insights"
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/Analytics/Insights/ app/Services/Analytics/InsightsAnalyticsService.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "feat(analytics): InsightsAnalyticsService + Strategy Pattern (8 generators + registry)"
```

---

### Task 8: LinkAnalyticsOrchestrator + Interfaces + Cleanup

**Files:**
- Create: `app/Contracts/Analytics/{5 interfaces}.php`
- Create: `app/Services/Analytics/LinkAnalyticsOrchestrator.php`
- Modify: `app/Http/Controllers/Analytics/AnalyticsController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Delete: `app/Services/Analytics/LinkAnalyticsService.php` + `UserAgentAnalyticsService.php`

- [ ] **Step 1: Write failing tests**

Add to `AnalyticsStructureTest.php`:
```php
use App\Services\Analytics\LinkAnalyticsOrchestrator; // add to imports

public function test_orchestrator_comprehensive_matches_monolith_structure(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);

    $legacy = app(LinkAnalyticsService::class)->getComprehensiveLinkAnalytics($link->id);
    $orch   = app(LinkAnalyticsOrchestrator::class)->getComprehensiveLinkAnalytics($link->id);

    $this->assertSame(array_keys($legacy), array_keys($orch));
}

public function test_orchestrator_all_public_methods_return_correct_top_level_keys(): void
{
    $link = $this->makeLink();
    $this->seedClicks($link->id);
    $orch = app(LinkAnalyticsOrchestrator::class);

    $this->assertArrayHasKey('has_data', $orch->getComprehensiveLinkAnalytics($link->id));
    $this->assertArrayHasKey('summary', $orch->getLinkDashboardAnalytics($link->id));
    $this->assertArrayHasKey('top_countries', $orch->getLinkGeographicAnalytics($link->id));
    $this->assertArrayHasKey('clicks_by_hour', $orch->getLinkTemporalAnalytics($link->id));
    $this->assertArrayHasKey('device_breakdown', $orch->getLinkAudienceAnalytics($link->id));
    $this->assertArrayHasKey('insights', $orch->getLinkInsightsAnalytics($link->id));
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
docker-compose exec app ./vendor/bin/phpunit --filter "test_orchestrator"
```

- [ ] **Step 3: Create the 5 interfaces**

Create `app/Contracts/Analytics/DashboardAnalyticsInterface.php`:
```php
<?php
namespace App\Contracts\Analytics;
interface DashboardAnalyticsInterface
{
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array;
}
```

Create `app/Contracts/Analytics/GeographicAnalyticsInterface.php`:
```php
<?php
namespace App\Contracts\Analytics;
interface GeographicAnalyticsInterface
{
    public function getLinkGeographicAnalytics(int $linkId): array;
    public function getHeatmapData(int $linkId): array;
}
```

Create `app/Contracts/Analytics/TemporalAnalyticsInterface.php`:
```php
<?php
namespace App\Contracts\Analytics;
interface TemporalAnalyticsInterface
{
    public function getLinkTemporalAnalytics(int $linkId): array;
    public function getAdvancedTemporalAnalytics(int $linkId): array;
}
```

Create `app/Contracts/Analytics/AudienceAnalyticsInterface.php`:
```php
<?php
namespace App\Contracts\Analytics;
interface AudienceAnalyticsInterface
{
    public function getLinkAudienceAnalytics(int $linkId): array;
}
```

Create `app/Contracts/Analytics/InsightsAnalyticsInterface.php`:
```php
<?php
namespace App\Contracts\Analytics;
interface InsightsAnalyticsInterface
{
    public function getLinkInsightsAnalytics(int $linkId): array;
}
```

- [ ] **Step 4: Implement interfaces on the 5 module services**

Add `implements` to each service class declaration:
```php
// DashboardAnalyticsService.php
class DashboardAnalyticsService implements \App\Contracts\Analytics\DashboardAnalyticsInterface

// GeographicAnalyticsService.php
class GeographicAnalyticsService implements \App\Contracts\Analytics\GeographicAnalyticsInterface

// TemporalAnalyticsService.php
class TemporalAnalyticsService implements \App\Contracts\Analytics\TemporalAnalyticsInterface

// AudienceAnalyticsService.php
class AudienceAnalyticsService implements \App\Contracts\Analytics\AudienceAnalyticsInterface

// InsightsAnalyticsService.php
class InsightsAnalyticsService implements \App\Contracts\Analytics\InsightsAnalyticsInterface
```

- [ ] **Step 5: Create LinkAnalyticsOrchestrator**

Create `app/Services/Analytics/LinkAnalyticsOrchestrator.php`:
```php
<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Models\Click;
use App\Models\Link;

class LinkAnalyticsOrchestrator
{
    public function __construct(
        private readonly DashboardAnalyticsInterface  $dashboard,
        private readonly GeographicAnalyticsInterface $geographic,
        private readonly TemporalAnalyticsInterface   $temporal,
        private readonly AudienceAnalyticsInterface   $audience,
        private readonly InsightsAnalyticsInterface   $insights,
    ) {}

    public function getComprehensiveLinkAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return [
                'has_data'  => false,
                'link_info' => $this->linkInfo($link),
                'message'   => 'Analytics will be available after the first clicks on your link.',
            ];
        }

        $total   = Click::where('link_id', $linkId)->count();
        $unique  = Click::where('link_id', $linkId)->distinct('ip')->count();
        $regions = Click::where('link_id', $linkId)->whereNotNull('country')->where('country', '!=', 'localhost')->distinct('country')->count();

        $insightsPayload = $this->insights->getLinkInsightsAnalytics($linkId);

        return [
            'has_data'   => true,
            'link_info'  => $this->linkInfo($link),
            'overview'   => [
                'total_clicks'      => $total,
                'unique_visitors'   => $unique,
                'countries_reached' => $regions,
                'avg_daily_clicks'  => $total > 0 ? round($total / 30, 1) : 0,
            ],
            'geographic' => array_merge(
                ['heatmap_data' => $this->geographic->getHeatmapData($linkId)],
                $this->geographic->getLinkGeographicAnalytics($linkId)
            ),
            'temporal'   => $this->temporal->getLinkTemporalAnalytics($linkId),
            'audience'   => $this->audience->getLinkAudienceAnalytics($linkId),
            'insights'   => $insightsPayload['insights'] ?? [],
        ];
    }

    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array
    {
        return $this->dashboard->getLinkDashboardAnalytics($linkId, $hours);
    }

    public function getLinkGeographicAnalytics(int $linkId): array
    {
        return $this->geographic->getLinkGeographicAnalytics($linkId);
    }

    public function getLinkTemporalAnalytics(int $linkId): array
    {
        return $this->temporal->getLinkTemporalAnalytics($linkId);
    }

    public function getLinkAudienceAnalytics(int $linkId): array
    {
        return $this->audience->getLinkAudienceAnalytics($linkId);
    }

    public function getLinkInsightsAnalytics(int $linkId): array
    {
        return $this->insights->getLinkInsightsAnalytics($linkId);
    }

    private function linkInfo(Link $link): array
    {
        return ['id' => $link->id, 'title' => $link->title, 'short_url' => $link->short_url, 'original_url' => $link->original_url, 'clicks' => $link->clicks, 'is_active' => $link->is_active, 'created_at' => $link->created_at];
    }
}
```

- [ ] **Step 6: Register bindings in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php` and add to `register()`:
```php
use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Services\Analytics\AudienceAnalyticsService;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Analytics\GeographicAnalyticsService;
use App\Services\Analytics\InsightsAnalyticsService;
use App\Services\Analytics\TemporalAnalyticsService;

// in register():
$this->app->bind(DashboardAnalyticsInterface::class,  DashboardAnalyticsService::class);
$this->app->bind(GeographicAnalyticsInterface::class, GeographicAnalyticsService::class);
$this->app->bind(TemporalAnalyticsInterface::class,   TemporalAnalyticsService::class);
$this->app->bind(AudienceAnalyticsInterface::class,   AudienceAnalyticsService::class);
$this->app->bind(InsightsAnalyticsInterface::class,   InsightsAnalyticsService::class);
```

- [ ] **Step 7: Update AnalyticsController**

In `app/Http/Controllers/Analytics/AnalyticsController.php`, replace the constructor and imports:

```php
// Remove:
use App\Services\Analytics\LinkAnalyticsService;
use App\Services\Analytics\UserAgentAnalyticsService;

// Add:
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Services\Analytics\LinkAnalyticsOrchestrator;

// Replace constructor:
public function __construct(
    private LinkAnalyticsOrchestrator $analyticsService,
    private TemporalAnalyticsInterface $temporalService
) {}
```

In `getTemporalAnalytics`, change the advanced data call:
```php
// Replace:
$advancedData = $this->userAgentAnalyticsService->getAdvancedTemporalAnalytics($linkId);
// With:
$advancedData = $this->temporalService->getAdvancedTemporalAnalytics($linkId);
```

- [ ] **Step 8: Run full suite — confirm all green**

```bash
docker-compose exec app ./vendor/bin/phpunit
```
Expected: ALL PASS (orchestrator + module services + existing tests).

- [ ] **Step 9: Remove cross-comparison tests that reference the monolith**

In `tests/Feature/Analytics/AnalyticsStructureTest.php`, remove the tests that call `app(LinkAnalyticsService::class)` or `app(UserAgentAnalyticsService::class)` directly — they've served their purpose as migration guards. The orchestrator tests now cover the same assertions.

Also remove:
```php
use App\Services\Analytics\LinkAnalyticsService;
```
from the imports.

Run the suite again:
```bash
docker-compose exec app ./vendor/bin/phpunit
```
Expected: ALL PASS.

- [ ] **Step 10: Delete the monolith services**

```bash
rm app/Services/Analytics/LinkAnalyticsService.php
rm app/Services/Analytics/UserAgentAnalyticsService.php
```

Run suite one final time:
```bash
docker-compose exec app ./vendor/bin/phpunit
```
Expected: ALL PASS — monolith is gone.

- [ ] **Step 11: Commit**

```bash
git add app/Contracts/Analytics/ app/Services/Analytics/LinkAnalyticsOrchestrator.php app/Http/Controllers/Analytics/AnalyticsController.php app/Providers/AppServiceProvider.php tests/Feature/Analytics/AnalyticsStructureTest.php
git rm app/Services/Analytics/LinkAnalyticsService.php app/Services/Analytics/UserAgentAnalyticsService.php
git commit -m "feat(analytics): LinkAnalyticsOrchestrator replaces 1697-line monolith — contracts, bindings, cleanup"
```

---

## Self-Review

### Spec coverage

| Wave 3 audit item | Task |
|---|---|
| 3.1 Extrair UserAgentParser (Support) | Task 2 |
| 3.2 Extrair ClickAggregator (queries reutilizáveis) | Absorvido em DashboardService (Task 3) |
| 3.3 DashboardAnalyticsService + HeatmapService + GeographicService | Tasks 3, 4 |
| 3.4 Consolidar Temporal (Link + UserAgent) em service único | Task 5 |
| 3.5 Extrair AudienceAnalyticsService | Task 6 |
| 3.6 Insights → Strategy pattern com generators dedicados | Task 7 |
| 3.7 Extrair PerformanceAnalyticsService | Absorvido em DashboardService (estimateSuccessRate/estimateResponseTime) |
| 3.8 Renomear para Orchestrator (fan-out only) | Task 8 |
| ClickFactory day_of_week 0→1-7 | Task 1 |
| Snapshot tests antes de qualquer refactor | Task 1 |

### Placeholder scan — clean

Nenhum "TBD", "TODO", "similar to Task N" ou bloco de código incompleto encontrado.

### Type consistency

- `InsightGeneratorInterface::generate()` → `?array` em todos os 8 generators ✓
- `getLinkInsightsAnalytics()` → `array` em InsightsAnalyticsService e Orchestrator ✓
- `getHeatmapData()` declarado em `GeographicAnalyticsInterface` e implementado em `GeographicAnalyticsService` ✓
- `getAdvancedTemporalAnalytics()` declarado em `TemporalAnalyticsInterface` e usado no controller ✓
- `$since: ?\Carbon\Carbon` threading em DashboardAnalyticsService — todos os 8 private methods o aceitam ✓
- Orchestrator `getComprehensiveLinkAnalytics` inclui `heatmap_data` em `geographic` via `array_merge` — compatível com `getHeatmapData($analytics['geographic']['heatmap_data'])` no controller ✓
