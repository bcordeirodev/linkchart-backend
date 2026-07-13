<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `link_tag` pivot table for the Link <-> Tag many-to-many
     * relationship (Eloquent's default naming for belongsToMany between
     * `Link` and `Tag`: singular model names joined alphabetically). Lean by
     * design — no surrogate id and no timestamps, since the pivot only ever
     * needs to answer "is link X tagged with tag Y" and is always written via
     * `$link->tags()->sync()`. Both foreign keys cascade on delete so
     * deleting a link or a tag automatically cleans up its attachments.
     */
    public function up(): void
    {
        Schema::create('link_tag', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['link_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_tag');
    }
};
