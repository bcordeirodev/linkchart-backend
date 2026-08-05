<?php

namespace App\Http\Controllers\Email;

use App\Logging\AppLogger;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os eventos de entrega do Brevo e desliga os e-mails de retenção de
 * quem tem falha permanente (bounce duro, denúncia de spam, bloqueio, endereço
 * inválido).
 *
 * Por que isso importa: insistir num endereço que já ricocheteou derruba a
 * reputação do domínio remetente e leva TODO o transacional (verificação,
 * reset de senha) para a caixa de spam. O opt-out automático é a defesa.
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
     * Valida o token, aplica o opt-out quando o evento é de falha permanente e
     * responde 200.
     *
     * Side effects: loga `email.bounce_optout` (warning, canal `app`) por
     * usuário desinscrito.
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

        return response()->json(['message' => 'ok']);
    }
}
