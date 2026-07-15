<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite múltiplos subdomínios por usuário: remove o UNIQUE(user_id)
 * e o substitui por índice simples. A unicidade global do label entre
 * ativos (partial unique em subdomain WHERE status='active') permanece.
 * Online-safe: remover constraint não bloqueia leituras nem quebra o código antigo.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_subdomains', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_subdomains', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });
    }
};
