<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add Phase 3 quality scoring columns to the clicks table.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->nullable()->after('rendering_engine');
            $table->string('quality_tier', 15)->nullable()->after('quality_score');
            $table->unsignedTinyInteger('fingerprint_score')->default(0)->after('quality_tier');

            $table->index('quality_tier', 'idx_clicks_quality_tier');
        });
    }

    /**
     * Drop Phase 3 quality scoring columns from the clicks table.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_quality_tier');
            $table->dropColumn(['quality_score', 'quality_tier', 'fingerprint_score']);
        });
    }
};
