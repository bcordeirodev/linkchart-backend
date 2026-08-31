<?php

namespace App\Http\Middleware;

use App\Logging\AppLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra no canal `http` toda resposta de erro servida pelo Laravel:
 * 4xx como warning, 5xx como error.
 *
 * Por que existe: o canal `http` foi desenhado para ser o painel de "o que os
 * clientes estão recebendo de errado", mas nunca teve escritor para o lado 4xx
 * — `AppLogger::httpClientError()` nasceu em 2026-05-07 sem nenhum chamador e
 * foi removida em 2026-06-16 como código morto. O único escritor que sobrou era
 * o renderer de exceção do bootstrap (5xx), e por isso `storage/logs/http-*.log`
 * ficou parado em 2026-05-13 enquanto endpoints reais devolviam 4xx todo dia
 * (auditoria de 2026-08-31). Este middleware é o escritor que faltava.
 *
 * Registrado como middleware GLOBAL (bootstrap/app.php) e não de grupo, porque
 * rota não encontrada cai no `Route::fallback()`, que roda fora dos grupos
 * web/api — logar 404 de rota inexistente é metade do valor do canal. O
 * middleware inspeciona a resposta na volta do pipeline, então `$request->route()`
 * já está resolvido mesmo rodando antes do roteamento.
 *
 * Exceções ficam de fora em dois pontos:
 *  - o hot path de redirect (`/r/{slug}` e `/{slug}`), que tem canal próprio
 *    (`redirect`) e volume de 600 req/min por IP — 404 de slug inexistente é
 *    tráfego normal ali, não sinal de defeito;
 *  - respostas 5xx que o renderer de exceção do bootstrap já registrou com
 *    stack trace, marcadas via {@see self::ATTR_LOGGED} para não duplicar.
 */
final class LogHttpErrors
{
    /**
     * Atributo do request que marca "esta resposta de erro já foi logada".
     *
     * Setado pelo renderer de exceção em bootstrap/app.php, que loga o 500 com
     * o stack completo via AppLogger::httpServerError().
     */
    public const ATTR_LOGGED = 'http.error_logged';

    /**
     * Rotas do hot path de redirect, isentas do log de erro HTTP.
     *
     * Mesma lista de OtelTrace::REDIRECT_ROUTE_NAMES.
     *
     * @var list<string>
     */
    private const REDIRECT_ROUTE_NAMES = [
        'public.redirect',
        'public.redirect.clean',
    ];

    /**
     * Deixa a request seguir e loga a resposta quando ela for 4xx/5xx.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->logErrorResponse($request, $response);
        } catch (Throwable) {
            // Observabilidade nunca pode derrubar a resposta.
        }

        return $response;
    }

    /**
     * Escreve a linha no canal `http` quando a resposta é de erro e não está
     * isenta (hot path de redirect ou 5xx já logado com exceção).
     */
    private function logErrorResponse(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return;
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::REDIRECT_ROUTE_NAMES, true)) {
            return;
        }

        if ($request->attributes->getBoolean(self::ATTR_LOGGED)) {
            return;
        }

        $context = array_filter([
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $this->userId($request),
            'error_code' => $this->errorCode($response),
        ], static fn ($value) => $value !== null);

        if ($status >= 500) {
            AppLogger::httpServerErrorResponse($this->routeLabel($request), $status, $context);

            return;
        }

        AppLogger::httpClientError($this->routeLabel($request), $status, $context);
    }

    /**
     * Rótulo estável do endpoint: nome da rota, senão o template de URI,
     * senão o path. Nunca o path cru quando existe template — é o que faz o
     * log agregar por endpoint em vez de por valor de parâmetro.
     */
    private function routeLabel(Request $request): string
    {
        $route = $request->route();

        return $route?->getName() ?: ($route?->uri() ?: $request->path());
    }

    /**
     * Id do usuário autenticado, ou null. Resolver o guard pode lançar quando o
     * JWT está corrompido — exatamente um dos casos que geram 401 aqui —, então
     * a falha vira null em vez de engolir a linha de log inteira.
     */
    private function userId(Request $request): ?int
    {
        try {
            return $request->user()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Código de erro do envelope `{error: {code}}` do NormalizeApiResponse.
     *
     * É o que distingue causas dentro de um mesmo status (um 422 pode ser
     * VALIDATION_FAILED ou auth0_email_unverified). Só respostas JSON são
     * inspecionadas, e apenas o formato de envelope da API.
     */
    private function errorCode(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        $data = $response->getData(true);

        if (! is_array($data)) {
            return null;
        }

        $code = $data['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }
}
