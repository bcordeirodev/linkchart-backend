<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds auth0_sub identifier for social-login users and makes password nullable.
 *
 * auth0_sub: the Auth0 "sub" claim (e.g. "google-oauth2|1234") — unique per
 * Auth0 identity. NULL for legacy email/password accounts until they first
 * log in via Auth0.
 *
 * password: made nullable because users who sign up exclusively through Auth0
 * social login never set a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth0_sub')->nullable()->unique()->after('email');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['auth0_sub']);
            $table->dropColumn('auth0_sub');
            $table->string('password')->nullable(false)->change();
        });
    }
};
