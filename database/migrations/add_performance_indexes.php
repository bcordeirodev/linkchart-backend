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

            // Nota: slug unique index já deve existir, pulando

            // Índice para queries de expiração
            $table->index(['expires_at', 'is_active'], 'idx_links_expiration');
        });

        // Índices para tabela users
        Schema::table('users', function (Blueprint $table) {
            // Nota: email unique index já deve existir, pulando

            // Índice para queries de criação
            $table->index('created_at', 'idx_users_created_at');
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
