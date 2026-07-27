<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.password_changed_at — the anchor for invalidating JWTs issued
 * before a password reset/change.
 *
 * Every JWT carries the epoch of this column in the custom `pwd_ts` claim
 * (see User::getJWTCustomClaims); ApiAuthenticate rejects tokens whose claim
 * no longer matches the current value. NULL (the default, and the state of
 * every pre-existing row) means "password never changed" and maps to claim 0,
 * so all currently valid tokens keep working after this deploy.
 *
 * Expand-safe: purely additive nullable column; old code ignores it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only undoes this migration's own change (drops the added column).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
