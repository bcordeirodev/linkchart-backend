<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an optional link from a bio page to one of its owner's
     * `user_subdomains` rows (Option A of the bio<->subdomain integration —
     * data relationship only; the subdomain root does NOT serve the bio page
     * yet, that is a separate, explicitly-scoped follow-up).
     *
     * Nullable and `nullOnDelete()`: releasing or deleting the underlying
     * subdomain must never break the bio page — {@see \App\Services\Bio\BioPageService}
     * simply falls back to a path-based `url` once `subdomain_id` reverts to null.
     */
    public function up(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->foreignId('subdomain_id')
                ->nullable()
                ->after('user_id')
                ->constrained('user_subdomains')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops only the column (and its FK constraint) this migration added —
     * never the `bio_pages` table itself, which a different migration created.
     */
    public function down(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subdomain_id');
        });
    }
};
