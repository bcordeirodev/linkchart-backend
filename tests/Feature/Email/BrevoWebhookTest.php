<?php

namespace Tests\Feature\Email;

use App\Models\EmailEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook de eventos do Brevo (POST público, autenticado por token na query).
 *
 * Contrato: token válido ⇒ SEMPRE 200, porque o Brevo re-tenta em qualquer
 * resposta não-2xx e uma retentativa eterna por e-mail desconhecido não ajuda
 * ninguém. Token errado/ausente ⇒ 403 sem efeito nenhum.
 *
 * Eventos de entrega definitivamente falha (hard_bounce, spam, blocked,
 * invalid_email) desligam `weekly_digest_enabled` — continuar mandando para um
 * endereço que já ricocheteou queima a reputação do domínio remetente.
 *
 * As respostas do grupo `api` passam pelo NormalizeApiResponse, então as
 * asserções aqui são por status HTTP e por efeito no banco, nunca pelo shape
 * do corpo.
 */
class BrevoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste';

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevo.webhook_token' => self::TOKEN]);
    }

    /**
     * Atalho: POST no webhook com o token dado (default: o válido).
     *
     * @param  array<string, mixed>  $payload  Corpo JSON do evento Brevo.
     */
    private function postEvent(array $payload, ?string $token = self::TOKEN)
    {
        $url = '/api/webhooks/brevo'.($token === null ? '' : '?token='.$token);

        return $this->postJson($url, $payload);
    }

    /** Hard bounce desliga os e-mails de retenção do endereço. */
    public function test_hard_bounce_disables_retention_emails(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com']);

        $response->assertOk();
        $this->assertFalse($user->fresh()->weekly_digest_enabled);
    }

    /** Spam, blocked e invalid_email têm o mesmo efeito do hard bounce. */
    public function test_other_permanent_failures_also_disable(): void
    {
        foreach (['spam', 'blocked', 'invalid_email'] as $event) {
            $user = User::factory()->create();

            $this->postEvent(['event' => $event, 'email' => $user->email])->assertOk();

            $this->assertFalse(
                $user->fresh()->weekly_digest_enabled,
                "O evento {$event} deveria ter desligado os e-mails de retenção.",
            );
        }
    }

    /** Evento de sucesso (delivered) é no-op: 200 e flag intacta. */
    public function test_delivered_event_is_a_noop(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postEvent(['event' => 'delivered', 'email' => 'ana@example.com']);

        $response->assertOk();
        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** Token errado: 403 e nada muda no banco. */
    public function test_wrong_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com'], 'token-errado');

        $response->assertForbidden();
        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** Token ausente: 403 e nada muda no banco. */
    public function test_missing_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com'], null);

        $response->assertForbidden();
        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** Sem token configurado, o endpoint fica fechado — nem o token vazio passa. */
    public function test_unconfigured_token_closes_the_endpoint(): void
    {
        config(['services.brevo.webhook_token' => null]);
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com'], '')->assertForbidden();
        $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com'], null)->assertForbidden();

        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** E-mail desconhecido: 200 mesmo assim (o Brevo não deve re-tentar). */
    public function test_unknown_email_still_returns_ok(): void
    {
        $response = $this->postEvent(['event' => 'hard_bounce', 'email' => 'ninguem@example.com']);

        $response->assertOk();
    }

    /** Payload sem e-mail não quebra o endpoint. */
    public function test_payload_without_email_still_returns_ok(): void
    {
        $this->postEvent(['event' => 'hard_bounce'])->assertOk();
    }

    /** A rota é pública: não exige JWT nem e-mail verificado. */
    public function test_route_is_public(): void
    {
        $this->postEvent(['event' => 'delivered', 'email' => 'ana@example.com'])->assertOk();
    }

    /** Evento de abertura vira linha em email_events com o dono resolvido pelo endereço. */
    public function test_records_opened_event_with_resolved_user(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->postEvent([
            'event' => 'unique_opened',
            'email' => 'ana@example.com',
            'tags' => ['weekly_digest'],
            'message-id' => '<abc@smtp-relay.mailin.fr>',
            'date' => '2026-08-16 09:12:33',
        ])->assertOk();

        $this->assertDatabaseHas('email_events', [
            'user_id' => $user->id,
            'email' => 'ana@example.com',
            'event' => 'unique_opened',
            'tag' => 'weekly_digest',
            'message_id' => '<abc@smtp-relay.mailin.fr>',
        ]);
    }

    /** Clique guarda a URL; endereço sem conta grava com user_id nulo. */
    public function test_records_click_event_with_url_and_null_user(): void
    {
        $this->postEvent([
            'event' => 'click',
            'email' => 'sem-conta@example.com',
            'tag' => 'milestone',
            'link' => 'https://linkcharts.com.br/links/analytics/42?utm_source=milestone-email',
        ])->assertOk();

        $this->assertDatabaseHas('email_events', [
            'user_id' => null,
            'event' => 'click',
            'tag' => 'milestone',
            'url' => 'https://linkcharts.com.br/links/analytics/42?utm_source=milestone-email',
        ]);
    }

    /** Falha permanente é gravada E continua desligando os e-mails de retenção. */
    public function test_hard_bounce_is_recorded_and_still_opts_out(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->postEvent(['event' => 'hard_bounce', 'email' => 'ana@example.com'])->assertOk();

        $this->assertFalse($user->fresh()->weekly_digest_enabled);
        $this->assertDatabaseHas('email_events', ['event' => 'hard_bounce', 'user_id' => $user->id]);
    }

    /** Evento desconhecido: 200 sem linha (o contrato de sempre-200 não muda). */
    public function test_unknown_event_is_not_recorded(): void
    {
        $this->postEvent(['event' => 'proxy_open_fancy', 'email' => 'ana@example.com'])->assertOk();

        $this->assertDatabaseCount('email_events', 0);
    }

    /** Data ilegível não derruba o webhook — occurred_at cai para o recebimento. */
    public function test_unparseable_date_falls_back_to_now(): void
    {
        $this->postEvent([
            'event' => 'delivered',
            'email' => 'ana@example.com',
            'date' => 'not-a-date',
        ])->assertOk();

        $event = EmailEvent::sole();
        $this->assertTrue($event->occurred_at->diffInSeconds(now()) < 5);
    }
}
