<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * 🌐 MIDDLEWARE PARA CONFIAR EM PROXIES E CAPTURAR IP REAL
 *
 * FUNCIONALIDADE:
 * - Configura Laravel para confiar em proxies (Nginx, Cloudflare, etc.)
 * - Permite leitura correta de headers X-Forwarded-For e X-Real-IP
 * - Essencial para capturar IP real do usuário em arquiteturas com proxy
 */
class TrustProxies extends Middleware
{
    /**
     * Os proxies confiáveis para esta aplicação.
     *
     * CONFIGURAÇÃO:
     * - '*' = Confiar em todos os proxies (recomendado para desenvolvimento)
     * - Para produção, especificar IPs específicos dos proxies
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * Os headers que devem ser usados para detectar proxies.
     *
     * HEADERS SUPORTADOS:
     * - X-Forwarded-For: Lista de IPs (padrão da indústria)
     * - X-Forwarded-Host: Host original
     * - X-Forwarded-Port: Porta original
     * - X-Forwarded-Proto: Protocolo original (HTTP/HTTPS)
     *
     * Nota: X-Real-IP será tratado manualmente no LinkTrackingService
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Determina se o request deve ser confiável.
     *
     * LÓGICA PERSONALIZADA:
     * - Sempre confiar em desenvolvimento
     * - Em produção, validar origem do proxy
     */
    protected function shouldTrustRequest(Request $request): bool
    {
        // Em desenvolvimento, sempre confiar
        if (config('app.env') === 'local' || config('app.env') === 'development') {
            return true;
        }

        // Em produção, validar se tem headers de proxy válidos
        return $request->hasHeader('X-Forwarded-For') ||
               $request->hasHeader('X-Real-IP') ||
               $request->hasHeader('CF-Connecting-IP'); // Cloudflare
    }
}
