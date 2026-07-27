<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `bio_page_items` table: the ordered list of buttons shown on
     * a user's bio page, each pointing at one of that user's existing
     * shortened links (`link_id`). `position` (0-based) defines display order;
     * `cascadeOnDelete` on both foreign keys means deleting the parent bio
     * page or the underlying link cleans up orphaned items automatically —
     * an item can never outlive the link it points to.
     */
    public function up(): void
    {
        Schema::create('bio_page_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bio_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['bio_page_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bio_page_items');
    }
};
