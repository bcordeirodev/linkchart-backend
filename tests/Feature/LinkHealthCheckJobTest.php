<?php

namespace Tests\Feature;

use App\Jobs\LinkHealthCheckJob;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Covers the LinkHealthCheckJob lifecycle instrumentation added after the
 * 2026-07-16 observability report found the job was a telemetry blind spot
 * (it ran hourly but emitted no logs), plus the 2026-07-26 accuracy fix.
 *
 * Antes do fix de 2026-07-26 o job marcava ~23% dos links ativos como 'error'
 * estando vivos: fazia só HEAD (muitos hosts respondem 405/403), usava o
 * User-Agent default do Guzzle (levava 403 de proteção anti-bot) e tratava 429 e
 * falha de conexão como link quebrado. Agora **'error' só quando o destino
 * respondeu ativamente com erro** — o resto é 'unknown', porque não sabemos.
 *
 * URLs point at 127.0.0.1 on a closed port so the Guzzle check fails
 * instantly (connection refused) without ever leaving the machine.
 */
class LinkHealthCheckJobTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    /** URL whose check fails immediately with connection refused (port 9 = discard). */
    private const UNREACHABLE_URL = 'http://127.0.0.1:9/health-check-test';

    /**
     * Destino inalcançável não é evidência de link quebrado: pode ser bloqueio do IP
     * do droplet (foi o caso de anatel.com.br em produção). Vira 'unknown'.
     */
    public function test_unreachable_destination_is_unknown_not_error(): void
    {
        $link = $this->makeLink(['original_url' => self::UNREACHABLE_URL]);

        (new LinkHealthCheckJob)->handle();

        $row = DB::table('links')->where('id', $link->id)->first(['health_status', 'health_checked_at']);
        $this->assertSame('unknown', $row->health_status);
        $this->assertNotNull($row->health_checked_at);
    }

    public function test_logs_lifecycle_with_checked_error_and_unknown_totals(): void
    {
        $this->makeLink(['original_url' => self::UNREACHABLE_URL]);

        // Capture the lifecycle calls on the 'jobs' channel via a spy.
        $jobsSpy = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('jobs')->andReturn($jobsSpy);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        (new LinkHealthCheckJob)->handle();

        $jobsSpy->shouldHaveReceived('info')
            ->with('job.started', \Mockery::on(fn ($ctx) => ($ctx['job'] ?? null) === LinkHealthCheckJob::class));
        $jobsSpy->shouldHaveReceived('info')
            ->with('job.succeeded', \Mockery::on(fn ($ctx) => ($ctx['job'] ?? null) === LinkHealthCheckJob::class
                && ($ctx['checked'] ?? null) === 1
                && ($ctx['errors'] ?? null) === 0
                && ($ctx['unknown'] ?? null) === 1));
    }

    public function test_skips_inactive_links(): void
    {
        $link = $this->makeLink(['original_url' => self::UNREACHABLE_URL, 'is_active' => false]);

        (new LinkHealthCheckJob)->handle();

        $this->assertNull(DB::table('links')->where('id', $link->id)->value('health_checked_at'));
    }

    /**
     * canva.link e tiktok.com respondem 403 a HEAD e 200 a GET. Sem o fallback,
     * ambos ficavam marcados como quebrados em produção.
     */
    public function test_head_rejected_falls_back_to_get(): void
    {
        $link = $this->makeLink(['original_url' => 'https://exemplo.test/pagina']);

        $this->runJobWithResponses([new Response(403), new Response(200)]);

        $this->assertSame('ok', DB::table('links')->where('id', $link->id)->value('health_status'));
    }

    public function test_destination_answering_not_found_is_error(): void
    {
        $link = $this->makeLink(['original_url' => 'https://exemplo.test/sumiu']);

        $this->runJobWithResponses([new Response(404), new Response(404)]);

        $this->assertSame('error', DB::table('links')->where('id', $link->id)->value('health_status'));
    }

    /**
     * 429 é o destino nos limitando (chat.whatsapp.com fazia isso, porque o job
     * martelava 9 links do mesmo host em sequência) — não diz nada sobre o link.
     */
    public function test_rate_limited_destination_is_unknown(): void
    {
        $link = $this->makeLink(['original_url' => 'https://exemplo.test/limitado']);

        $this->runJobWithResponses([new Response(429)]);

        $this->assertSame('unknown', DB::table('links')->where('id', $link->id)->value('health_status'));
    }

    public function test_redirect_to_live_destination_is_ok(): void
    {
        $link = $this->makeLink(['original_url' => 'https://exemplo.test/redireciona']);

        $this->runJobWithResponses([new Response(301, ['Location' => 'https://exemplo.test/final'])]);

        $this->assertSame('ok', DB::table('links')->where('id', $link->id)->value('health_status'));
    }

    /**
     * Roda o job com respostas HTTP pré-programadas, sem tocar a rede.
     *
     * @param  array<int, Response>  $responses  Respostas na ordem em que serão servidas.
     */
    private function runJobWithResponses(array $responses): void
    {
        (new FakeHttpLinkHealthCheckJob($responses))->handle();
    }
}

/**
 * Test double que injeta respostas HTTP pré-programadas.
 *
 * O client não pode ser propriedade do job (ele precisa continuar serializável para
 * a fila), então `httpClient()` é um seam protected justamente para isto.
 */
class FakeHttpLinkHealthCheckJob extends LinkHealthCheckJob
{
    /**
     * @param  array<int, Response>  $responses  Respostas na ordem em que serão servidas.
     */
    public function __construct(private array $responses) {}

    protected function httpClient(): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($this->responses)),
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }
}
