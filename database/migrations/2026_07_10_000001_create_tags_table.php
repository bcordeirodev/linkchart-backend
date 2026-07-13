<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `tags` table: a per-user label that can later be attached
     * to any number of that user's links via the `link_tag` pivot (see the
     * following migration). `color` is a required 7-char hex string (e.g.
     * "#3B82F6") assigned by the frontend from a fixed palette; the backend
     * only validates the hex format, not membership in that palette. The
     * `unique(['user_id', 'name'])` constraint scopes name uniqueness per
     * user, so two different users may both have a tag named "Marketing".
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 7);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
