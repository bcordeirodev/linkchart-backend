<?php

namespace Tests\Unit\Services;

use App\Services\EmailService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre o caminho Brevo do EmailService — hoje o único transporte de e-mail
 * transacional — e a fachada sendTransactionalEmail().
 *
 * Contrato sob teste: os métodos NUNCA lançam — devolvem
 * ['success' => bool, ...] e o caller decide. Ver SendWelcomeEmailJob para o
 * porquê de inspecionar `success` ser obrigatório.
 */
class EmailServiceBrevoTest extends TestCase
{
    private EmailService $service;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmailService;

        config([
            'services.brevo.api_key' => 'xkeysib-test-key',
            'services.brevo.from.email' => 'noreply@linkcharts.com.br',
            'services.brevo.from.name' => 'Link Charts',
        ]);
    }

    /** Envio feliz: POST na API v3 com sender/to/subject/conteúdo e header api-key. */
    public function test_sends_via_brevo_api_and_reports_success(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<abc@smtp-relay.mailin.fr>'], 201),
        ]);

        $result = $this->service->sendEmailViaBrevoAPI(
            'ana@example.com',
            'Assunto de teste',
            '<p>Olá</p>',
            'Olá',
            'Ana Souza',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Brevo API', $result['method']);
        $this->assertSame(201, $result['status_code']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test-key')
                && $request['sender'] === ['name' => 'Link Charts', 'email' => 'noreply@linkcharts.com.br']
                && $request['to'] === [['email' => 'ana@example.com', 'name' => 'Ana Souza']]
                && $request['subject'] === 'Assunto de teste'
                && $request['htmlContent'] === '<p>Olá</p>'
                && $request['textContent'] === 'Olá';
        });
    }

    /** Sem textContent, o campo não vai no payload (a API rejeita string vazia). */
    public function test_omits_text_content_when_not_provided(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'x'], 201),
        ]);

        $result = $this->service->sendEmailViaBrevoAPI('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $request) => ! array_key_exists('textContent', $request->data()));
    }

    /** Recusa da API (4xx/5xx) vira payload de erro — sem exceção. */
    public function test_api_rejection_returns_error_payload_without_throwing(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401),
        ]);

        $result = $this->service->sendEmailViaBrevoAPI('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertFalse($result['success']);
        $this->assertSame('Brevo API', $result['method']);
        $this->assertSame(401, $result['status_code']);
        $this->assertStringContainsString('Key not found', $result['error']);
    }

    /** Falha de rede também vira payload de erro — o contrato "nunca lança" vale. */
    public function test_network_failure_returns_error_payload_without_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = $this->service->sendEmailViaBrevoAPI('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('timeout', $result['error']);
    }

    /** Chave ausente ou placeholder: curto-circuito sem tocar a rede. */
    public function test_missing_or_placeholder_api_key_short_circuits(): void
    {
        Http::fake();

        config(['services.brevo.api_key' => null]);
        $this->assertFalse($this->service->sendEmailViaBrevoAPI('a@b.com', 'S', '<p>x</p>')['success']);

        config(['services.brevo.api_key' => 'BREVO_API_KEY_PLACEHOLDER']);
        $this->assertFalse($this->service->sendEmailViaBrevoAPI('a@b.com', 'S', '<p>x</p>')['success']);

        Http::assertNothingSent();
    }

    /** sendTransactionalEmail() entrega pela API do Brevo. */
    public function test_transactional_email_goes_through_brevo(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'x'], 201),
        ]);

        $result = $this->service->sendTransactionalEmail('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertTrue($result['success']);
        $this->assertSame('Brevo API', $result['method']);
        Http::assertSentCount(1);
    }

    /** O type do e-mail viaja até o log email.sent (observabilidade por tipo). */
    public function test_email_type_reaches_the_sent_log(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'x'], 201),
        ]);

        $channelSpy = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturn($channelSpy);

        $this->service->sendTransactionalEmail(
            'ana@example.com', 'Assunto', '<p>Olá</p>', null, null, 'weekly_digest',
        );

        $channelSpy->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'email.sent'
                && ($context['type'] ?? null) === 'weekly_digest');
    }
}
