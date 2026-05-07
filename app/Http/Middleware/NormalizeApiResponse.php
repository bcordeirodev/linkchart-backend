<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Padroniza toda resposta JSON da API no formato único:
 *
 *   sucesso: { "data": T, "meta"?: {...}, "message"?: "..." }
 *   erro:    { "error": { "code": "...", "message": "...", "details"?: {...} } }
 *
 * Aceita respostas legadas dos controllers e normaliza sem exigir mudança no
 * código de domínio. Onda 0 do refactor arquitetural.
 */
class NormalizeApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        $status = $response->getStatusCode();

        $normalized = $status >= 400
            ? $this->normalizeError($payload, $status)
            : $this->normalizeSuccess($payload);

        $response->setData($normalized);

        return $response;
    }

    private function normalizeSuccess(mixed $payload): array
    {
        if ($payload === null) {
            return ['data' => null];
        }

        if (is_array($payload) && array_key_exists('data', $payload)) {
            $envelope = ['data' => $payload['data']];
            if (array_key_exists('meta', $payload)) {
                $envelope['meta'] = $payload['meta'];
            }
            if (array_key_exists('message', $payload)) {
                $envelope['message'] = $payload['message'];
            }

            return $envelope;
        }

        return ['data' => $payload];
    }

    private function normalizeError(mixed $payload, int $status): array
    {
        if (! is_array($payload)) {
            return ['error' => [
                'code' => $this->codeForStatus($status),
                'message' => (string) $payload,
            ]];
        }

        if (isset($payload['error']) && is_array($payload['error']) && isset($payload['error']['code'])) {
            return ['error' => $payload['error']];
        }

        $code = $this->codeForStatus($status);
        if (isset($payload['error']) && is_string($payload['error'])) {
            $code = $this->slugifyCode($payload['error']) ?: $code;
        }

        $message = $payload['message']
            ?? (is_string($payload['error'] ?? null) ? $payload['error'] : null)
            ?? $this->messageForStatus($status);

        $details = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, ['error', 'message'], true)) {
                continue;
            }
            $details[$key] = $value;
        }

        $error = ['code' => $code, 'message' => $message];
        if (! empty($details)) {
            $error['details'] = $details;
        }

        return ['error' => $error];
    }

    private function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'BAD_REQUEST',
            $status === 401 => 'UNAUTHENTICATED',
            $status === 403 => 'FORBIDDEN',
            $status === 404 => 'NOT_FOUND',
            $status === 409 => 'CONFLICT',
            $status === 422 => 'VALIDATION_FAILED',
            $status === 429 => 'TOO_MANY_REQUESTS',
            $status >= 500 => 'SERVER_ERROR',
            default => 'HTTP_ERROR',
        };
    }

    private function messageForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'Autenticação requerida.',
            $status === 403 => 'Acesso negado.',
            $status === 404 => 'Recurso não encontrado.',
            $status === 422 => 'Dados inválidos.',
            $status >= 500 => 'Erro interno do servidor.',
            default => 'Erro na requisição.',
        };
    }

    private function slugifyCode(string $raw): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $raw));

        return trim($slug, '_');
    }
}
