<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

                // Origens permitidas (usar configuração do .env)
        $corsOrigins = env('CORS_ALLOWED_ORIGINS', 'http://134.209.33.182,http://134.209.33.182:3000');
        $allowedOrigins = explode(',', $corsOrigins);
        $allowedOrigins = array_map('trim', $allowedOrigins);

        // Verificar se origem é permitida
        $isOriginAllowed = empty($origin) || in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins);

        // Se é uma requisição OPTIONS (preflight), responder imediatamente
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        // Aplicar headers CORS
        if ($isOriginAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } elseif (in_array('*', $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
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
