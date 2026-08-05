<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail de dicas do terceiro dia: guarda at-most-once por usuário.
 *
 * Expand-only (blue/green): apenas adiciona coluna — nada é renomeado nem
 * removido enquanto o código antigo ainda serve tráfego.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Claim at-most-once do SendOnboardingTipsEmailJob — mesmo padrão
            // de welcome_email_sent_at (ver docblock daquele job).
            $table->timestamp('onboarding_tips_sent_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_tips_sent_at');
        });
    }
};
