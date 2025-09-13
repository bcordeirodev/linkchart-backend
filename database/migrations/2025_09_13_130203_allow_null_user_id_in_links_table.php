<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Remove a constraint de foreign key
            $table->dropForeign(['user_id']);

            // Modifica a coluna para permitir null
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // Recria a foreign key com nullable
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Remove a constraint de foreign key
            $table->dropForeign(['user_id']);

            // Volta a coluna para não permitir null
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->change();
        });
    }
};
