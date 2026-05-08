<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Phase 1 enrichment columns to the clicks table.
     *
     * Adds navigation context (Sec-Fetch-* headers), Client Hints (ch_platform,
     * ch_is_mobile), Save-Data indicator, HTTP protocol, and parsed language fields.
     * Includes indexes on navigation_context and primary_language for query performance.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->string('navigation_context', 30)->nullable()->after('click_source');
            $table->string('fetch_dest', 30)->nullable()->after('navigation_context');
            $table->string('ch_platform', 30)->nullable()->after('fetch_dest');
            $table->boolean('ch_is_mobile')->nullable()->after('ch_platform');
            $table->boolean('is_data_saver')->default(false)->after('ch_is_mobile');
            $table->string('http_protocol', 10)->nullable()->after('is_data_saver');
            $table->string('primary_language', 10)->nullable()->after('http_protocol');
            $table->string('language_region', 10)->nullable()->after('primary_language');

            $table->index('navigation_context', 'idx_clicks_nav_context');
            $table->index('primary_language', 'idx_clicks_primary_lang');
        });
    }

    /**
     * Reverse Phase 1 enrichment columns migration.
     *
     * Drops navigation context, Client Hints, Save-Data, HTTP protocol,
     * and language fields along with their associated indexes.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_nav_context');
            $table->dropIndex('idx_clicks_primary_lang');
            $table->dropColumn([
                'navigation_context', 'fetch_dest', 'ch_platform',
                'ch_is_mobile', 'is_data_saver', 'http_protocol',
                'primary_language', 'language_region',
            ]);
        });
    }
};
