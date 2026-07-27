<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice composto (ip, created_at) para o analyzeVisitorBehavior.
 *
 * O ProcessLinkClickJob consulta `WHERE ip = ? AND created_at >= ?` a cada
 * clique processado (janelas de 24h/1h de is_return_visitor/session_clicks).
 * Sem este índice a consulta é um full-scan de `clicks` e a fila degrada
 * linearmente com o crescimento da tabela.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->index(['ip', 'created_at'], 'idx_clicks_ip_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_ip_created_at');
        });
    }
};
