# Merge Heatmap tab into Geographic with sub-tabs

**Date:** 2026-05-07
**Scope:** `link-charts/backend` + `link-charts/frontend-next`
**Goal:** Eliminate redundancy between the Heatmap and Geographic analytics tabs by unifying them into a single Geographic tab with internal sub-tabs, and collapse the corresponding backend endpoints into one.

## Motivation

Today the analytics page (`/links/analytics/{id}`) exposes two tabs that overlap heavily:

- The **Heatmap** tab calls `/api/analytics/link/{id}/heatmap` and renders five metric cards, a rankings table (`HeatmapStats`), and a `RealTimeHeatmapChart`.
- The **Geographic** tab calls `/api/analytics/link/{id}/geographic` and renders five metric cards, a `GeographicChoropleth`, a `ContinentBreakdown`, a drill-down rankings chart (`GeographicChart`), and a text `GeographicInsights` block.

Both endpoints are served by the same `GeographicAnalyticsService`. The geographic response already contains the full `heatmap_data[]` array, so the heatmap endpoint is a strict subset duplicated on the wire. The rankings shown by `HeatmapStats` are also a subset of `GeographicChart`'s drill-down. The user has authorized a breaking change to remove `/heatmap` outright.

The Temporal tab establishes the desired UX pattern: a single tab with top-level metric cards plus internal MUI sub-tabs to organize multiple visualizations. We mirror that pattern for Geographic.

## Out of scope

- Adding new analytics dimensions (no new metrics, no new chart types).
- Caching layer (none today; not introduced here).
- Visual restyling of any individual chart component.
- Public-analytics page (`features/public-analytics/`); only the authenticated link analytics page is touched.
- Deep-link query params for tab selection — checked separately; no consumer relies on tab index 4.

## Backend design

### Endpoint shape

`/api/analytics/link/{id}/heatmap` is **removed**. `/api/analytics/link/{id}/geographic` becomes the single source of truth and absorbs the metadata wrapper that previously only existed on the heatmap response:

```json
{
  "data": {
    "heatmap_data":   [{ "lat", "lng", "city", "country", "clicks", "iso_code", "currency", "state_name", "continent", "timezone", "last_click" }],
    "top_countries":  [{ "country", "iso_code", "clicks", "currency" }],
    "top_states":     [{ "country", "state", "state_name", "clicks" }],
    "top_cities":     [{ "city", "country", "state", "clicks" }],
    "continents":     [{ "continent", "continent_name", "clicks", "percentage" }]
  },
  "metadata": {
    "total_clicks":      int,
    "unique_countries":  int,
    "unique_states":     int,
    "unique_cities":     int,
    "max_clicks":        int,
    "total_locations":   int,
    "last_updated":      ISO8601,
    "link_info":         { "id", "title", "short_url", "is_active" }
  }
}
```

The success/error envelope from `NormalizeApiResponse` middleware is preserved.

### Code changes

| File | Change |
|---|---|
| `routes/api.php` | Remove the `heatmap` route entry. |
| `app/Http/Controllers/Analytics/AnalyticsController.php` | Delete `getHeatmapData()` (lines 46–81). Update `getGeographicAnalytics()` to also build and attach the new `metadata` block (link_info + totals). |
| `app/Services/Analytics/LinkAnalyticsOrchestrator.php` | Delete `getHeatmapData()` (lines 85–88). |
| `app/Services/Analytics/GeographicAnalyticsService.php` | Convert `getHeatmapData()` from public to **private** (it remains the single producer of `heatmap_data[]`, called internally by `getLinkGeographicAnalytics()`). Add a private helper `buildGeographicMetadata(int $linkId, array $heatmapData): array` that produces the metadata block (totals derived from `$heatmapData` + a `link_info` lookup against `Link::find($linkId)`). `getLinkGeographicAnalytics()` returns `['data' => [...], 'metadata' => [...]]`. |
| `app/Contracts/Analytics/GeographicAnalyticsInterface.php` | Remove `getHeatmapData()` from the interface. |
| `tests/Feature/...` | If a heatmap endpoint test exists, delete it. Add or update a feature test for `/geographic` asserting the new top-level `metadata` block (presence of `link_info`, `total_clicks`, `unique_countries`, `unique_cities`, `max_clicks`, `total_locations`, `last_updated`). |

### Backend invariants preserved

- Auth middleware on `/geographic` is unchanged.
- Response envelope (`NormalizeApiResponse`) is unchanged.
- Database queries are unchanged (same aggregations as today).
- No new dependency.

## Frontend design

### Component tree (new)

```
GeographicAnalysis (refactored — top-level container)
├── GeographicMetricsRow (5 unified cards: Countries, States, Cities, Total clicks, Coverage %)
└── MUI <Tabs> (4 sub-tabs)
    ├── 0 "Visão geral"     → <GeographicChoropleth /> (8/12) + <ContinentBreakdown /> (4/12)
    ├── 1 "Mapa de calor"   → <RealTimeHeatmapChart /> (full width)
    ├── 2 "Rankings"        → <GeographicChart /> drill-down country→state→city (full width)
    └── 3 "Insights"        → <GeographicInsights />
```

State for the active sub-tab uses `useState(0)` and an `handleTabChange(_, newValue)` handler — exactly matching `TemporalChart.tsx:73,105-107`. Each `<Tab />` is `disabled` when the corresponding data array is empty/missing, mirroring Temporal.

### Files to delete

- `src/features/analytics/components/heatmap/HeatmapAnalysis.tsx`
- `src/features/analytics/components/heatmap/HeatmapMetrics.tsx`
- `src/features/analytics/components/heatmap/HeatmapStats.tsx` (rankings already covered by `GeographicChart`)
- `src/features/analytics/hooks/useHeatmapData.ts`
- Any heatmap-specific exports from `src/features/analytics/index.ts`
- The `getHeatmapData()` method on the analytics service class
- Heatmap-specific i18n keys

### Files to move

- `src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx` → `src/features/analytics/components/geographic/RealTimeHeatmapChart.tsx` (it remains used as the "Mapa de calor" sub-tab body). After the move the `heatmap/` folder is empty and is deleted.

### Files to refactor

- `src/features/analytics/components/geographic/GeographicAnalysis.tsx` — full refactor per the component tree above. The existing `GeographicMetrics` component is updated in place: its props change from the current `GeographicStats` shape to the unified set sourced from `metadata` (see "Top-level metric cards" below). Renamed only if the existing name no longer fits; default is to keep the file/name and update the prop type. No second metrics component is introduced — single source of truth.
- `src/features/analytics/hooks/useGeographicData.ts` — extend the returned shape to expose `metadata` alongside `data`. The derived `GeographicStats` helper is computed from `metadata` rather than re-counting client-side.
- `src/features/analytics/types/*` — `GeographicData` gains a `metadata` field; `HeatmapPoint` (the row shape inside `heatmap_data[]`) is consolidated into the geographic types module and the standalone heatmap types file is deleted.
- `src/features/links/components/analytics/LinkAnalyticsTabs.tsx`:
  - Remove the `Heatmap` entry from `tabLabels` (lines 71–79) and the corresponding `index === 4` render block.
  - Drop the `HeatmapAnalysis` import.
  - Remaining indices become Overview=0, Geographic=1, Temporal=2, Audience=3, Insights=4, ClicksTable=5.
- i18n catalogs — remove heatmap tab keys; add `analytics.geographic.subtabs.{overview,heatmap,rankings,insights}` (plus matching translations).

### Top-level metric cards (unified set)

Five cards, derived from the new `metadata` block:

1. **Countries reached** ← `metadata.unique_countries`
2. **States reached** ← `metadata.unique_states` (added to the metadata block; the backend computes it via `count(distinct state_name)` in the same scan that produces the other unique counts)
3. **Cities reached** ← `metadata.unique_cities`
4. **Total clicks** ← `metadata.total_clicks`
5. **Coverage %** ← computed client-side from `metadata` exactly as `GeographicMetrics` computes it today (preserved formula)

### Loading / errors / empty state

Single hook (`useGeographicData`) drives loading, error, and empty states for the entire tab — same pattern as Temporal. Sub-tabs do not fetch independently. While loading, the existing skeleton/spinner from `GeographicAnalysis` is preserved. On error, the existing error UI is preserved. If `heatmap_data` is empty, the "Mapa de calor" sub-tab is disabled (not hidden) so the user understands it exists but has no data yet.

### Lazy loading the map

`RealTimeHeatmapChart` likely brings in a map library (Leaflet/Mapbox) that is heavy. Today it loads only when the user opens the Heatmap tab. After the merge, it must NOT load until the user opens the "Mapa de calor" sub-tab. Implementation: render the chart only when `activeSubTab === 1`, and dynamically `import()` the chart module via `next/dynamic` if it isn't already.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| External consumer of `/heatmap` breaks | User has explicitly authorized the breaking change. No deprecation period. |
| Tab-index reordering breaks deep links | No deep-link consumer of tab index 4 has been found. Reconfirm with a quick grep before implementation. |
| Map library adds bundle weight to Geographic tab | Lazy-render the chart only when its sub-tab is active; use `next/dynamic` if it isn't already. |
| Subtle drift between `metadata.total_clicks` and prior heatmap totals | Backend feature test asserts the metadata block exactly. |

## Validation

**Backend (automated):**
- `docker-compose exec app ./vendor/bin/phpunit` — full suite green.
- New/updated feature test for `GET /api/analytics/link/{id}/geographic` asserts the response contains `data.heatmap_data`, `data.top_countries`, `data.top_states`, `data.top_cities`, `data.continents`, and a top-level `metadata` block containing `total_clicks`, `unique_countries`, `unique_states`, `unique_cities`, `max_clicks`, `total_locations`, `last_updated`, and `link_info`.
- A test asserting `GET /api/analytics/link/{id}/heatmap` returns 404 (route removed).

**Frontend (manual — no test runner):**
- `npm run quality` (type-check + lint + format:check) green.
- Open `http://localhost:3000/links/analytics/68`; confirm:
  - Geographic tab is present; Heatmap tab is gone.
  - Five metric cards render at the top of Geographic with correct values.
  - All four sub-tabs render their respective visualizations; map sub-tab loads its library only when opened.
  - Disabled states appear when underlying data is missing.
  - Other tabs (Overview, Temporal, Audience, Insights, ClicksTable) still load — verifying that the tab-index shift didn't regress any sibling.

## Acceptance criteria

1. `/api/analytics/link/{id}/heatmap` no longer exists; `/api/analytics/link/{id}/geographic` returns the unified payload with the new `metadata` block.
2. The `Heatmap` tab no longer appears in `LinkAnalyticsTabs`.
3. The Geographic tab shows the five top-level metric cards plus four sub-tabs in the order: Visão geral, Mapa de calor, Rankings, Insights.
4. No file under `src/features/analytics/components/heatmap/` exists after the change. `RealTimeHeatmapChart` lives under `src/features/analytics/components/geographic/`.
5. No `useHeatmapData` hook, no analytics-service `getHeatmapData()` method, no `getHeatmapData()` on `GeographicAnalyticsInterface`.
6. Backend tests pass; manual frontend validation per the checklist above passes.
