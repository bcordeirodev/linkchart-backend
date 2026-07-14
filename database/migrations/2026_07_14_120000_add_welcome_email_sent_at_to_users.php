<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `users.welcome_email_sent_at`, the at-most-once guard for the welcome email.
 *
 * SendWelcomeEmailJob claims the send by flipping this column from NULL to a
 * timestamp in a single conditional UPDATE. A job retry (tries = 3) that runs
 * after a successful SendGrid call therefore finds the column already set and
 * returns without sending a duplicate.
 *
 * Expand-only: the column is nullable and additive, so the previous release keeps
 * serving traffic during the blue/green cutover without knowing about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_email_sent_at');
        });
    }
};
