<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens clicks.referer from varchar(255) to varchar(2048).
 *
 * Real HTTP Referer values routinely exceed 255 chars — most notably Facebook
 * in-app-browser traffic, which arrives wrapped in a `lm.facebook.com/l.php?u=…&h=…`
 * URL of ~350–450 chars. The old width caused ProcessLinkClickJob to fail with
 * SQLSTATE[22001] ("value too long"), silently dropping those clicks from analytics.
 *
 * 2048 comfortably holds real referer URLs while staying well under the ~2704-byte
 * PostgreSQL btree row-size limit of the existing (link_id, referer) index, so the
 * index keeps working without a crash on oversized values. Anything beyond 2048 is
 * clamped in the app layer (Click::COLUMN_LIMITS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->string('referer', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->string('referer', 255)->nullable()->change();
        });
    }
};
