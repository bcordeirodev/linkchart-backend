<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a durable, idempotency key to the clicks table.
 *
 * `dedup_key` is the server-generated token created in RedirectController at
 * dispatch time and carried in the ProcessLinkClickJob payload. A UNIQUE index
 * on it makes click persistence idempotent under job retry at the database
 * level (the source-of-truth guarantee), so a worker timeout between the click
 * insert and the old cache marker can no longer produce duplicate rows or a
 * double links.clicks increment on retry.
 *
 * The column is intentionally NULLABLE: events without a dedup_key (legacy
 * payloads queued before the key existed, or edge cases) must still insert
 * freely. Both PostgreSQL and SQLite treat NULL values as distinct in a UNIQUE
 * index, so multiple NULL dedup_key rows never collide — at-least-once
 * semantics are preserved for those events.
 */
return new class extends Migration
{
    /**
     * Add the nullable dedup_key column with a UNIQUE index.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->string('dedup_key', 80)->nullable()->after('id');
            $table->unique('dedup_key', 'clicks_dedup_key_unique');
        });
    }

    /**
     * Drop the UNIQUE index and the dedup_key column.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropUnique('clicks_dedup_key_unique');
            $table->dropColumn('dedup_key');
        });
    }
};
