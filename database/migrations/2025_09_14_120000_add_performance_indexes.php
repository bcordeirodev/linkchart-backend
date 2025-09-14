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
            // Verificar se índices já existem antes de criar
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes('clicks');

            // Índice composto para queries de analytics por link (temporal)
            if (!isset($indexes['idx_clicks_link_date'])) {
                $table->index(['link_id', 'created_at'], 'idx_clicks_link_date');
            }

            // Índice para queries geográficas
            if (!isset($indexes['idx_clicks_geo'])) {
                $table->index(['link_id', 'country', 'city'], 'idx_clicks_geo');
            }

            // Índice para user agent analytics
            if (!isset($indexes['idx_clicks_user_agent'])) {
                $table->index(['link_id', 'user_agent'], 'idx_clicks_user_agent');
            }

            // Índice para referer analytics
            if (!isset($indexes['idx_clicks_referer'])) {
                $table->index(['link_id', 'referer'], 'idx_clicks_referer');
            }
        });

        // Índices para tabela links
        Schema::table('links', function (Blueprint $table) {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes('links');

            // Índice composto para queries do usuário
            if (!isset($indexes['idx_links_user_active'])) {
                $table->index(['user_id', 'is_active', 'created_at'], 'idx_links_user_active');
            }

            // Índice para queries de expiração
            if (!isset($indexes['idx_links_expiration'])) {
                $table->index(['expires_at', 'is_active'], 'idx_links_expiration');
            }
        });

        // Índices para tabela users
        Schema::table('users', function (Blueprint $table) {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes('users');

            // Índice para queries de criação
            if (!isset($indexes['idx_users_created_at'])) {
                $table->index('created_at', 'idx_users_created_at');
            }
        });

        // Otimizações básicas - sem comandos problemáticos
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

        // Índices específicos do PostgreSQL removidos via Schema::dropIfExists()
    }


};
