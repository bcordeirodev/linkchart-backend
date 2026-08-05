<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail de winback (link sem cliques em D+14): guarda at-most-once por link.
 *
 * Expand-only (blue/green): apenas adiciona coluna — nada é renomeado nem
 * removido enquanto o código antigo ainda serve tráfego.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Claim at-most-once do SendWinbackEmailJob — nulo enquanto o link
            // não entrou em nenhum e-mail de winback; carimbado no UPDATE
            // condicional que reivindica o envio (ver docblock daquele job).
            $table->timestamp('winback_email_sent_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('winback_email_sent_at');
        });
    }
};
