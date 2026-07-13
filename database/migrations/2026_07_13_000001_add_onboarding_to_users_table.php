<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `onboarding` JSON map to `users`.
 *
 * Holds "user has already seen X" markers keyed by a dotted flag name
 * (e.g. `links.tour`), each mapped to the ISO-8601 timestamp of when it was
 * dismissed. Previously these lived only in the browser's localStorage, so the
 * guided tour replayed on every new browser, device or private window.
 *
 * A JSON map (rather than one boolean column per flag) keeps future onboarding
 * surfaces from requiring a migration each.
 *
 * Expand-only: adds a nullable column, so the pre-deploy code — which never
 * reads or writes it — keeps working during the blue/green cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('onboarding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding');
        });
    }
};
