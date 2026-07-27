<?php

namespace Tests\Feature\Logging;

use App\Http\Middleware\RedirectMetricsCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * Cobre duas coisas que se cruzaram em produção em 2026-07-27.
 *
 * 1. Roteamento de deprecations. O `.env` de produção tinha
 *    `LOG_DEPRECATIONS_CHANNEL=null` com a intenção de descartá-las — mas o dotenv
 *    converte a string `null` em **PHP null**, e PHP null faz o Laravel usar o canal
 *    **default** (`stack`, que inclui o OTLP). O efeito foi o oposto do pretendido:
 *    80 deprecations do `symfony/console` por dia, disparadas por invocações de
 *    artisan, indo para o Loki e representando 82% de tudo que havia no canal de
 *    warning — afogando os warnings reais da aplicação.
 *
 * 2. O `substr()` com null. `RedirectMetricsCollector` fazia
 *    `substr($request->userAgent(), 0, 100)`, e `userAgent()` devolve null quando a
 *    requisição não manda o header — o que scanners fazem. Deprecation hoje,
 *    TypeError numa versão futura do PHP, no hot path de cliques. Detalhe: o log que
 *    consome esse valor é `debug` e está suprimido em produção, mas o `substr` era
 *    avaliado de qualquer forma, porque o array de contexto é montado antes do
 *    filtro de nível.
 *
 * ⚠️ Os dois testes precisam recriar a condição exata, senão passam sem ter dente:
 * o canal só quebra quando a env var **existe** valendo null (não quando está
 * ausente), e o `substr` só recebe null quando o header está **removido** — o
 * `Request::create` do Symfony injeta um User-Agent default.
 */
class DeprecationRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        // O repositório de env é estático; sem limpar, vaza para outros testes.
        Env::getRepository()->clear('LOG_DEPRECATIONS_CHANNEL');

        parent::tearDown();
    }

    /**
     * Reproduz a condição de produção: a env var existe e vale null depois da
     * conversão do dotenv. O `env()` com valor default NÃO protege disso, porque o
     * default só se aplica quando a chave está ausente.
     */
    public function test_deprecations_channel_survives_an_env_var_valued_null(): void
    {
        Env::getRepository()->set('LOG_DEPRECATIONS_CHANNEL', 'null');

        $config = require config_path('logging.php');
        $channel = $config['deprecations']['channel'];

        $this->assertNotNull(
            $channel,
            'Com LOG_DEPRECATIONS_CHANNEL=null o canal virou PHP null, e PHP null faz o Laravel '
            .'usar o canal default — mandando deprecations para o mesmo lugar dos logs da aplicação.'
        );

        $this->assertArrayHasKey($channel, $config['channels'], "Canal '{$channel}' não existe.");
        $this->assertNotSame($config['default'], $channel, 'Deprecations não devem cair no canal da aplicação.');
    }

    /**
     * Invoca o middleware direto, com o header removido de verdade — pelo kernel HTTP
     * o Symfony injetaria um User-Agent default e o null nunca aconteceria.
     */
    public function test_request_without_user_agent_raises_no_substr_deprecation(): void
    {
        $request = Request::create('/r/abc123', 'GET');
        $request->headers->remove('User-Agent');
        $request->server->remove('HTTP_USER_AGENT');

        $this->assertNull($request->userAgent(), 'A request de teste precisa realmente não ter User-Agent.');

        $deprecations = [];
        set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $deprecations[] = $message;
            }

            return true;
        });

        try {
            app(RedirectMetricsCollector::class)->handle($request, fn (): Response => new Response('ok'));
        } finally {
            restore_error_handler();
        }

        $offenders = array_values(array_filter(
            $deprecations,
            fn (string $m): bool => str_contains($m, 'substr')
        ));

        $this->assertSame([], $offenders, 'substr() recebeu null a partir de uma request sem User-Agent.');
    }
}
