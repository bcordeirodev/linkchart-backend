<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail de marco de 100 cliques: guarda at-most-once por link.
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
            // Claim at-most-once do SendMilestoneEmailJob — nulo enquanto o
            // marco não foi comemorado; carimbado no UPDATE condicional que
            // reivindica o envio (ver docblock daquele job).
            $table->timestamp('milestone_100_notified_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('milestone_100_notified_at');
        });
    }
};
