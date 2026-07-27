<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `avatar_path` to `bio_pages`: the storage-relative path of the
     * uploaded avatar file (e.g. `bio-avatars/{random}.jpg`), used by
     * {@see \App\Services\Bio\BioPageService} to delete the previous file on
     * replace/remove. Deliberately separate from the existing `avatar_url`
     * column (which holds the derived, publicly-servable URL returned to
     * clients) — `avatar_url` is a view of `avatar_path` plus the configured
     * disk, not the other way around. Nullable: most pages have no avatar.
     *
     * Purely additive (expand-safe) — no existing column is touched.
     */
    public function up(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops only the column this migration added — never the `bio_pages`
     * table itself, which a different migration created.
     */
    public function down(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
