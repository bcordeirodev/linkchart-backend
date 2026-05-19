<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_subdomains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');
            $table->string('subdomain', 63);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // Regular index for fast redirect hot-path lookup
            $table->index('subdomain');
        });

        // Partial unique index: only one active record per subdomain label.
        // Allows a released (inactive) subdomain to be reclaimed by another user.
        // Guard: SQLite (used in tests) does not support WHERE-clause indexes.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX user_subdomains_subdomain_active_unique"
                . " ON user_subdomains (subdomain) WHERE status = 'active'"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subdomains');
    }
};
