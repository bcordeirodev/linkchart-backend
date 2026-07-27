<?php

namespace Tests\Feature\Logging;

use App\Http\Middleware\RedirectMetricsCollector;
use App\Logging\AppLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Regressão do FALLBACK_LOG que aparecia na suite:
 *
 *   FALLBACK_LOG: RedirectMetricsCollector failed for slug= error=...
 *   redirectMetricsCollected(): Argument #1 ($slug) must be of type string, null given
 *
 * Quando a request não tem slug resolvível (`$request->route('slug')` devolve
 * null — sem route resolver, rota sem parâmetro, etc.), o middleware chamava
 * `AppLogger::redirectMetricsCollected(null, ...)` → TypeError → o catch chamava
 * `AppLogger::redirectMetricsFailed(null, ...)` → segundo TypeError → a
 * telemetria daquela request era perdida no `error_log` de fallback.
 */
class RedirectMetricsNullSlugTest extends TestCase
{
    /**
     * Garante que uma request sem slug resolvível ainda registra a métrica final
     * (`redirect.metrics_collected`) com o sentinela 'unknown', sem cair no
     * caminho de falha nem no fallback de `error_log`.
     */
    public function test_metrics_are_collected_when_the_request_has_no_resolvable_slug(): void
    {
        $handler = new TestHandler;
        Log::channel('redirect')->getLogger()->pushHandler($handler);

        // Sem route resolver vinculado, $request->route('slug') devolve null —
        // mesma condição de uma request que o middleware cobre sem {slug} resolvido.
        $request = Request::create('/r/abc123', 'GET');
        $this->assertNull($request->route('slug'), 'A request de teste precisa realmente não ter slug resolvido.');

        app(RedirectMetricsCollector::class)->handle($request, fn (): Response => new Response('ok'));

        $collected = array_values(array_filter(
            $handler->getRecords(),
            fn ($record): bool => $record->message === AppLogger::REDIRECT_METRICS_OK,
        ));

        $this->assertCount(
            1,
            $collected,
            'A métrica final não foi registrada — o coletor caiu no fallback para uma request sem slug.'
        );
        $this->assertSame(
            'unknown',
            $collected[0]->context['slug'],
            'Slug ausente deve virar o sentinela "unknown" na telemetria, não null.'
        );

        $failed = array_filter(
            $handler->getRecords(),
            fn ($record): bool => $record->message === AppLogger::REDIRECT_METRICS_FAILED,
        );

        $this->assertSame([], array_values($failed), 'O coletor não deve tratar slug ausente como falha de coleta.');
    }
}
