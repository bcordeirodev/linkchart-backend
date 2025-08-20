<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adicionar índices para otimizar performance em produção
     */
    public function up()
    {
        // Índices para tabela clicks (analytics)
        Schema::table('clicks', function (Blueprint $table) {
            // Índice composto para queries de analytics por link
            $table->index(['link_id', 'created_at'], 'idx_clicks_link_date');

            // Índice para queries geográficas
            $table->index(['link_id', 'country', 'city'], 'idx_clicks_geo');

            // Índice para queries temporais
            $table->index(['link_id', 'created_at'], 'idx_clicks_temporal');

            // Índice para user agent analytics
            $table->index(['link_id', 'user_agent'], 'idx_clicks_user_agent');

            // Índice para referer analytics
            $table->index(['link_id', 'referer'], 'idx_clicks_referer');
        });

        // Índices para tabela links
        Schema::table('links', function (Blueprint $table) {
            // Índice composto para queries do usuário
            $table->index(['user_id', 'is_active', 'created_at'], 'idx_links_user_active');

            // Índice para slug lookup (já existe, mas garantindo)
            if (!$this->indexExists('links', 'links_slug_unique')) {
                $table->unique('slug', 'links_slug_unique');
            }

            // Índice para queries de expiração
            $table->index(['expires_at', 'is_active'], 'idx_links_expiration');
        });

        // Índices para tabela users
        Schema::table('users', function (Blueprint $table) {
            // Índice para email lookup (já existe, mas garantindo)
            if (!$this->indexExists('users', 'users_email_unique')) {
                $table->unique('email', 'users_email_unique');
            }

            // Índice para queries de criação
            $table->index('created_at', 'idx_users_created_at');
        });

        // Otimizações específicas do PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            // Criar índices parciais para melhor performance
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_active_links
                          ON clicks (link_id, created_at)
                          WHERE link_id IN (SELECT id FROM links WHERE is_active = true)');

            // Índice para contagem rápida de cliques por link
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_count_by_link
                          ON clicks (link_id)
                          WHERE created_at >= CURRENT_DATE - INTERVAL \'30 days\'');

            // Estatísticas automáticas para otimizador
            DB::statement('ANALYZE clicks');
            DB::statement('ANALYZE links');
            DB::statement('ANALYZE users');
        }
    }

    /**
     * Reverter as otimizações
     */
    public function down()
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex('idx_clicks_link_date');
            $table->dropIndex('idx_clicks_geo');
            $table->dropIndex('idx_clicks_temporal');
            $table->dropIndex('idx_clicks_user_agent');
            $table->dropIndex('idx_clicks_referer');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex('idx_links_user_active');
            $table->dropIndex('idx_links_expiration');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_created_at');
        });

        // Remover índices específicos do PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_active_links');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_count_by_link');
        }
    }

    /**
     * Verificar se um índice existe
     */
    private function indexExists(string $table, string $index): bool
    {
        $indexes = Schema::getConnection()->getDoctrineSchemaManager()
            ->listTableIndexes($table);

        return array_key_exists($index, $indexes);
    }
};
