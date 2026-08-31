<?php

namespace Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Guarda o canal `http` (App\Http\Middleware\LogHttpErrors).
 *
 * O canal ficou sem uma única linha de 4xx desde 2026-05-13 porque nunca teve
 * escritor para esse lado — nada falhava, simplesmente ninguém logava. Estes
 * testes existem para que a próxima remoção de "código morto" quebre aqui.
 */
class HttpErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Espia o canal `http` deixando os demais canais inertes.
     *
     * @return \Mockery\MockInterface&\Psr\Log\LoggerInterface
     */
    private function spyHttpChannel()
    {
        $spy = \Mockery::spy(LoggerInterface::class);

        Log::shouldReceive('channel')->with('http')->andReturn($spy);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('notice')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('critical')->andReturnNull();
        Log::shouldReceive('log')->andReturnNull();

        return $spy;
    }

    /** 404 de rota inexistente (Route::fallback, fora dos grupos) vira warning. */
    public function test_logs_unknown_route_404_as_warning(): void
    {
        $spy = $this->spyHttpChannel();

        $this->getJson('/api/rota-que-nao-existe')->assertStatus(404);

        $spy->shouldHaveReceived('warning')->with(
            'http.client_error',
            \Mockery::on(fn ($ctx) => ($ctx['status'] ?? null) === 404
                && ($ctx['method'] ?? null) === 'GET'
                && ($ctx['path'] ?? null) === 'api/rota-que-nao-existe'),
        );
    }

    /** 401 de rota protegida entra com o código do envelope de erro da API. */
    public function test_logs_unauthenticated_401_with_error_code(): void
    {
        $spy = $this->spyHttpChannel();

        $this->getJson('/api/links')->assertStatus(401);

        $spy->shouldHaveReceived('warning')->with(
            'http.client_error',
            \Mockery::on(fn ($ctx) => ($ctx['status'] ?? null) === 401
                && ($ctx['error_code'] ?? null) === 'UNAUTHENTICATED'),
        );
    }

    /**
     * 500 de exceção não tratada é logado UMA vez — pelo renderer do bootstrap,
     * que carrega o stack. O middleware respeita a marca e não duplica.
     */
    public function test_uncaught_exception_500_is_logged_once_with_the_exception(): void
    {
        Route::get('/api/_test/boom', function () {
            throw new \RuntimeException('boom de teste');
        });

        $spy = $this->spyHttpChannel();

        $this->getJson('/api/_test/boom')->assertStatus(500);

        $spy->shouldHaveReceived('error')
            ->with('http.server_error', \Mockery::on(fn ($ctx) => ($ctx['error'] ?? null) === 'boom de teste'))
            ->once();
        $spy->shouldNotHaveReceived('warning');
    }

    /** O hot path de redirect tem canal próprio e fica fora do canal `http`. */
    public function test_redirect_hot_path_is_exempt(): void
    {
        $spy = $this->spyHttpChannel();

        $this->get('/r/slug-que-nao-existe');

        $spy->shouldNotHaveReceived('warning');
        $spy->shouldNotHaveReceived('error');
    }

    /** Resposta de sucesso não escreve nada no canal. */
    public function test_successful_response_is_not_logged(): void
    {
        $spy = $this->spyHttpChannel();

        $this->get('/health')->assertOk();

        $spy->shouldNotHaveReceived('warning');
        $spy->shouldNotHaveReceived('error');
    }
}
