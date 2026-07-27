<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the removal of the dead `idx_clicks_user_agent` index.
 *
 * The index (link_id, user_agent) was created by
 * 2025_09_14_140100_add_performance_indexes_simple but no query ever
 * aggregates or filters by the raw user_agent column — analytics group by the
 * parsed browser/device/os columns instead. The index only added write
 * amplification on the click-insert hot path (user_agent is up to 1024 chars),
 * so a later migration drops it. This test proves the drop migration ran and
 * keeps anyone from resurrecting the index without a query that needs it.
 */
class DropUserAgentIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * After running all migrations the dead index must not exist.
     */
    public function test_idx_clicks_user_agent_does_not_exist(): void
    {
        $indexes = collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'clicks'"
        ))->pluck('name');

        $this->assertNotContains('idx_clicks_user_agent', $indexes);
    }

    /**
     * Sibling indexes from the same migration must remain untouched.
     */
    public function test_sibling_clicks_indexes_still_exist(): void
    {
        $indexes = collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'clicks'"
        ))->pluck('name');

        $this->assertContains('idx_clicks_link_date', $indexes);
        $this->assertContains('idx_clicks_geo', $indexes);
        $this->assertContains('idx_clicks_referer', $indexes);
    }
}
