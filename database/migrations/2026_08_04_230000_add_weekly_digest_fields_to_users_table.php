<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digest semanal de cliques: opt-out por usuário + guarda at-most-once.
 *
 * Expand-only (blue/green): apenas adiciona colunas — nada é renomeado nem
 * removido enquanto o código antigo ainda serve tráfego.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Opt-out do digest semanal (LGPD): true = inscrito. O link
            // assinado de unsubscribe (rota digest.unsubscribe) e o futuro
            // toggle do perfil desligam.
            $table->boolean('weekly_digest_enabled')->default(true);

            // Claim at-most-once do SendWeeklyDigestEmailJob — mesmo padrão
            // de welcome_email_sent_at (ver docblock daquele job).
            $table->timestamp('weekly_digest_sent_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_digest_enabled', 'weekly_digest_sent_at']);
        });
    }
};
