<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de entrega/engajamento de e-mail vindos do webhook Brevo
 * (delivered, opened, click, bounces). Fonte do funil por campanha (`tag`):
 * enviados (claims nos models) → delivered → unique_opened → click.
 *
 * Retenção: 180 dias, via comando email-events:prune agendado diário.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            // Resolvido pelo e-mail no momento do evento; nullable porque o
            // endereço pode não ter mais conta (nullOnDelete preserva o evento
            // para o funil mesmo se o usuário sair).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('event', 32);
            $table->string('tag', 64)->nullable();
            $table->text('url')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tag', 'event']);
            $table->index('user_id');
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
