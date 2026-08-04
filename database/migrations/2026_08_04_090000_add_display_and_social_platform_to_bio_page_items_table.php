<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive-only (expand/contract — see MigrationSafetyTest): adds two
     * nullable columns to `bio_page_items` so an item can render as a social
     * icon instead of a full-width button, WITHOUT giving up click tracking
     * — `link_id` stays mandatory for every item, icon or not (see
     * BioPageService::addItem()).
     *
     * `display` defaults to `'item'` so every pre-existing row (created
     * before this migration) reads as a regular button with zero backfill —
     * Postgres applies a column DEFAULT to existing rows when the column is
     * added, not just to future inserts. Whitelisted values ('item'|'icon')
     * are enforced at the application layer (CreateBioPageItemRequest /
     * UpdateBioPageItemRequest), not a DB CHECK constraint, matching how
     * `bio_pages.theme` ('dark'|'light') is already handled in this module.
     *
     * `social_platform` is nullable with no default — only meaningful when
     * `display = 'icon'`; the whitelist of the initial 8 platforms
     * (instagram, tiktok, youtube, x, whatsapp, linkedin, github, website)
     * lives in `config('bio.social_platforms')`, mirrored by
     * {@see \App\Models\BioPageItem::DISPLAY_ICON}'s sibling validation
     * rules. Length 30 mirrors `clicks.social_platform` (an unrelated column
     * of the same name/purpose-shape on a different table — see
     * `2026_05_19_000003_add_social_platform_to_clicks_table.php`).
     */
    public function up(): void
    {
        Schema::table('bio_page_items', function (Blueprint $table) {
            $table->string('display', 20)->default('item')->nullable()->after('is_active');
            $table->string('social_platform', 30)->nullable()->after('display');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bio_page_items', function (Blueprint $table) {
            $table->dropColumn(['display', 'social_platform']);
        });
    }
};
