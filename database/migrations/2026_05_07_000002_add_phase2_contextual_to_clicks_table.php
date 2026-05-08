<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Phase 2 contextual intelligence columns to the clicks table.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->boolean('is_holiday')->nullable()->after('language_region');
            $table->string('holiday_name', 100)->nullable()->after('is_holiday');
            $table->string('season', 10)->nullable()->after('holiday_name');
            $table->string('viral_rank', 15)->nullable()->after('season');
            $table->integer('seconds_since_last_click')->nullable()->after('viral_rank');
            $table->string('connection_type', 20)->nullable()->after('seconds_since_last_click');
            $table->string('rendering_engine', 20)->nullable()->after('connection_type');

            $table->index('viral_rank', 'idx_clicks_viral_rank');
            $table->index('connection_type', 'idx_clicks_connection_type');
        });
    }

    /**
     * Drop Phase 2 contextual intelligence columns from the clicks table.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_viral_rank');
            $table->dropIndex('idx_clicks_connection_type');
            $table->dropColumn([
                'is_holiday', 'holiday_name', 'season',
                'viral_rank', 'seconds_since_last_click',
                'connection_type', 'rendering_engine',
            ]);
        });
    }
};
