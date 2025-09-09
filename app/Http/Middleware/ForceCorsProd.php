<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCorsProd
{
    /**
     * Handle an incoming request.
     * Este middleware garante que CORS sempre funcione, mesmo em caso de erro 500
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Se é uma requisição OPTIONS (preflight), responder imediatamente
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflight($request);
        }

        // Processar a requisição
        $response = $next($request);

        // Aplicar headers CORS na resposta
        return $this->addCorsHeaders($request, $response);
    }

    /**
     * Handle preflight OPTIONS request
     */
    private function handlePreflight(Request $request): Response
    {
        $response = response('', 204);
        return $this->addCorsHeaders($request, $response);
    }

    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');

        // Origens permitidas para produção
        $allowedOrigins = [
            'http://134.209.33.182',
            'http://134.209.33.182:3000',
            'http://localhost:3000',
            'http://127.0.0.1:3000'
        ];

        // Verificar se a origem é permitida
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } else {
            // Fallback para produção
            $response->headers->set('Access-Control-Allow-Origin', 'http://134.209.33.182');
        }

        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization,Accept,Origin,X-CSRF-Token');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Length,Content-Range,Authorization');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
