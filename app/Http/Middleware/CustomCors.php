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

        // Se é uma requisição OPTIONS (preflight), responder imediatamente
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        // Aplicar headers CORS sempre
        $response->headers->set('Access-Control-Allow-Origin', $origin ?: '*');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization,Accept,Origin');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Length,Content-Range');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
