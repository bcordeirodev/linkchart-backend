<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the dead index idx_clicks_user_agent (link_id, user_agent).
 *
 * Created by 2025_09_14_140100_add_performance_indexes_simple, but no query
 * ever aggregated or filtered by the raw user_agent column — the analytics
 * layer groups by the parsed browser/device/os columns instead (audited
 * 2026-07-27: the only user_agent reads in app/ are storage, logging and
 * parsing). The index only added write amplification on the click-insert hot
 * path, since user_agent is up to 1024 chars per row.
 *
 * Blue/green safe: dropping an index never breaks the old code still serving
 * traffic — queries simply replan without it — so this does NOT need the
 * offline-migration escape hatch (see MigrationSafetyTest, which only bans
 * dropColumn/renameColumn/drop/rename in up()). Deliberately worded without
 * the literal "at-sign + offline-migration" marker: that string anywhere in
 * this file would make MigrationSafetyTest skip it and signal the deploy to
 * run it in offline mode.
 */
return new class extends Migration
{
    /**
     * Drop the unused index.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_user_agent');
        });
    }

    /**
     * Recreate the index exactly as the 2025_09_14 migration defined it.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->index(['link_id', 'user_agent'], 'idx_clicks_user_agent');
        });
    }
};
