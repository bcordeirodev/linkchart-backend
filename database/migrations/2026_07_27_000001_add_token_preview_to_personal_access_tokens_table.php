<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `token_preview` to personal_access_tokens (Sanctum API keys).
 *
 * Sanctum only persists the sha256 hash of the token, so the plaintext is
 * unrecoverable after creation — yet GET /api/api-keys must show the last 4
 * characters ("…a1b2") so users can tell their keys apart. The preview is
 * captured once, at creation time, from the plainTextToken.
 *
 * Additive-only (expand/contract): nullable column, no default rewrite, safe
 * to run while the previous release is still serving traffic (blue/green).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Last 4 chars of the plaintext token, stored at creation time.
            // Nullable: tokens created outside the API-keys flow have none.
            $table->string('token_preview', 8)->nullable()->after('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('token_preview');
        });
    }
};
