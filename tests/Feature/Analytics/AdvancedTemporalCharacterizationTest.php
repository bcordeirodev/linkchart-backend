<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\Analytics\TemporalAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test freezing the COMPLETE payload of
 * {@see TemporalAnalyticsService::getAdvancedTemporalAnalytics()}.
 *
 * The frontend (useTemporalData) consumes this payload directly, so its shape
 * and values must stay byte-identical while the in-memory aggregations are
 * moved to GROUP BY SQL. The dataset below deliberately exercises:
 *
 *  - clicks with NULL hour_of_day / day_of_week (fallback to created_at extraction);
 *  - clicks with NULL timezone (mapped to 'Unknown');
 *  - clicks with NULL / unknown-case / non-standard device values;
 *  - multiple ISO weeks including a week whose Monday falls in the previous
 *    calendar year (the 'Y-W' key quirk of startOfWeek()->format('Y-W'));
 *  - multiple months, seasons and one holiday click;
 *  - a click older than the 90-day daily_timeline cap (excluded from the
 *    timeline but present in every other aggregation);
 *  - a second link whose clicks must never leak into the aggregations.
 *
 * The expected array was captured from the pre-refactor (PHP in-memory)
 * implementation and must NOT be regenerated from the refactored code —
 * that would defeat the purpose of the characterization.
 */
class AdvancedTemporalCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Link $link;

    private TemporalAnalyticsService $service;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $user->id]);
        $this->service = new TemporalAnalyticsService;
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Seeds the full characterization dataset for the main link plus one
     * decoy click on another link (must be excluded by link scoping).
     */
    private function seedClicks(): void
    {
        $clicks = [
            // [created_at, hour_of_day, day_of_week, timezone, device, ip, extra]
            // c1 — NULL hour/dow fallbacks; Monday of its ISO week is 2025-12-29 → week key "2025-01".
            ['2026-01-01 22:15:00', null, null, 'America/Sao_Paulo', 'desktop', '10.0.0.1', []],
            // c2/c3 — same day + hour, distinct timezones, shared IP with c3.
            ['2026-05-10 08:00:00', 8, 7, 'America/Sao_Paulo', 'mobile', '10.0.0.2', []],
            ['2026-05-10 08:30:00', 8, 7, 'Europe/Lisbon', 'tablet', '10.0.0.2', []],
            // c4 — NULL hour/dow/timezone/device (Saturday 14h).
            ['2026-06-20 14:45:00', null, null, null, null, '10.0.0.4', []],
            // c5/c6 — dawn clicks; c6 exercises strtolower on device.
            ['2026-07-01 03:10:00', 3, 3, 'America/Sao_Paulo', 'desktop', '10.0.0.5', []],
            ['2026-07-01 03:20:00', 3, 3, 'America/Sao_Paulo', 'Desktop', '10.0.0.6', []],
            // c7/c8 — evening clicks; c8 has a device outside desktop/mobile/tablet.
            ['2026-07-14 19:00:00', 19, 2, 'Asia/Tokyo', 'mobile', '10.0.0.7', []],
            ['2026-07-14 19:30:00', 19, 2, 'America/Sao_Paulo', 'smart-tv', '10.0.0.8', []],
            // c9 — holiday click, winter season.
            ['2026-06-20 10:00:00', 10, 6, 'Europe/Lisbon', 'mobile', '10.0.0.9', ['is_holiday' => true, 'holiday_name' => 'Corpus Christi', 'season' => 'winter']],
            // c10 — winter season.
            ['2026-07-10 09:00:00', 9, 5, 'Europe/Lisbon', 'desktop', '10.0.0.10', ['season' => 'winter']],
            // c11 — spring season, NULL timezone.
            ['2026-05-10 21:00:00', 21, 7, null, 'tablet', '10.0.0.11', ['season' => 'spring']],
        ];

        foreach ($clicks as [$createdAt, $hour, $dow, $tz, $device, $ip, $extra]) {
            Click::factory()->create(array_merge([
                'link_id' => $this->link->id,
                'created_at' => $createdAt,
                'hour_of_day' => $hour,
                'day_of_week' => $dow,
                'timezone' => $tz,
                'device' => $device,
                'ip' => $ip,
            ], $extra));
        }

        // Decoy click on a different link — must not appear in any aggregate.
        $otherLink = Link::factory()->create(['user_id' => $this->link->user_id]);
        Click::factory()->create([
            'link_id' => $otherLink->id,
            'created_at' => '2026-07-01 05:00:00',
            'hour_of_day' => 5,
            'day_of_week' => 3,
            'timezone' => 'America/Sao_Paulo',
            'device' => 'mobile',
            'ip' => '10.0.9.9',
        ]);
    }

    /**
     * Freezes the complete advanced temporal payload for a link with clicks.
     */
    public function test_advanced_temporal_payload_is_frozen(): void
    {
        $this->seedClicks();

        $payload = $this->service->getAdvancedTemporalAnalytics($this->link->id);

        $this->assertSame($this->golden('advanced_temporal_payload'), $payload);
    }

    /**
     * Freezes the payload for a link with zero clicks (empty/null branches).
     */
    public function test_advanced_temporal_payload_is_frozen_for_link_without_clicks(): void
    {
        $payload = $this->service->getAdvancedTemporalAnalytics($this->link->id);

        $this->assertSame($this->golden('advanced_temporal_payload_empty'), $payload);
    }

    /**
     * Loads a golden payload snapshot captured from the pre-refactor
     * (in-memory PHP aggregation) implementation.
     *
     * Follows the repo golden-snapshot convention (see
     * RedirectPreviewCharacterizationTest): the snapshot is a var_export'ed
     * PHP array under tests/Feature/__snapshots__/. It must NOT be regenerated
     * from refactored code — doing so would erase the frozen reference.
     *
     * @param  string  $name  Snapshot basename without extension.
     * @return array<string, mixed> The frozen payload.
     */
    private function golden(string $name): array
    {
        $path = __DIR__.'/../__snapshots__/'.$name.'.php';

        $this->assertFileExists($path, 'Golden snapshot missing — it freezes the pre-refactor payload and must not be deleted.');

        return require $path;
    }
}
