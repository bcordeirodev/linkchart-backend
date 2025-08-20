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
            // Adiciona campos geográficos mais detalhados
            $table->string('iso_code', 2)->nullable()->after('country'); // Código ISO do país
            $table->string('state', 10)->nullable()->after('city'); // Código do estado/região
            $table->string('state_name')->nullable()->after('state'); // Nome completo do estado/região
            $table->string('postal_code', 20)->nullable()->after('state_name'); // Código postal
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code'); // Latitude
            $table->decimal('longitude', 11, 7)->nullable()->after('latitude'); // Longitude
            $table->string('timezone', 50)->nullable()->after('longitude'); // Fuso horário
            $table->string('continent', 20)->nullable()->after('timezone'); // Continente
            $table->string('currency', 3)->nullable()->after('continent'); // Moeda

            // Índices para melhorar performance de consultas
            $table->index(['country', 'state', 'city'], 'idx_clicks_location');
            $table->index('iso_code', 'idx_clicks_iso_code');
            $table->index('continent', 'idx_clicks_continent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            // Remove índices primeiro
            $table->dropIndex('idx_clicks_location');
            $table->dropIndex('idx_clicks_iso_code');
            $table->dropIndex('idx_clicks_continent');

            // Remove colunas
            $table->dropColumn([
                'iso_code',
                'state',
                'state_name',
                'postal_code',
                'latitude',
                'longitude',
                'timezone',
                'continent',
                'currency'
            ]);
        });
    }
};
