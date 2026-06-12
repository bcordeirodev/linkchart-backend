<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ip_anonymized flag used by the clicks:anonymize-ips retention
 * command (LGPD). Indexed together with created_at so the daily sweep only
 * scans rows that still need anonymizing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->boolean('ip_anonymized')->default(false);
            $table->index(['ip_anonymized', 'created_at'], 'clicks_ip_anonymized_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('clicks_ip_anonymized_created_at_index');
            $table->dropColumn('ip_anonymized');
        });
    }
};
