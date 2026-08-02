<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona a miniatura do avatar à bio page: o arquivo ORIGINAL segue como
 * imagem do Open Graph (preview grande e bonito no WhatsApp), e a miniatura
 * quadrada — gerada no navegador no momento do upload — vira a imagem do
 * círculo da página pública, que renderiza a ~116px e não precisa (nem fica
 * bem) com a foto cheia.
 *
 * Migration aditiva (expand) — segura para blue/green.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->string('avatar_thumb_path')->nullable()->after('avatar_url');
            $table->string('avatar_thumb_url')->nullable()->after('avatar_thumb_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bio_pages', function (Blueprint $table) {
            $table->dropColumn(['avatar_thumb_path', 'avatar_thumb_url']);
        });
    }
};
