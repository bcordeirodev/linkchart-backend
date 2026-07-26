<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Cobre a resolução do IP do cliente contra forja.
 *
 * Um X-Forwarded-For forjado pelo cliente chegava a gravar o IP e o geo do clique
 * em produção (comprovado com 133.11.0.1 → country=Japan). A defesa depende de dois
 * pontos: o Nginx da borda validando a procedência (real_ip_header CF-Connecting-IP
 * restrito às faixas da Cloudflare) e o TrustProxies confiando apenas na bridge do
 * Docker, para o Symfony caminhar a cadeia da direita e descartar o token forjado.
 *
 * @see docs/superpowers/specs/2026-07-25-ip-resolution-anti-abuse-design.md
 */
class ClientIpSpoofingTest extends TestCase
{
    /**
     * Request::setTrustedProxies() é estático, então o estado vaza entre testes no
     * mesmo processo do PHPUnit. Sem este reset, um teste posterior que dependa de
     * $request->ip() passa ou falha conforme a ordem de execução.
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * Constrói uma request com a forma real de produção: REMOTE_ADDR é o gateway da
     * bridge do Docker e o X-Forwarded-For traz um token forjado pelo cliente à
     * esquerda do IP verdadeiro que o Nginx acrescentou à direita.
     *
     * @param  string  $forwardedFor  Valor do header X-Forwarded-For.
     * @param  string  $remoteAddr  Peer imediato da conexão.
     * @return Request A request montada.
     */
    private function requestThroughProxy(string $forwardedFor, string $remoteAddr = '172.19.0.1'): Request
    {
        return Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
    }

    public function test_forged_left_token_is_ignored_when_proxy_is_trusted(): void
    {
        $request = $this->requestThroughProxy('1.2.3.4, 203.0.113.10');

        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('203.0.113.10', $request->ip());
    }

    public function test_untrusted_peer_does_not_get_to_set_the_client_ip(): void
    {
        $request = $this->requestThroughProxy('1.2.3.4', remoteAddr: '198.51.100.9');

        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('198.51.100.9', $request->ip());
    }

    /**
     * Os seis rate limiters "por IP" chaveiam em $request->ip(). Duas requests com o
     * MESMO token forjado à esquerda mas clientes reais diferentes têm que gerar
     * chaves diferentes — é isso que prova que o limite voltou a ser por ator, e não
     * um bucket global nem algo que o atacante controla.
     */
    public function test_redirect_rate_limiter_keys_by_the_real_client_ip(): void
    {
        $through = function (string $forwardedFor): Request {
            $request = $this->requestThroughProxy($forwardedFor);
            (new TrustProxies)->handle($request, fn () => new Response);

            return $request;
        };

        $limiter = RateLimiter::limiter('redirect');

        $first = $limiter($through('1.2.3.4, 203.0.113.10'));
        $second = $limiter($through('1.2.3.4, 203.0.113.99'));

        $this->assertNotSame($first->key, $second->key);
    }
}
