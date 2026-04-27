<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->string('health_status', 20)->default('unknown')->after('click_limit');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['health_status', 'health_checked_at']);
        });
    }
};
