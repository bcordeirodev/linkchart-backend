<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the clicks:anonymize-ips retention command (LGPD): old IPs get
 * masked, recent IPs stay intact, and already-anonymized rows are skipped.
 */
class AnonymizeOldClickIpsTest extends TestCase
{
    use RefreshDatabase;

    /** Creates a click bound to $link with a fixed ip and created_at age in days. */
    private function makeClick(Link $link, string $ip, int $ageDays): Click
    {
        $click = Click::factory()->create([
            'link_id' => $link->id,
            'ip' => $ip,
        ]);
        $click->timestamps = false;
        $click->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $click;
    }

    /** Clicks past the window are masked + flagged; recent clicks stay intact. */
    public function test_masks_only_clicks_older_than_retention_window(): void
    {
        $link = Link::factory()->create();
        $old = $this->makeClick($link, '187.10.55.42', 120);
        $recent = $this->makeClick($link, '187.10.55.99', 5);

        $this->artisan('clicks:anonymize-ips')->assertSuccessful();

        $this->assertSame('187.10.55.0', $old->fresh()->ip);
        $this->assertTrue((bool) $old->fresh()->ip_anonymized);
        $this->assertSame('187.10.55.99', $recent->fresh()->ip);
        $this->assertFalse((bool) $recent->fresh()->ip_anonymized);
    }

    /** IPv6 addresses are truncated to their /48 prefix. */
    public function test_truncates_ipv6_addresses(): void
    {
        $link = Link::factory()->create();
        $old = $this->makeClick($link, '2804:14d:5c81:9aab:1234:5678:9abc:def0', 120);

        $this->artisan('clicks:anonymize-ips')->assertSuccessful();

        $this->assertSame('2804:14d:5c81::', $old->fresh()->ip);
    }

    /** The --days option overrides the configured window. */
    public function test_respects_days_option(): void
    {
        $link = Link::factory()->create();
        $click = $this->makeClick($link, '8.8.8.8', 10);

        $this->artisan('clicks:anonymize-ips', ['--days' => 5])->assertSuccessful();

        $this->assertSame('8.8.8.0', $click->fresh()->ip);
    }
}
