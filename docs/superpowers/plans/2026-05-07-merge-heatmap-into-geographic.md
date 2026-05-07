# Merge Heatmap tab into Geographic — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the redundant `Heatmap` analytics tab and `/api/analytics/link/{id}/heatmap` endpoint by absorbing them into the existing `Geographic` tab, which gains four MUI sub-tabs (Visão geral, Mapa de calor, Rankings, Insights) mirroring the `TemporalChart` pattern.

**Architecture:** Backend collapses two endpoints into one. `/api/analytics/link/{id}/geographic` becomes the single source of truth, returning the existing `data` block plus a new top-level `metadata` block (link_info + totals). On the frontend, `GeographicAnalysis.tsx` is refactored from a flat layout to a top-level metric row + an MUI Tabs control whose four panels host the existing visualization components. `RealTimeHeatmapChart` (with its internals `HeatmapMap` and `HeatmapControls`) is relocated under `components/geographic/`, all other heatmap files are deleted, and the `Heatmap` entry in `LinkAnalyticsTabs.tsx` is removed. No new dependencies. No new analytics dimensions.

**Tech Stack:** Laravel 12 / PHP 8.2 (backend, PHPUnit on SQLite `:memory:`), Next.js 15 / React / TypeScript / MUI 6 (frontend, no test runner — `npm run quality` is the gate).

**Spec:** `backend/docs/superpowers/specs/2026-05-07-merge-heatmap-into-geographic-design.md`

---

## File map

### Backend — modified
- `routes/api.php` — remove the `heatmap` route line.
- `app/Http/Controllers/Analytics/AnalyticsController.php` — delete `getHeatmapData()`; update `getGeographicAnalytics()` to attach the new `metadata` block.
- `app/Services/Analytics/LinkAnalyticsOrchestrator.php` — delete `getHeatmapData()`; simplify `getComprehensiveLinkAnalytics()` to drop the redundant heatmap merge.
- `app/Services/Analytics/GeographicAnalyticsService.php` — make `getHeatmapData()` private; have `getLinkGeographicAnalytics()` return `['data' => […], 'metadata' => […]]`; add private `buildGeographicMetadata()` helper that includes `unique_states`.
- `app/Contracts/Analytics/GeographicAnalyticsInterface.php` — remove `getHeatmapData()` from the contract.
- `tests/Feature/Analytics/AnalyticsEndpointsTest.php` — drop the `heatmap` data provider entry; add a dedicated test for `/geographic`'s new shape and a 404 test for the removed `/heatmap` URL.
- `tests/Feature/Analytics/AnalyticsStructureTest.php` — update the existing heatmap-structure test to go through `getLinkGeographicAnalytics()` (the public path), since `getHeatmapData()` is private.

### Frontend — modified
- `src/features/links/components/analytics/LinkAnalyticsTabs.tsx` — drop the Heatmap tab entry, its panel, and the import.
- `src/features/analytics/components/geographic/GeographicAnalysis.tsx` — full refactor: top-level metric cards + 4 MUI sub-tabs.
- `src/features/analytics/components/geographic/GeographicMetrics.tsx` — props change to consume the new `metadata` shape; remove the legacy fallback chains.
- `src/features/analytics/components/geographic/index.ts` — re-export the relocated `RealTimeHeatmapChart`.
- `src/features/analytics/hooks/useGeographicData.ts` — extend the response shape to surface `metadata`; rebuild `GeographicStats` from it.
- `src/types/analytics/geographic.ts` — add `GeographicMetadata` interface; extend `GeographicData` with an optional `metadata` field at the top level (or surface it on the hook return — see Task 6).
- `src/services/analytics.service.ts` — remove `getLinkHeatmap()`; remove the unused `HeatmapPoint` import.
- `src/lib/query/keys.ts` — remove `heatmap` key under `analytics`.
- `src/lib/api/endpoints.ts` — remove `ANALYTICS_HEATMAP` constant.
- `src/lib/i18n/locales/pt-BR/links.json` — drop `analytics.tabs.heatmap`.
- `src/lib/i18n/locales/en/links.json` — drop `analytics.tabs.heatmap`.
- `src/lib/i18n/locales/pt-BR/analytics.json` — drop the `heatmap` block at the root and the inner `tabs.heatmap`; add `geographic.subtabs.{overview,heatmap,rankings,insights}`.
- `src/lib/i18n/locales/en/analytics.json` — same as above.

### Frontend — moved
- `src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx` → `src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx`
- `src/features/analytics/components/heatmap/HeatmapMap.tsx` → `src/features/analytics/components/geographic/HeatmapMap.tsx`
- `src/features/analytics/components/heatmap/HeatmapControls.tsx` → `src/features/analytics/components/geographic/HeatmapControls.tsx`

### Frontend — deleted
- `src/features/analytics/components/heatmap/HeatmapAnalysis.tsx`
- `src/features/analytics/components/heatmap/HeatmapMetrics.tsx`
- `src/features/analytics/components/heatmap/HeatmapStats.tsx`
- `src/features/analytics/components/heatmap/index.ts`
- `src/features/analytics/components/heatmap/` (empty directory after the moves above)
- `src/features/analytics/hooks/useHeatmapData.ts`

---

## Phase A — Backend (TDD: tests first)

### Task 1: Update backend tests for the new shape (RED)

**Files:**
- Modify: `backend/tests/Feature/Analytics/AnalyticsEndpointsTest.php`
- Modify: `backend/tests/Feature/Analytics/AnalyticsStructureTest.php`

- [ ] **Step 1.1: Drop the `heatmap` data-provider entry and add a dedicated geographic-shape test plus a 404 test for the removed route**

In `backend/tests/Feature/Analytics/AnalyticsEndpointsTest.php`, replace the data provider and append two new test methods.

Replace lines 35–47 (`analyticsEndpoints()` method) with:

```php
    /** @return array<string, array{string}> */
    public static function analyticsEndpoints(): array
    {
        return [
            'dashboard'    => ['/api/analytics/link/%d/dashboard'],
            'comprehensive' => ['/api/analytics/link/%d/comprehensive'],
            'geographic'   => ['/api/analytics/link/%d/geographic'],
            'insights'     => ['/api/analytics/link/%d/insights'],
            'temporal'     => ['/api/analytics/link/%d/temporal'],
            'audience'     => ['/api/analytics/link/%d/audience'],
        ];
    }
```

Append the following two tests inside the class (before the closing `}`):

```php
    public function test_geographic_endpoint_returns_data_and_metadata_blocks(): void
    {
        \App\Models\Click::factory()->count(3)->create([
            'link_id'   => $this->link->id,
            'latitude'  => -23.5,
            'longitude' => -46.6,
            'country'   => 'Brazil',
            'iso_code'  => 'BR',
            'state'     => 'SP',
            'state_name'=> 'São Paulo',
            'city'      => 'São Paulo',
            'continent' => 'SA',
        ]);

        $url = sprintf('/api/analytics/link/%d/geographic', $this->link->id);

        $this->getJson($url, $this->auth())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'heatmap_data',
                    'top_countries',
                    'top_states',
                    'top_cities',
                    'continents',
                ],
                'metadata' => [
                    'total_clicks',
                    'unique_countries',
                    'unique_states',
                    'unique_cities',
                    'max_clicks',
                    'total_locations',
                    'last_updated',
                    'link_info' => ['id', 'title', 'short_url', 'is_active'],
                ],
            ]);
    }

    public function test_removed_heatmap_endpoint_returns_404(): void
    {
        $url = sprintf('/api/analytics/link/%d/heatmap', $this->link->id);

        $this->getJson($url, $this->auth())->assertStatus(404);
    }
```

- [ ] **Step 1.2: Update the structure test to call the public path**

In `backend/tests/Feature/Analytics/AnalyticsStructureTest.php`, replace the body of `test_geographic_service_heatmap_returns_valid_structure()` (lines 100–118) with:

```php
    public function test_geographic_service_returns_heatmap_data_inside_link_geographic_payload(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create([
            'link_id'   => $link->id,
            'latitude'  => -23.5,
            'longitude' => -46.6,
            'country'   => 'Brazil',
        ]);

        $payload = app(GeographicAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('metadata', $payload);
        $this->assertArrayHasKey('heatmap_data', $payload['data']);
        $this->assertIsArray($payload['data']['heatmap_data']);

        if (count($payload['data']['heatmap_data']) > 0) {
            $point = $payload['data']['heatmap_data'][0];
            $this->assertArrayHasKey('lat', $point);
            $this->assertArrayHasKey('lng', $point);
            $this->assertArrayHasKey('clicks', $point);
        }

        $this->assertArrayHasKey('unique_states', $payload['metadata']);
        $this->assertArrayHasKey('link_info', $payload['metadata']);
    }
```

Replace the existing `test_orchestrator_all_public_methods_return_correct_top_level_keys()` assertion that uses `top_countries` (line 128). The new shape nests it under `data`. Change the line:

```php
$this->assertArrayHasKey('top_countries', $orch->getLinkGeographicAnalytics($link->id));
```

to:

```php
$this->assertArrayHasKey('top_countries', $orch->getLinkGeographicAnalytics($link->id)['data']);
```

- [ ] **Step 1.3: Run the tests and verify they fail in the expected way**

Run:
```bash
docker-compose exec app ./vendor/bin/phpunit --filter "Analytics"
```

Expected: failures (RED) coming from
- `test_geographic_endpoint_returns_data_and_metadata_blocks` — `metadata` is missing from the current response.
- `test_removed_heatmap_endpoint_returns_404` — current code still returns 200 (route still exists).
- `test_geographic_service_returns_heatmap_data_inside_link_geographic_payload` — top-level `data`/`metadata` not present yet.
- `test_orchestrator_all_public_methods_return_correct_top_level_keys` — current shape is flat.

These RED failures confirm the tests reflect the target shape before any implementation.

- [ ] **Step 1.4: Commit the failing tests**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add tests/Feature/Analytics/AnalyticsEndpointsTest.php tests/Feature/Analytics/AnalyticsStructureTest.php
git commit -m "test(analytics): assert /geographic metadata shape and 404 for /heatmap"
```

---

### Task 2: Update the contract and service (GREEN — service layer)

**Files:**
- Modify: `backend/app/Contracts/Analytics/GeographicAnalyticsInterface.php`
- Modify: `backend/app/Services/Analytics/GeographicAnalyticsService.php`

- [ ] **Step 2.1: Remove `getHeatmapData()` from the interface**

Replace the entire content of `backend/app/Contracts/Analytics/GeographicAnalyticsInterface.php` with:

```php
<?php

namespace App\Contracts\Analytics;

interface GeographicAnalyticsInterface
{
    public function getLinkGeographicAnalytics(int $linkId): array;
}
```

- [ ] **Step 2.2: Make `getHeatmapData()` private and produce the unified `data + metadata` payload**

In `backend/app/Services/Analytics/GeographicAnalyticsService.php`:

- Change the visibility of `getHeatmapData()` from `public` to `private` (line 43).
- Replace `getLinkGeographicAnalytics()` (lines 21–41) with the version below.
- Append a new private helper `buildGeographicMetadata()`.
- Append a new private helper `linkInfo()`.

Replace lines 21–41 with:

```php
    public function getLinkGeographicAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        if (! Click::where('link_id', $linkId)->exists()) {
            return [
                'data' => [
                    'heatmap_data'  => [],
                    'top_countries' => [],
                    'top_states'    => [],
                    'top_cities'    => [],
                    'continents'    => [],
                ],
                'metadata' => $this->buildGeographicMetadata($link, []),
            ];
        }

        $heatmap = $this->getHeatmapData($linkId);

        return [
            'data' => [
                'heatmap_data'  => $heatmap,
                'top_countries' => $this->getTopCountriesOptimized($linkId),
                'top_states'    => $this->getTopStatesOptimized($linkId),
                'top_cities'    => $this->getTopCitiesOptimized($linkId),
                'continents'    => $this->getTopContinents($linkId),
            ],
            'metadata' => $this->buildGeographicMetadata($link, $heatmap),
        ];
    }
```

Append the helpers immediately above the closing `}` of the class:

```php
    private function buildGeographicMetadata(Link $link, array $heatmap): array
    {
        $countries = array_filter(array_column($heatmap, 'country'));
        $states    = array_filter(array_column($heatmap, 'state_name'));
        $cities    = array_filter(array_column($heatmap, 'city'));
        $clicks    = array_column($heatmap, 'clicks');

        return [
            'total_clicks'      => array_sum($clicks),
            'unique_countries'  => count(array_unique($countries)),
            'unique_states'     => count(array_unique($states)),
            'unique_cities'     => count(array_unique($cities)),
            'max_clicks'        => $clicks ? max($clicks) : 0,
            'total_locations'   => count($heatmap),
            'last_updated'      => now()->toISOString(),
            'link_info'         => $this->linkInfo($link),
        ];
    }

    private function linkInfo(Link $link): array
    {
        return [
            'id'        => $link->id,
            'title'     => $link->title,
            'short_url' => $link->getShortedUrl(),
            'is_active' => $link->is_active,
        ];
    }
```

- [ ] **Step 2.3: Commit the service-layer change**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add app/Contracts/Analytics/GeographicAnalyticsInterface.php app/Services/Analytics/GeographicAnalyticsService.php
git commit -m "refactor(analytics): unify geographic payload with metadata block"
```

---

### Task 3: Update the orchestrator, controller, and route (GREEN — HTTP layer)

**Files:**
- Modify: `backend/app/Services/Analytics/LinkAnalyticsOrchestrator.php`
- Modify: `backend/app/Http/Controllers/Analytics/AnalyticsController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 3.1: Drop `getHeatmapData()` from the orchestrator and simplify `getComprehensiveLinkAnalytics`**

In `backend/app/Services/Analytics/LinkAnalyticsOrchestrator.php`:

Delete the method `getHeatmapData()` entirely (lines 85–88).

Replace the `geographic` line inside `getComprehensiveLinkAnalytics()` (lines 50–53):

```php
            'geographic' => array_merge(
                ['heatmap_data' => $this->geographic->getHeatmapData($linkId)],
                $this->geographic->getLinkGeographicAnalytics($linkId)
            ),
```

with:

```php
            'geographic' => $this->geographic->getLinkGeographicAnalytics($linkId),
```

- [ ] **Step 3.2: Delete `getHeatmapData()` from the controller and attach `metadata` on `getGeographicAnalytics()`**

In `backend/app/Http/Controllers/Analytics/AnalyticsController.php`:

Delete the entire method `getHeatmapData()` (lines 43–81, including its docblock).

Replace `getGeographicAnalytics()` (lines 86–101) with:

```php
    /**
     * Analytics geográficos detalhados — payload unificado (data + metadata)
     */
    public function getGeographicAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $payload = $this->analyticsService->getLinkGeographicAnalytics($linkId);

            return response()->json([
                'success'  => true,
                'data'     => $payload['data'],
                'metadata' => $payload['metadata'],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics geográficos.', $e);
        }
    }
```

- [ ] **Step 3.3: Remove the heatmap route**

In `backend/routes/api.php`, delete line 119 (the `heatmap` route entry inside the `analytics/link` prefix group):

```php
        Route::get('/{linkId}/heatmap', 'getHeatmapData')->where('linkId', '[0-9]+');             // ✅ USADO: useHeatmapData
```

- [ ] **Step 3.4: Run the full analytics test suite — should be GREEN**

Run:
```bash
docker-compose exec app ./vendor/bin/phpunit --filter "Analytics"
```

Expected: all tests pass.

- [ ] **Step 3.5: Run the entire backend suite to catch regressions**

Run:
```bash
docker-compose exec app ./vendor/bin/phpunit
```

Expected: green.

- [ ] **Step 3.6: Commit the HTTP-layer change**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add app/Services/Analytics/LinkAnalyticsOrchestrator.php app/Http/Controllers/Analytics/AnalyticsController.php routes/api.php
git commit -m "feat(analytics): remove /heatmap endpoint, return metadata on /geographic"
```

---

## Phase B — Frontend

### Task 4: Extend the geographic types and hook to surface `metadata`

**Files:**
- Modify: `frontend-next/src/types/analytics/geographic.ts`
- Modify: `frontend-next/src/features/analytics/hooks/useGeographicData.ts`

- [ ] **Step 4.1: Add `GeographicMetadata` and `GeographicResponse` types**

In `frontend-next/src/types/analytics/geographic.ts`, append (after the `ContinentData` interface and before the existing `GeographicStats` definition):

```ts
/**
 * Metadados retornados junto com /api/analytics/link/{id}/geographic
 */
export interface GeographicMetadata {
  total_clicks: number;
  unique_countries: number;
  unique_states: number;
  unique_cities: number;
  max_clicks: number;
  total_locations: number;
  last_updated: string;
  link_info: {
    id: number;
    title: string;
    short_url: string;
    is_active: boolean;
  };
}

/**
 * Envelope completo da resposta /geographic.
 * Espelha exatamente { data, metadata } retornado pelo backend.
 */
export interface GeographicResponse {
  data: GeographicData;
  metadata: GeographicMetadata;
}
```

- [ ] **Step 4.2: Update the hook to consume `{ data, metadata }` and rebuild `GeographicStats` from metadata**

Replace the entire content of `frontend-next/src/features/analytics/hooks/useGeographicData.ts` with:

```ts
"use client";

import { useMemo } from "react";
import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { queryKeys } from "@/lib/query/keys";
import { API_CONFIG } from "@/lib/api/endpoints";

import type {
  GeographicData,
  GeographicMetadata,
  GeographicResponse,
} from "@/types/analytics/geographic";

export interface GeographicStats {
  totalCountries: number;
  totalStates: number;
  totalCities: number;
  totalClicks: number;
  maxClicks: number;
  totalLocations: number;
  coveragePercentage: number;
  lastUpdate: string;
}

export interface UseGeographicDataOptions {
  linkId: string;
  refreshInterval?: number;
  enableRealtime?: boolean;
  includeHeatmap?: boolean;
  minClicks?: number;
}

export interface UseGeographicDataReturn {
  data: GeographicData | null;
  metadata: GeographicMetadata | null;
  stats: GeographicStats | null;
  loading: boolean;
  error: string | null;
  refresh: () => void;
  isRealtime: boolean;
}

function calculateStats(metadata: GeographicMetadata): GeographicStats {
  return {
    totalCountries: metadata.unique_countries,
    totalStates: metadata.unique_states,
    totalCities: metadata.unique_cities,
    totalClicks: metadata.total_clicks,
    maxClicks: metadata.max_clicks,
    totalLocations: metadata.total_locations,
    coveragePercentage:
      metadata.unique_countries > 0
        ? Math.min((metadata.unique_countries / 195) * 100, 100)
        : 0,
    lastUpdate: metadata.last_updated,
  };
}

export function useGeographicData({
  linkId,
  refreshInterval = 30000,
  enableRealtime = false,
  minClicks = 1,
}: UseGeographicDataOptions): UseGeographicDataReturn {
  const {
    data: raw,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: queryKeys.analytics.geographic(linkId),
    queryFn: () =>
      api.get<GeographicResponse>(`/api/analytics/link/${linkId}/geographic`),
    staleTime: API_CONFIG.CACHE.ANALYTICS_TTL,
    refetchInterval: enableRealtime ? refreshInterval : false,
    enabled: !!linkId,
  });

  const data = useMemo<GeographicData | null>(() => {
    if (!raw?.data) return null;
    if (minClicks <= 1) return raw.data;
    return {
      ...raw.data,
      top_countries:
        raw.data.top_countries?.filter((c) => c.clicks >= minClicks) || [],
      top_states:
        raw.data.top_states?.filter((s) => s.clicks >= minClicks) || [],
      top_cities:
        raw.data.top_cities?.filter((c) => c.clicks >= minClicks) || [],
      heatmap_data:
        raw.data.heatmap_data?.filter((h) => h.clicks >= minClicks) || [],
    };
  }, [raw, minClicks]);

  const metadata = raw?.metadata ?? null;
  const stats = useMemo(
    () => (metadata ? calculateStats(metadata) : null),
    [metadata],
  );

  return {
    data,
    metadata,
    stats,
    loading: isLoading,
    error: error ? (error as Error).message : null,
    refresh: refetch,
    isRealtime: enableRealtime,
  };
}

export default useGeographicData;
```

- [ ] **Step 4.3: Commit the type and hook changes**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add src/types/analytics/geographic.ts src/features/analytics/hooks/useGeographicData.ts
git commit -m "feat(analytics): surface metadata block on geographic hook"
```

---

### Task 5: Update `GeographicMetrics` to consume the new metadata-derived stats

**Files:**
- Modify: `frontend-next/src/features/analytics/components/geographic/GeographicMetrics.tsx`

- [ ] **Step 5.1: Replace the component body with a metadata-driven version**

Replace the entire content of `frontend-next/src/features/analytics/components/geographic/GeographicMetrics.tsx` with:

```tsx
"use client";
import { Globe, Building2, TrendingUp, MapPin, Layers } from "lucide-react";
import { Grid, Box, Typography, useTheme } from "@mui/material";
import { useTranslation } from "react-i18next";

import { ICON_LG } from "@/lib/theme/iconDefaults";
import { createPresetAnimations } from "@/lib/theme";
import { MetricCardOptimized as MetricCard } from "@/shared/ui/base/MetricCardOptimized";

import type { GeographicStats } from "../../hooks/useGeographicData";

interface GeographicMetricsProps {
  stats: GeographicStats | null;
  showTitle?: boolean;
  title?: string;
}

export function GeographicMetrics({
  stats,
  showTitle = false,
  title,
}: GeographicMetricsProps) {
  const theme = useTheme();
  const { t } = useTranslation("analytics");
  const animations = createPresetAnimations(theme);

  const displayTitle = title ?? t("geographic.metrics.title");

  const metrics = [
    {
      id: "countries_reached",
      title: t("geographic.metrics.countriesReached"),
      value: (stats?.totalCountries ?? 0).toString(),
      icon: <Globe {...ICON_LG} />,
      color: "primary" as const,
      subtitle: t("geographic.metrics.globalReach"),
    },
    {
      id: "states_reached",
      title: t("geographic.metrics.statesRegions"),
      value: (stats?.totalStates ?? 0).toString(),
      icon: <Layers {...ICON_LG} />,
      color: "success" as const,
      subtitle: t("geographic.metrics.regionalCoverage"),
    },
    {
      id: "cities_reached",
      title: t("geographic.metrics.citiesReached"),
      value: (stats?.totalCities ?? 0).toString(),
      icon: <Building2 {...ICON_LG} />,
      color: "info" as const,
      subtitle: t("geographic.metrics.urbanDiversity"),
    },
    {
      id: "total_clicks",
      title: t("geographic.metrics.geographicClicks"),
      value: (stats?.totalClicks ?? 0).toLocaleString(),
      icon: <TrendingUp {...ICON_LG} />,
      color: "warning" as const,
      subtitle: t("geographic.metrics.mappedClicks"),
    },
    {
      id: "coverage",
      title: t("geographic.metrics.coverage"),
      value: `${Math.round(stats?.coveragePercentage ?? 0)}%`,
      icon: <MapPin {...ICON_LG} />,
      color: "secondary" as const,
      subtitle: t("geographic.metrics.continentsCoverage"),
    },
  ];

  return (
    <Box sx={{ mb: 3 }}>
      {showTitle ? (
        <Typography variant="h6" sx={{ mb: 2, fontWeight: 600 }}>
          {displayTitle}
        </Typography>
      ) : null}

      <Grid container spacing={3} sx={{ ...animations.fadeIn }}>
        {metrics.map((metric) => (
          <Grid item xs={12} sm={6} md={2.4} key={metric.id}>
            <Box sx={{ height: "100%", ...animations.cardHover }}>
              <MetricCard
                title={metric.title}
                value={metric.value}
                icon={metric.icon}
                color={metric.color}
                subtitle={metric.subtitle}
              />
            </Box>
          </Grid>
        ))}
      </Grid>
    </Box>
  );
}

export default GeographicMetrics;
```

- [ ] **Step 5.2: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add src/features/analytics/components/geographic/GeographicMetrics.tsx
git commit -m "refactor(analytics): drive GeographicMetrics from metadata-backed stats"
```

---

### Task 6: Move `RealTimeHeatmapChart` (and its internals) into `geographic/`

**Files:**
- Move: `src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx` → `src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx`
- Move: `src/features/analytics/components/heatmap/HeatmapMap.tsx` → `src/features/analytics/components/geographic/HeatmapMap.tsx`
- Move: `src/features/analytics/components/heatmap/HeatmapControls.tsx` → `src/features/analytics/components/geographic/HeatmapControls.tsx`
- Modify: `src/features/analytics/components/geographic/index.ts`

Both `HeatmapMap` and `HeatmapControls` are internal collaborators of `RealTimeHeatmapChart` and must travel with it; their `import type { HeatmapPoint } from "@/types"` continues to resolve, since the type lives in `src/types/core/api.ts`.

- [ ] **Step 6.1: Move the three files using `git mv`**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git mv src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx
git mv src/features/analytics/components/heatmap/HeatmapMap.tsx src/features/analytics/components/geographic/HeatmapMap.tsx
git mv src/features/analytics/components/heatmap/HeatmapControls.tsx src/features/analytics/components/geographic/HeatmapControls.tsx
```

- [ ] **Step 6.2: Re-export the relocated chart from the geographic index**

Replace the content of `src/features/analytics/components/geographic/index.ts` with:

```ts
import dynamic from "next/dynamic";

export { GeographicAnalysis } from "./GeographicAnalysis";
export { GeographicChart } from "./GeographicChart";
export { GeographicChoropleth } from "./GeographicChoropleth";
export { GeographicInsights } from "./GeographicInsights";
export { GeographicMetrics } from "./GeographicMetrics";
export { ContinentBreakdown } from "./ContinentBreakdown";

export const RealTimeHeatmapChart = dynamic(
  () =>
    import("./RealTimeHeatmapChart").then((m) => ({
      default: m.RealTimeHeatmapChart,
    })),
  { ssr: false, loading: () => null },
);

// Hook
export { useGeographicData } from "../../hooks/useGeographicData";

// Tipos
export type {
  GeographicStats,
  UseGeographicDataOptions,
  UseGeographicDataReturn,
} from "../../hooks/useGeographicData";
```

- [ ] **Step 6.3: Sanity-check that the relocated chart still type-checks in isolation**

Run:
```bash
cd /Users/bruno/Projects/link-charts/frontend-next
npm run type-check
```

Expected: errors will appear in `HeatmapAnalysis.tsx` and `LinkAnalyticsTabs.tsx` (since they still import from the heatmap directory). These are addressed in subsequent tasks. The relocated files themselves should compile.

- [ ] **Step 6.4: Commit the move**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add src/features/analytics/components/geographic/index.ts src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx src/features/analytics/components/geographic/HeatmapMap.tsx src/features/analytics/components/geographic/HeatmapControls.tsx
git commit -m "refactor(analytics): relocate RealTimeHeatmapChart under geographic module"
```

---

### Task 7: Refactor `GeographicAnalysis.tsx` to use sub-tabs

**Files:**
- Modify: `frontend-next/src/features/analytics/components/geographic/GeographicAnalysis.tsx`

- [ ] **Step 7.1: Replace the component body with the sub-tab layout**

Replace the entire content of `frontend-next/src/features/analytics/components/geographic/GeographicAnalysis.tsx` with:

```tsx
"use client";
import { useState } from "react";
import { Globe, Map, BarChart3, Lightbulb, Layers } from "lucide-react";
import { Box, Grid, Tab, Tabs } from "@mui/material";
import { useTranslation } from "react-i18next";

import { ICON_LG, ICON_SM } from "@/lib/theme/iconDefaults";
import AnalyticsStateManager from "@/shared/ui/base/AnalyticsStateManager";
import TabDescription from "@/shared/ui/base/TabDescription";

import { useGeographicData } from "../../hooks/useGeographicData";

import { ContinentBreakdown } from "./ContinentBreakdown";
import { GeographicChart } from "./GeographicChart";
import { GeographicChoropleth } from "./GeographicChoropleth";
import { GeographicInsights } from "./GeographicInsights";
import { GeographicMetrics } from "./GeographicMetrics";
import { RealTimeHeatmapChart } from "./index";

interface GeographicAnalysisProps {
  linkId: string;
  title?: string;
  enableRealtime?: boolean;
  minClicks?: number;
}

export function GeographicAnalysis({
  linkId,
  title,
  enableRealtime = false,
  minClicks = 1,
}: GeographicAnalysisProps) {
  const { t } = useTranslation("analytics");
  const displayTitle = title ?? t("geographic.title");
  const [activeSubTab, setActiveSubTab] = useState(0);
  const [selectedCountry, setSelectedCountry] = useState<string | null>(null);

  const { data, stats, loading, error, refresh, isRealtime } =
    useGeographicData({
      linkId,
      enableRealtime,
      minClicks,
      includeHeatmap: true,
      refreshInterval: 30000,
    });

  const handleSubTabChange = (
    _event: React.SyntheticEvent,
    newValue: number,
  ) => {
    setActiveSubTab(newValue);
  };

  const hasHeatmapData = (data?.heatmap_data?.length ?? 0) > 0;
  const hasRankings =
    (data?.top_countries?.length ?? 0) > 0 ||
    (data?.top_states?.length ?? 0) > 0 ||
    (data?.top_cities?.length ?? 0) > 0;
  const hasContinents = (data?.continents?.length ?? 0) > 0;
  const hasInsightInputs = hasHeatmapData || hasRankings;

  return (
    <Box>
      {/* Cabeçalho do módulo */}
      <Box sx={{ mb: 3 }}>
        <TabDescription
          icon={<Globe {...ICON_LG} />}
          title={displayTitle}
          description={t("geographic.description")}
          highlight={t("geographic.countriesReached", {
            count: stats?.totalCountries || 0,
          })}
          metadata={isRealtime ? t("dashboard.realtime") : undefined}
        />
      </Box>

      <AnalyticsStateManager
        loading={loading}
        error={error}
        hasData={!!data}
        onRetry={refresh}
        loadingMessage={t("geographic.loading")}
        emptyMessage={t("geographic.empty")}
        minHeight={300}
      >
        <Box>
          {/* 5 metric cards no topo, fora das sub-tabs */}
          <GeographicMetrics
            stats={stats}
            showTitle
            title={t("geographic.metrics.title")}
          />

          {/* Sub-tabs */}
          <Box sx={{ borderBottom: 1, borderColor: "divider", mb: 3 }}>
            <Tabs
              value={activeSubTab}
              onChange={handleSubTabChange}
              variant="scrollable"
              scrollButtons="auto"
            >
              <Tab
                label={t("geographic.subtabs.overview")}
                icon={<Layers {...ICON_SM} />}
                iconPosition="start"
                disabled={!hasContinents && !hasRankings}
              />
              <Tab
                label={t("geographic.subtabs.heatmap")}
                icon={<Map {...ICON_SM} />}
                iconPosition="start"
                disabled={!hasHeatmapData}
              />
              <Tab
                label={t("geographic.subtabs.rankings")}
                icon={<BarChart3 {...ICON_SM} />}
                iconPosition="start"
                disabled={!hasRankings}
              />
              <Tab
                label={t("geographic.subtabs.insights")}
                icon={<Lightbulb {...ICON_SM} />}
                iconPosition="start"
                disabled={!hasInsightInputs}
              />
            </Tabs>
          </Box>

          {/* Sub-tab 0: Visão geral */}
          {activeSubTab === 0 && (
            <Grid container spacing={3}>
              <Grid item xs={12} md={8}>
                <GeographicChoropleth
                  countries={data?.top_countries || []}
                  selectedCountry={selectedCountry}
                  onCountrySelect={setSelectedCountry}
                />
              </Grid>
              <Grid item xs={12} md={4}>
                <ContinentBreakdown continents={data?.continents || []} />
              </Grid>
            </Grid>
          )}

          {/* Sub-tab 1: Mapa de calor */}
          {activeSubTab === 1 && hasHeatmapData && (
            <RealTimeHeatmapChart
              data={data?.heatmap_data || []}
              loading={loading}
              error={error}
              onRefresh={refresh}
              height={700}
              title={t("geographic.subtabs.heatmap")}
              showControls
              showStats={false}
            />
          )}

          {/* Sub-tab 2: Rankings com drill-down */}
          {activeSubTab === 2 && (
            <GeographicChart
              countries={data?.top_countries || []}
              states={data?.top_states || []}
              cities={data?.top_cities || []}
              totalClicks={stats?.totalClicks || 0}
              selectedCountry={selectedCountry}
              onCountrySelect={setSelectedCountry}
            />
          )}

          {/* Sub-tab 3: Insights */}
          {activeSubTab === 3 && (
            <GeographicInsights
              data={data?.heatmap_data || []}
              countries={data?.top_countries || []}
              states={data?.top_states || []}
              cities={data?.top_cities || []}
            />
          )}
        </Box>
      </AnalyticsStateManager>
    </Box>
  );
}

export default GeographicAnalysis;
```

Note: `RealTimeHeatmapChart` is imported from the local `./index` module so the existing `next/dynamic` lazy-load (`ssr: false, loading: () => null`) is preserved — the map library is downloaded only when the user opens sub-tab 1.

The `RealTimeHeatmapChart` props no longer include `stats` because the chart originally took a heatmap-shaped stats prop that does not exist anymore. Confirm by inspecting the current component to ensure `stats` is optional (it is — see `frontend-next/src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx`, where `stats` was a `HeatmapStats | null` accepted but only used for the now-removed `showStats` panel). If a TypeScript error arises in the next step, drop the unused `stats` from the chart's prop type as part of the same task.

- [ ] **Step 7.2: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add src/features/analytics/components/geographic/GeographicAnalysis.tsx
git commit -m "feat(analytics): geographic tab with sub-tabs replacing heatmap tab"
```

---

### Task 8: Remove the Heatmap entry from the parent tabs and delete the heatmap module

**Files:**
- Modify: `frontend-next/src/features/links/components/analytics/LinkAnalyticsTabs.tsx`
- Delete: `frontend-next/src/features/analytics/components/heatmap/HeatmapAnalysis.tsx`
- Delete: `frontend-next/src/features/analytics/components/heatmap/HeatmapMetrics.tsx`
- Delete: `frontend-next/src/features/analytics/components/heatmap/HeatmapStats.tsx`
- Delete: `frontend-next/src/features/analytics/components/heatmap/index.ts`
- Delete: `frontend-next/src/features/analytics/hooks/useHeatmapData.ts`

- [ ] **Step 8.1: Remove the Heatmap entry from `LinkAnalyticsTabs.tsx`**

In `frontend-next/src/features/links/components/analytics/LinkAnalyticsTabs.tsx`:

Remove the `Flame` import (line 10) — keep the rest of the lucide-react imports intact:

Replace lines 5–13:
```ts
import {
  LayoutDashboard,
  Globe,
  Clock,
  Users,
  Flame,
  Lightbulb,
  MousePointer2,
} from "lucide-react";
```
with:
```ts
import {
  LayoutDashboard,
  Globe,
  Clock,
  Users,
  Lightbulb,
  MousePointer2,
} from "lucide-react";
```

Remove the import on line 21:
```ts
import { HeatmapAnalysis } from "@/features/analytics/components/heatmap/HeatmapAnalysis";
```

Replace `tabLabels` (lines 71–79) with:
```ts
  const tabLabels = [
    { label: t("analytics.tabs.overview"), Icon: LayoutDashboard },
    { label: t("analytics.tabs.geographic"), Icon: Globe },
    { label: t("analytics.tabs.temporal"), Icon: Clock },
    { label: t("analytics.tabs.audience"), Icon: Users },
    { label: t("analytics.tabs.insights"), Icon: Lightbulb },
    { label: t("analytics.clicksTable.title"), Icon: MousePointer2 },
  ];
```

Replace the panels region — remove the Heatmap panel and shift Insights / ClicksTable indices. Replace lines 165–193 with:

```tsx
      {/* Audiência Tab */}
      <TabPanel value={tabValue} index={3}>
        {/* Renderizar apenas se a tab está ativa */}
        {tabValue === 3 && <AudienceAnalysis linkId={linkId} />}
      </TabPanel>

      {/* Insights Tab */}
      <TabPanel value={tabValue} index={4}>
        {/* Renderizar apenas se a tab está ativa */}
        {tabValue === 4 && (
          <InsightsAnalysis
            linkId={linkId}
            enableRealtime={false}
            maxInsights={10}
          />
        )}
      </TabPanel>

      {/* Cliques Tab */}
      <TabPanel value={tabValue} index={5}>
        {/* Renderizar apenas se a tab está ativa */}
        {tabValue === 5 && <ClicksTable linkId={linkId} />}
      </TabPanel>
```

- [ ] **Step 8.2: Delete the now-orphaned heatmap files and the empty directory**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git rm src/features/analytics/components/heatmap/HeatmapAnalysis.tsx
git rm src/features/analytics/components/heatmap/HeatmapMetrics.tsx
git rm src/features/analytics/components/heatmap/HeatmapStats.tsx
git rm src/features/analytics/components/heatmap/index.ts
git rm src/features/analytics/hooks/useHeatmapData.ts
rmdir src/features/analytics/components/heatmap
```

- [ ] **Step 8.3: Verify no dangling references to the deleted module**

Run:
```bash
cd /Users/bruno/Projects/link-charts/frontend-next
grep -rn "from \"@/features/analytics/components/heatmap\|useHeatmapData\|HeatmapAnalysis\|HeatmapMetrics\|HeatmapStats" src
```

Expected: no results.

- [ ] **Step 8.4: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add -A src/features/links/components/analytics/LinkAnalyticsTabs.tsx src/features/analytics
git commit -m "feat(analytics): drop standalone heatmap tab and module"
```

---

### Task 9: Clean up the analytics service, query keys, endpoint constant, and i18n catalogs

**Files:**
- Modify: `frontend-next/src/services/analytics.service.ts`
- Modify: `frontend-next/src/lib/query/keys.ts`
- Modify: `frontend-next/src/lib/api/endpoints.ts`
- Modify: `frontend-next/src/lib/i18n/locales/pt-BR/links.json`
- Modify: `frontend-next/src/lib/i18n/locales/en/links.json`
- Modify: `frontend-next/src/lib/i18n/locales/pt-BR/analytics.json`
- Modify: `frontend-next/src/lib/i18n/locales/en/analytics.json`

- [ ] **Step 9.1: Remove `getLinkHeatmap()` from the analytics service**

In `frontend-next/src/services/analytics.service.ts`:

Delete the `getLinkHeatmap()` method (lines 104–116) — including its leading `/** ... */` docblock.

In the same file's import line (line 5), remove the `HeatmapPoint` symbol if it has no other usage. Verify with:
```bash
grep -n "HeatmapPoint" frontend-next/src/services/analytics.service.ts
```
If the only remaining hit is the import, change line 5 from:
```ts
import type { AnalyticsData, HeatmapPoint } from "@/types";
```
to:
```ts
import type { AnalyticsData } from "@/types";
```

- [ ] **Step 9.2: Remove the `heatmap` query key**

In `frontend-next/src/lib/query/keys.ts`, delete the line:
```ts
heatmap: (id: string) => ["analytics", id, "heatmap"] as const,
```

- [ ] **Step 9.3: Remove the `ANALYTICS_HEATMAP` endpoint constant**

In `frontend-next/src/lib/api/endpoints.ts`, delete the `ANALYTICS_HEATMAP` line (around line 73) and any trailing comma cleanup needed to keep the surrounding object literal valid.

Verify no other consumer:
```bash
grep -rn "ANALYTICS_HEATMAP" /Users/bruno/Projects/link-charts/frontend-next/src
```
Expected: no results.

- [ ] **Step 9.4: Remove the `heatmap` tab label from both `links.json` catalogs**

In `frontend-next/src/lib/i18n/locales/pt-BR/links.json`, locate the `analytics.tabs` block (around line 160–168) and delete the `"heatmap": "Mapa de Calor"` line. Make sure the preceding line ends without a trailing comma.

Apply the same change to `frontend-next/src/lib/i18n/locales/en/links.json` (delete `"heatmap": "Heatmap"`).

- [ ] **Step 9.5: Remove the standalone `heatmap` block from both `analytics.json` catalogs and add the `geographic.subtabs` keys**

In `frontend-next/src/lib/i18n/locales/pt-BR/analytics.json`:

- Delete the `"heatmap": "Mapa de Calor de Cliques"` line inside the top-level `dashboard` description set (around line 41) and the `"heatmap": "Mapa de Calor"` inside `tabs` (around line 50).
- Delete the entire `"heatmap": { ... }` block at root scope (starting around line 541) — the one that owns `title`, `description`, `metrics`, `stats`, `chart`, `controls`, `locationsCount`, `loading`, `noData`, `empty`, `realtime`, `updated`. Removing this block cleans every key formerly consumed by the deleted heatmap components.
- Inside the `geographic` block, add a new `subtabs` object:

```json
    "subtabs": {
      "overview": "Visão geral",
      "heatmap": "Mapa de calor",
      "rankings": "Rankings",
      "insights": "Insights"
    },
```

Apply the parallel changes to `frontend-next/src/lib/i18n/locales/en/analytics.json`, with English values:

```json
    "subtabs": {
      "overview": "Overview",
      "heatmap": "Heatmap",
      "rankings": "Rankings",
      "insights": "Insights"
    },
```

Validate that both files are still valid JSON:
```bash
cd /Users/bruno/Projects/link-charts/frontend-next
node -e "JSON.parse(require('fs').readFileSync('src/lib/i18n/locales/pt-BR/analytics.json','utf8'))"
node -e "JSON.parse(require('fs').readFileSync('src/lib/i18n/locales/en/analytics.json','utf8'))"
node -e "JSON.parse(require('fs').readFileSync('src/lib/i18n/locales/pt-BR/links.json','utf8'))"
node -e "JSON.parse(require('fs').readFileSync('src/lib/i18n/locales/en/links.json','utf8'))"
```
Expected: each invocation prints nothing and exits 0.

- [ ] **Step 9.6: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add src/services/analytics.service.ts src/lib/query/keys.ts src/lib/api/endpoints.ts src/lib/i18n/locales
git commit -m "chore(analytics): purge heatmap service, query key, endpoint and i18n keys"
```

---

### Task 10: Frontend quality gate and manual validation

**Files:**
- Verify: full frontend tree

- [ ] **Step 10.1: Run the project quality gate**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
npm run quality
```

Expected: green (type-check + lint + format:check). Fix any reported issue inline before continuing.

- [ ] **Step 10.2: Start the backend and frontend dev servers**

In one terminal:
```bash
cd /Users/bruno/Projects/link-charts/backend
docker-compose up -d
docker-compose exec app php artisan optimize:clear
```

In another terminal:
```bash
cd /Users/bruno/Projects/link-charts/frontend-next
npm run dev
```

- [ ] **Step 10.3: Manual validation against the live page**

Open `http://localhost:3000/links/analytics/68` in a browser. Confirm each of the following:

1. The top tab strip shows: **Visão Geral, Geográfico, Temporal, Audiência, Insights, Cliques** — i.e. **no Heatmap tab**.
2. Click **Geográfico**. Verify five metric cards render at the top: countries, states, cities, total clicks, coverage %.
3. Verify the four sub-tabs are present and ordered: **Visão geral, Mapa de calor, Rankings, Insights**.
4. Sub-tab **Visão geral** shows the choropleth (8/12) and the continent breakdown (4/12).
5. Sub-tab **Mapa de calor** loads the interactive map; observe in DevTools → Network that the chart bundle only loads after this sub-tab is opened.
6. Sub-tab **Rankings** shows the country → state → city drill-down chart.
7. Sub-tab **Insights** shows the text/recommendations.
8. Sub-tabs whose underlying data is empty are visibly **disabled** (greyed out), not hidden.
9. Open each of the sibling tabs (Visão Geral, Temporal, Audiência, Insights, Cliques) once and confirm none of them are broken by the index shift.
10. In a separate browser tab, hit `http://localhost:8000/api/analytics/link/68/heatmap` directly — must return **404**.

- [ ] **Step 10.4: Final commit if any quality fix landed**

If `npm run quality` produced fixable warnings/changes, commit them:

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git status
# only commit if there are uncommitted changes from the quality run
git add -A
git commit -m "chore(analytics): satisfy quality gate after geographic refactor"
```

---

## Self-Review Checklist (already applied during plan authoring)

- **Spec coverage:** every requirement in the spec maps to a task — backend route removal (Task 3.3), service refactor (Task 2), interface update (Task 2.1), `metadata` block including `unique_states` (Task 2.2), controller wiring (Task 3.2), orchestrator simplification (Task 3.1), `LinkAnalyticsTabs` cleanup (Task 8.1), `GeographicAnalysis` sub-tab refactor (Task 7), `RealTimeHeatmapChart` relocation (Task 6), 5-card metric set driven by metadata (Task 5 + Task 4 hook), deletion of the heatmap module (Task 8.2), service / query-key / endpoint / i18n cleanup (Task 9), backend tests (Task 1), frontend quality + manual validation (Task 10).
- **Placeholder scan:** no TBD / TODO / vague "handle errors" / "similar to" — every code-bearing step has full code.
- **Type consistency:** `GeographicMetadata` shape is consistent across the spec, the backend `buildGeographicMetadata()`, the frontend `GeographicMetadata` interface, the hook's `GeographicResponse`, and the metric-card consumers. `unique_states` is present in all three layers. `GeographicStats` is rebuilt from metadata in one place (the hook) and consumed everywhere else as `GeographicStats | null`.
- **Branch safety:** the order keeps the tree compilable except for an explicitly-noted intermediate state in Step 6.3, which is resolved by Tasks 7 and 8.
