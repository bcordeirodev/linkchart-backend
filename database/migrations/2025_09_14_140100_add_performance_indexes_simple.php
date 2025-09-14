<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adicionar índices para otimizar performance em produção
     * Versão simplificada usando SQL direto
     */
    public function up()
    {
        // Índices para tabela clicks (analytics) - usando SQL direto
        $clicksIndexes = [
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_link_date ON clicks (link_id, created_at)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_geo ON clicks (link_id, country, city)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_user_agent ON clicks (link_id, user_agent)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_clicks_referer ON clicks (link_id, referer)'
        ];

        foreach ($clicksIndexes as $sql) {
            try {
                DB::statement($sql);
            } catch (\Exception $e) {
                // Ignorar se índice já existir
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        // Índices para tabela links - usando SQL direto
        $linksIndexes = [
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_links_user_active ON links (user_id, is_active, created_at)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_links_expiration ON links (expires_at, is_active)'
        ];

        foreach ($linksIndexes as $sql) {
            try {
                DB::statement($sql);
            } catch (\Exception $e) {
                // Ignorar se índice já existir
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        // Índices para tabela users - usando SQL direto
        $usersIndexes = [
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_created_at ON users (created_at)'
        ];

        foreach ($usersIndexes as $sql) {
            try {
                DB::statement($sql);
            } catch (\Exception $e) {
                // Ignorar se índice já existir
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Reverter as otimizações
     */
    public function down()
    {
        $indexes = [
            'DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_link_date',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_geo',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_user_agent',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_clicks_referer',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_links_user_active',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_links_expiration',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_users_created_at'
        ];

        foreach ($indexes as $sql) {
            try {
                DB::statement($sql);
            } catch (\Exception $e) {
                // Ignorar erros ao remover índices
            }
        }
    }
};
