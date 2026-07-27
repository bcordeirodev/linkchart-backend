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
     * O down() so pode dropar tabelas que o proprio up() criou.
     *
     * Rollback e manual e raro, mas um `Schema::dropIfExists` de tabela alheia
     * no down() transforma um `migrate:rollback` acidental em perda da tabela
     * inteira — incluindo dados que a migration nunca tocou. O caso inverso
     * (dropar um nome que o up() nao criou por typo) tambem e bug: o rollback
     * "funciona" e deixa a tabela real para tras.
     */
    public function test_down_only_drops_tables_created_by_up(): void
    {
        $violations = [];

        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            $source = file_get_contents($file);

            $created = $this->tableNames($this->extractUpBody($source), 'create');
            $dropped = $this->tableNames($this->extractDownBody($source), 'drop');

            foreach (array_diff($dropped, $created) as $table) {
                $violations[] = basename($file)." -> drop de '{$table}' sem Schema::create correspondente no up()";
            }
        }

        $this->assertSame([], $violations, implode("\n", [
            '',
            'down() dropando tabela que o up() nao criou. Um rollback acidental',
            'destruiria dados que a migration nunca tocou (ou, no caso de typo,',
            'deixaria a tabela real para tras).',
            '',
            'Migration que so ALTERA uma tabela deve reverter apenas a alteracao',
            '(ex.: dropColumn da coluna adicionada), nunca dropar a tabela.',
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

    /**
     * Extrai o corpo do down() (do cabecalho ate o fim do arquivo).
     */
    private function extractDownBody(string $source): string
    {
        $start = strpos($source, 'function down()');

        return $start === false ? '' : substr($source, $start);
    }

    /**
     * Nomes de tabela passados a Schema::create ou Schema::drop[IfExists]
     * dentro de um trecho de codigo de migration.
     *
     * @return string[]
     */
    private function tableNames(string $body, string $operation): array
    {
        $pattern = $operation === 'create'
            ? '/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/'
            : '/Schema::(?:dropIfExists|drop)\(\s*[\'"]([^\'"]+)[\'"]/';

        preg_match_all($pattern, $body, $matches);

        return $matches[1];
    }
}
