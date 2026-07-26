<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use App\Support\ClientIpResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
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

    public function test_from_request_returns_the_trusted_client_ip(): void
    {
        $request = $this->requestThroughProxy('1.2.3.4, 203.0.113.10');
        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('203.0.113.10', (new ClientIpResolver)->fromRequest($request));
    }

    public function test_from_request_honours_real_ip_override_outside_production(): void
    {
        config(['app.env' => 'local']);
        $request = Request::create('/?real_ip=198.51.100.7', 'GET', [], [], [], [
            'REMOTE_ADDR' => '172.19.0.1',
        ]);

        $this->assertSame('198.51.100.7', (new ClientIpResolver)->fromRequest($request));
    }

    public function test_from_request_ignores_real_ip_override_in_production(): void
    {
        config(['app.env' => 'production']);
        $request = $this->requestThroughProxy('203.0.113.10');
        $request->query->set('real_ip', '198.51.100.7');
        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('203.0.113.10', (new ClientIpResolver)->fromRequest($request));
    }

    /**
     * Modo de falha SILENCIOSO: se o real_ip_header da borda não estiver ativo, o IP
     * resolvido passa a ser a borda da Cloudflare — que é público e portanto escapa
     * da checagem de IP privado. Comparar contra o CF-Connecting-IP é o que denuncia.
     */
    public function test_warns_when_resolved_ip_disagrees_with_cf_connecting_ip(): void
    {
        config(['app.env' => 'production']);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'ip.edge_chain_mismatch'
                && $context['resolved_ip'] === '203.0.113.10'
                && $context['cf_connecting_ip'] === '198.51.100.7');

        $request = $this->requestThroughProxy('203.0.113.10');
        $request->headers->set('CF-Connecting-IP', '198.51.100.7');
        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('203.0.113.10', (new ClientIpResolver)->fromRequest($request));
    }

    public function test_does_not_warn_when_edge_chain_is_consistent(): void
    {
        config(['app.env' => 'production']);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        $request = $this->requestThroughProxy('203.0.113.10');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');
        (new TrustProxies)->handle($request, fn () => new Response);

        $this->assertSame('203.0.113.10', (new ClientIpResolver)->fromRequest($request));
    }

    public function test_is_public_ip_rejects_private_and_reserved_ranges(): void
    {
        $resolver = new ClientIpResolver;

        $this->assertTrue($resolver->isPublicIp('203.0.113.10'));
        $this->assertFalse($resolver->isPublicIp('172.19.0.1'));
        $this->assertFalse($resolver->isPublicIp('127.0.0.1'));
        $this->assertFalse($resolver->isPublicIp('nao-e-ip'));
    }
}
