<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo admin: flag de privilégio + registro de último login.
 *
 * Expand-only (blue/green): apenas adiciona colunas.
 *
 * - `is_admin`: gate do painel /admin. NUNCA entra no $fillable do User —
 *   promoção só por escrita explícita (tinker no droplet). Lida do banco a
 *   cada request pelo middleware EnsureUserIsAdmin (nunca vira claim de JWT:
 *   TTL de 30 dias tornaria a revogação inerte, e para contas Auth0 não há
 *   outro lever de invalidação).
 * - `last_login_at`: estampada em AuthController::login e auth0Exchange
 *   (só no login — nunca por request). Base futura de WAU/MAU real; começa
 *   a acumular a partir deste deploy.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->timestamp('last_login_at')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'last_login_at']);
        });
    }
};
