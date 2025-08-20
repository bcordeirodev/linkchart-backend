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
        Schema::table('links', function (Blueprint $table) {
            $table->string('title')->nullable()->after('original_url');
            $table->text('description')->nullable()->after('title');

            // UTM Parameters
            $table->string('utm_source', 100)->nullable()->after('is_active');
            $table->string('utm_medium', 100)->nullable()->after('utm_source');
            $table->string('utm_campaign', 100)->nullable()->after('utm_medium');
            $table->string('utm_term', 100)->nullable()->after('utm_campaign');
            $table->string('utm_content', 100)->nullable()->after('utm_term');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
                Schema::table('links', function (Blueprint $table) {
            $table->dropColumn([
                'title', 'description',
                'utm_source', 'utm_medium', 'utm_campaign',
                'utm_term', 'utm_content'
            ]);
        });
    }
};
