<?php

namespace Tests\Unit\Services;

use App\Services\EmailService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre o caminho Brevo do EmailService e o roteador sendTransactionalEmail().
 *
 * Contrato sob teste (o mesmo do caminho SendGrid): os métodos NUNCA lançam —
 * devolvem ['success' => bool, ...] e o caller decide. Ver
 * SendWelcomeEmailJob para o porquê de inspecionar `success` ser obrigatório.
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

    /** O roteador usa Brevo por default (TRANSACTIONAL_EMAIL_PROVIDER ausente). */
    public function test_transactional_router_defaults_to_brevo(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'x'], 201),
        ]);

        $result = $this->service->sendTransactionalEmail('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertTrue($result['success']);
        $this->assertSame('Brevo API', $result['method']);
        Http::assertSentCount(1);
    }

    /**
     * Kill switch: com TRANSACTIONAL_EMAIL_PROVIDER=sendgrid o roteador volta pro
     * caminho antigo. Sem SENDGRID_API_KEY no ambiente de teste o método curto-circuita
     * (success=false, sem rede) — o que basta para provar o roteamento.
     */
    public function test_transactional_router_falls_back_to_sendgrid_when_configured(): void
    {
        Http::fake();
        config([
            'services.transactional_email.provider' => 'sendgrid',
            'services.sendgrid.api_key' => null,
        ]);

        $result = $this->service->sendTransactionalEmail('ana@example.com', 'Assunto', '<p>Olá</p>');

        $this->assertSame('SendGrid API', $result['method']);
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }
}
