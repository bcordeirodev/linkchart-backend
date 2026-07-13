<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Garante que nenhuma migration execute operacao destrutiva no up().
 *
 * O deploy usa blue/green: as migrations rodam enquanto a versao ANTIGA do
 * codigo ainda esta servindo trafego. Um dropColumn/renameColumn no up()
 * quebraria a cor ativa no meio do deploy.
 *
 * Regra (expand/contract): adicione a coluna nova -> deploy -> so no release
 * SEGUINTE remova a antiga. Nunca renomeie nem derrube numa tacada.
 *
 * Custo real desta regra: zero. Em 34 migrations o projeto nunca teve uma
 * operacao destrutiva no up() — apenas 3 `->change()`, todos alargando ou
 * afrouxando (nullable, varchar maior), que sao retrocompativeis por natureza.
 *
 * Escape hatch: para uma migration destrutiva legitima, marque o arquivo com o
 * comentario `@offline-migration` e dispare o release com `migration_mode:
 * offline` (o deploy para a app, migra e sobe de novo, com ~20s de downtime).
 */
class MigrationSafetyTest extends TestCase
{
    /**
     * Operacoes que quebram codigo antigo ainda em execucao.
     *
     * `Schema::dropIfExists` cobre tambem `Schema::drop` por prefixo, mas
     * ambos ficam listados para a mensagem de erro ser explicita.
     */
    private const DESTRUCTIVE = [
        'dropColumn',
        'renameColumn',
        'Schema::drop',
        'Schema::rename',
    ];

    /**
     * Nenhuma migration pode ter operacao destrutiva dentro do up().
     */
    public function test_no_migration_has_destructive_operation_in_up(): void
    {
        $violations = [];

        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            $source = file_get_contents($file);

            // Escape hatch explicito para migration destrutiva intencional.
            if (str_contains($source, '@offline-migration')) {
                continue;
            }

            $up = $this->extractUpBody($source);

            foreach (self::DESTRUCTIVE as $op) {
                if (str_contains($up, $op)) {
                    $violations[] = basename($file).' -> '.$op;
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", [
            '',
            'Migration destrutiva no up(). O deploy e blue/green: isto roda com o',
            'codigo ANTIGO ainda servindo trafego e vai quebra-lo.',
            '',
            'Corrija com expand/contract: adicione a coluna nova -> deploy -> so no',
            'release SEGUINTE remova a antiga.',
            '',
            'Se a operacao destrutiva for mesmo necessaria agora, marque a migration',
            'com `@offline-migration` e dispare o release com migration_mode=offline.',
            '',
            'Violacoes:',
            ...$violations,
            '',
        ]));
    }

    /**
     * Extrai o corpo do up(), parando no down().
     *
     * O down() so roda em rollback manual, nunca no deploy — por isso um
     * dropColumn la e legitimo e nao deve reprovar o build.
     */
    private function extractUpBody(string $source): string
    {
        $start = strpos($source, 'function up()');

        if ($start === false) {
            return '';
        }

        $end = strpos($source, 'function down()', $start);

        return $end === false
            ? substr($source, $start)
            : substr($source, $start, $end - $start);
    }
}
