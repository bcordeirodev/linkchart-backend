<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `bio_pages` table: one public "link-in-bio" page per user
     * (MVP — enforced by the `unique('user_id')` constraint). `handle` is the
     * public slug (e.g. linkcharts.com.br/bio/{handle}), globally unique and
     * always stored lowercase (normalized in BioPageService/UpsertBioPageRequest,
     * not at the DB layer). `unique('handle')` doubles as the lookup index for
     * the public GET /api/public/bio/{handle} endpoint.
     */
    public function up(): void
    {
        Schema::create('bio_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');
            $table->string('handle', 30);
            $table->string('title');
            $table->string('bio', 280)->nullable();
            $table->string('theme', 20)->default('dark');
            // Reserved for a future avatar upload feature — not read/written by
            // any code yet (see module spec).
            $table->string('avatar_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('handle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bio_pages');
    }
};
