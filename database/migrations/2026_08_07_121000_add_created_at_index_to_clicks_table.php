<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para as séries globais do módulo admin (cliques/dia sem link_id).
 *
 * Nenhum índice existente de `clicks` tem `created_at` como coluna líder —
 * uma janela global (`WHERE created_at >= ?` sem link específico) faria seq
 * scan, e a tabela dobra de tamanho mensalmente com os seeds de demo.
 * `link_id` como segunda coluna serve o JOIN com `links` direto do índice.
 *
 * CONCURRENTLY: `clicks` está no caminho de escrita do redirect — um build
 * não-concorrente tomaria ACCESS EXCLUSIVE e estolaria a fila. Por isso
 * $withinTransaction = false (CONCURRENTLY não roda em transação).
 */
return new class extends Migration
{
    /** CONCURRENTLY é incompatível com o wrapper transacional do migrate. */
    public $withinTransaction = false;

    /** {@inheritDoc} */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_created_at ON clicks (created_at, link_id)');

            return;
        }

        // SQLite (testes) não suporta CONCURRENTLY — índice comum.
        Schema::table('clicks', function (Blueprint $table) {
            $table->index(['created_at', 'link_id'], 'idx_clicks_created_at');
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_created_at');

            return;
        }

        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_created_at');
        });
    }
};
