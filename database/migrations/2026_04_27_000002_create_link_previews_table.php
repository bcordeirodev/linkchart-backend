<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_previews', function (Blueprint $table) {
            $table->unsignedBigInteger('link_id')->primary();
            $table->foreign('link_id')->references('id')->on('links')->onDelete('cascade');
            $table->string('favicon_url', 500)->nullable();
            $table->string('og_title', 500)->nullable();
            $table->string('og_image_url', 500)->nullable();
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_previews');
    }
};
