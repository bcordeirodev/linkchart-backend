<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reivindicar link (claim-your-link): prova de criação do link anônimo.
 *
 * O encurtador público cria links com `user_id = NULL`; ~87% do volume de
 * cliques em prod vem deles, sem nenhum momento de conversão anônimo → conta.
 * Esta coluna guarda o SHA-256 do token entregue em claro (uma única vez) na
 * resposta 201 do `POST /api/public/shorten` de convidado. Quem apresentar o
 * token de volta em `POST /api/links/claim` prova que criou o link e vira dono.
 *
 * Só o hash toca o banco — o token em claro nunca é persistido nem logado.
 * `NULL` cobre dois estados distintos, desambiguados pelo `user_id`:
 *   - `user_id IS NULL` + hash NULL  → link anônimo antigo (pré-feature), não
 *     reivindicável: não há prova de criação e permitir o claim seria sequestro.
 *   - `user_id NOT NULL` + hash NULL → link com dono (nasceu logado ou já foi
 *     reivindicado; o claim zera o hash para queimar o token).
 *
 * Sem índice: a busca do claim entra pelo `slug` (já único) e o hash é apenas
 * predicado de igualdade no mesmo UPDATE.
 *
 * Expand-only (blue/green): apenas adiciona coluna nullable — nada é renomeado
 * nem removido enquanto o código antigo ainda serve tráfego.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // SHA-256 em hex = 64 chars; string() padrão (varchar 255) sobra de
            // propósito para não exigir ->change() se o algoritmo mudar.
            $table->string('claim_token_hash')->nullable();
        });
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('claim_token_hash');
        });
    }
};
