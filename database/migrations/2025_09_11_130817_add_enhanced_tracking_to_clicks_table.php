<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            // === DADOS DE DISPOSITIVO DETALHADOS ===
            $table->string('browser', 50)->nullable()->after('device');
            $table->string('browser_version', 20)->nullable()->after('browser');
            $table->string('os', 50)->nullable()->after('browser_version');
            $table->string('os_version', 20)->nullable()->after('os');
            $table->boolean('is_mobile')->default(false)->after('os_version');
            $table->boolean('is_tablet')->default(false)->after('is_mobile');
            $table->boolean('is_desktop')->default(false)->after('is_tablet');
            $table->boolean('is_bot')->default(false)->after('is_desktop');

            // === DADOS TEMPORAIS ENRIQUECIDOS ===
            $table->tinyInteger('hour_of_day')->nullable()->after('is_bot');
            $table->tinyInteger('day_of_week')->nullable()->after('hour_of_day');
            $table->tinyInteger('day_of_month')->nullable()->after('day_of_week');
            $table->tinyInteger('month')->nullable()->after('day_of_month');
            $table->smallInteger('year')->nullable()->after('month');
            $table->string('local_time', 20)->nullable()->after('year');
            $table->boolean('is_weekend')->default(false)->after('local_time');
            $table->boolean('is_business_hours')->default(false)->after('is_weekend');

            // === DADOS DE COMPORTAMENTO ===
            $table->boolean('is_return_visitor')->default(false)->after('is_business_hours');
            $table->integer('session_clicks')->default(1)->after('is_return_visitor');
            $table->string('click_source', 50)->nullable()->after('session_clicks');

            // === DADOS DE PERFORMANCE ===
            $table->decimal('response_time', 8, 3)->nullable()->after('click_source');
            $table->string('accept_language', 100)->nullable()->after('response_time');

            // === ÍNDICES PARA PERFORMANCE ===
            $table->index(['browser', 'os'], 'idx_clicks_browser_os_enhanced');
            $table->index(['hour_of_day', 'day_of_week'], 'idx_clicks_temporal_enhanced');
            $table->index(['is_return_visitor', 'session_clicks'], 'idx_clicks_behavior_enhanced');
            $table->index('click_source', 'idx_clicks_source_enhanced');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            // Remove índices primeiro
            $table->dropIndex('idx_clicks_browser_os_enhanced');
            $table->dropIndex('idx_clicks_temporal_enhanced');
            $table->dropIndex('idx_clicks_behavior_enhanced');
            $table->dropIndex('idx_clicks_source_enhanced');

            // Remove colunas
            $table->dropColumn([
                'browser',
                'browser_version',
                'os',
                'os_version',
                'is_mobile',
                'is_tablet',
                'is_desktop',
                'is_bot',
                'hour_of_day',
                'day_of_week',
                'day_of_month',
                'month',
                'year',
                'local_time',
                'is_weekend',
                'is_business_hours',
                'is_return_visitor',
                'session_clicks',
                'click_source',
                'response_time',
                'accept_language'
            ]);
        });
    }
};
