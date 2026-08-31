<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims dos dois e-mails de retenção re-segmentados (pacote 2+4 de 2026-08-16):
 * winback por USUÁRIO ausente e nudge de ativação do dia 1–2.
 *
 * Ambos saem de `users` porque agora o alvo é a pessoa, não o link. O winback
 * antigo carimbava `links.winback_email_sent_at` — essa coluna fica órfã (o
 * contract dela é de um release futuro; nenhum código lê ou escreve mais).
 *
 * Expand-only (blue/green): apenas adiciona colunas — nada é renomeado nem
 * removido enquanto o código antigo ainda serve tráfego
 * ({@see \Tests\Unit\MigrationSafetyTest}).
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Claim do SendWinbackEmailJob. Diferente dos outros claims de
            // retenção, este NÃO é at-most-once eterno: o winback pode repetir
            // a cada 60 dias (ausência é recorrente), então a condição do
            // UPDATE é "nulo OU mais velho que o cooldown".
            $table->timestamp('winback_email_sent_at')->nullable();

            // Claim at-most-once eterno do SendActivationNudgeEmailJob — mesmo
            // padrão de onboarding_tips_sent_at (ver docblock daquele job).
            $table->timestamp('activation_nudge_sent_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['winback_email_sent_at', 'activation_nudge_sent_at']);
        });
    }
};
