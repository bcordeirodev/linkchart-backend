<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `links.password_hash`, the bcrypt hash for password-protected links.
 *
 * When non-null, GET /r/{slug} renders a password form instead of the 302
 * redirect; the visitor unlocks via POST /r/{slug}/unlock and the password is
 * verified with Hash::check against this column. The column is write-only at
 * the API layer (the `password` field) and hidden from all serialization
 * (Link::$hidden) — clients only ever see the derived `has_password` boolean.
 *
 * Expand-only: nullable and additive, so the previous release keeps serving
 * traffic during the blue/green cutover without knowing about it.
 */
return new class extends Migration
{
    /**
     * Adds the nullable `password_hash` column to `links`.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('short_domain');
        });
    }

    /**
     * Reverts only the alteration made by up() (never drops the table).
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('password_hash');
        });
    }
};
