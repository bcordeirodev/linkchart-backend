<?php

namespace App\Http\Controllers\Email;

use App\Logging\AppLogger;
use App\Models\EmailEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Recebe os eventos de entrega do Brevo, desliga os e-mails de retenção de
 * quem tem falha permanente (bounce duro, denúncia de spam, bloqueio, endereço
 * inválido) e grava todo evento reconhecido em `email_events`.
 *
 * Por que o opt-out importa: insistir num endereço que já ricocheteou derruba a
 * reputação do domínio remetente e leva TODO o transacional (verificação,
 * reset de senha) para a caixa de spam. O opt-out automático é a defesa.
 *
 * Por que a gravação importa: `email_events` é a única fonte do funil
 * enviado→entregue→aberto→clicado por campanha. A campanha vem da `tag` do
 * payload, que é o `$type` semântico mandado pelo `EmailService` no envio.
 *
 * ⚠️ Passo manual no painel do Brevo: os eventos `delivered`, `opened` (unique)
 * e `click` precisam estar habilitados no webhook — sem isso o Brevo só manda
 * as falhas e a tabela nunca recebe o lado positivo do funil.
 *
 * Autenticação: a rota é pública (o Brevo não faz login), então o segredo vive
 * na query string — `?token=` comparado com `services.brevo.webhook_token` via
 * hash_equals (comparação em tempo constante). Config vazia fecha o endpoint.
 *
 * Contrato de status: token válido responde SEMPRE 200, inclusive para e-mail
 * desconhecido ou evento irrelevante — o Brevo re-tenta em qualquer não-2xx, e
 * uma retentativa eterna não conserta um endereço que não existe aqui.
 */
class BrevoWebhookController
{
    /**
     * Eventos que significam "esse endereço não recebe mais e-mail nosso".
     *
     * Deliberadamente sem `soft_bounce`: caixa cheia ou indisponibilidade
     * temporária não justifica desinscrever ninguém.
     *
     * @var list<string>
     */
    private const PERMANENT_FAILURE_EVENTS = [
        'hard_bounce',
        'spam',
        'blocked',
        'invalid_email',
    ];

    /**
     * Eventos que viram linha em email_events. Inclui as falhas permanentes (que
     * TAMBÉM acionam o opt-out) e soft_bounce, que não desinscreve mas compõe o
     * funil de entregabilidade.
     *
     * @var list<string>
     */
    private const RECORDED_EVENTS = [
        'delivered',
        'unique_opened',
        'opened',
        'click',
        'soft_bounce',
        'hard_bounce',
        'spam',
        'blocked',
        'invalid_email',
    ];

    /**
     * Valida o token, aplica o opt-out quando o evento é de falha permanente,
     * grava o evento em `email_events` e responde 200.
     *
     * Side effects: loga `email.bounce_optout` (warning, canal `app`) por
     * usuário desinscrito; insere uma linha em `email_events` por evento
     * reconhecido.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.brevo.webhook_token', '');
        $provided = (string) $request->query('token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $event = (string) $request->input('event', '');
        $email = (string) $request->input('email', '');

        if ($email !== '' && in_array($event, self::PERMANENT_FAILURE_EVENTS, true)) {
            // Por e-mail, não por id: o mesmo endereço pode ter mais de uma
            // conta (legado). Todas param de receber.
            $users = User::where('email', $email)->get();

            foreach ($users as $user) {
                User::whereKey($user->id)->update(['weekly_digest_enabled' => false]);

                AppLogger::event('app', 'warning', 'email.bounce_optout', [
                    'user_id' => $user->id,
                    'event' => $event,
                ]);
            }
        }

        if ($email !== '' && in_array($event, self::RECORDED_EVENTS, true)) {
            // `date` vem "Y-m-d H:i:s" do Brevo; ilegível ou ausente cai para agora —
            // um timestamp aproximado vale mais que um webhook 500 re-tentado.
            $occurredAt = now();
            $rawDate = (string) $request->input('date', '');

            if ($rawDate !== '') {
                try {
                    $occurredAt = Carbon::parse($rawDate);
                } catch (Throwable) {
                    // mantém o fallback
                }
            }

            $tag = $request->input('tags.0', $request->input('tag'));

            EmailEvent::create([
                // Uma linha só, mesmo com contas duplicadas no endereço: o evento é
                // do e-mail; o user_id é o primeiro dono para facilitar o funil.
                'user_id' => User::where('email', $email)->value('id'),
                'email' => $email,
                'event' => $event,
                'tag' => is_string($tag) && $tag !== '' ? $tag : null,
                'url' => $request->input('link'),
                'message_id' => $request->input('message-id'),
                'occurred_at' => $occurredAt,
            ]);
        }

        return response()->json(['message' => 'ok']);
    }
}
