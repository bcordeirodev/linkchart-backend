<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Declara como proxy confiável apenas o espaço de endereços das bridges do Docker.
 *
 * A validação de procedência do IP do cliente é feita **na borda**, pelo Nginx do
 * host (`real_ip_header CF-Connecting-IP` restrito às faixas da Cloudflare, em
 * /etc/nginx/conf.d/cloudflare-realip.conf). O Nginx acrescenta o IP verdadeiro à
 * direita do X-Forwarded-For; confiando na bridge, o Symfony caminha a cadeia da
 * direita para a esquerda, descarta o salto confiável e devolve esse IP — de modo
 * que um token forjado pelo cliente à esquerda é ignorado.
 *
 * Confiar num /12 em vez de um gateway fixo é deliberado: a aplicação é alcançável
 * por mais de uma rede Docker (172.18.x e 172.19.x aparecem nos logs de produção) e
 * subnets de bridge são reatribuídas quando uma rede é recriada. Isso não afrouxa a
 * segurança — só um peer dentro do Docker consegue apresentar origem nessa faixa, e
 * entre o Nginx e o PHP não existe nada além do Docker.
 *
 * @see docs/superpowers/specs/2026-07-25-ip-resolution-anti-abuse-design.md
 */
class TrustProxies extends Middleware
{
    /**
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '172.16.0.0/12',
    ];

    /**
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_PREFIX;
}
