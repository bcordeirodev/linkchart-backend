<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona users.signup_attribution — o first-touch (gclid/utm/referrer) capturado
 * pelo frontend na primeira visita e enviado no auth0-exchange, persistido apenas
 * na criação da conta. Fecha o buraco de atribuição de julho/2026, quando 8 de 15
 * cadastros ficaram sem origem conhecida.
 *
 * Migration aditiva (expand) — segura para blue/green: o código antigo ignora a
 * coluna nova.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('signup_attribution')->nullable()->after('onboarding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_attribution');
        });
    }
};
