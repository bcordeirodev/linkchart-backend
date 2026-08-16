<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Escada de marcos de cliques: guarda o maior degrau já comemorado do link
 * (10/25/50/100/250/500/1000), substituindo o claim binário de 100 cliques.
 *
 * Expand-only (blue/green): adiciona a coluna e faz backfill dos marcos de 100
 * já comemorados. `milestone_100_notified_at` FICA — o contract (drop) é de um
 * release futuro, quando nenhum código antigo estiver servindo tráfego.
 */
return new class extends Migration
{
    /** {@inheritDoc} */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Claim at-most-once por degrau: o UPDATE condicional
            // (`milestone_last_threshold < :t`) reivindica o envio — ver
            // docblock de SendMilestoneEmailJob.
            $table->integer('milestone_last_threshold')->default(0);
        });

        // Quem já comemorou o marco de 100 no modelo antigo não pode recebê-lo
        // de novo na escada.
        DB::table('links')
            ->whereNotNull('milestone_100_notified_at')
            ->update(['milestone_last_threshold' => 100]);
    }

    /** {@inheritDoc} */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('milestone_last_threshold');
        });
    }
};
