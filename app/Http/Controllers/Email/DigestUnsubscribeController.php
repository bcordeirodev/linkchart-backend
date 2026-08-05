<?php

namespace App\Http\Controllers\Email;

use App\Logging\AppLogger;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * Opt-out do digest semanal via link assinado no rodapé do e-mail (LGPD).
 *
 * A rota (digest.unsubscribe, GET web) usa o middleware `signed` — a
 * assinatura na URL é a autenticação: um clique na caixa de entrada tem que
 * funcionar sem login. Idempotente: revisitar o link mantém o opt-out e
 * mostra a mesma confirmação. A reativação fica para o toggle do perfil no
 * frontend (follow-up).
 */
class DigestUnsubscribeController
{
    /**
     * Desliga weekly_digest_enabled e renderiza a página de confirmação.
     *
     * Side effects: loga `digest.unsubscribed` no canal `app`.
     */
    public function __invoke(User $user): Response
    {
        User::whereKey($user->id)->update(['weekly_digest_enabled' => false]);

        AppLogger::event('app', 'info', 'digest.unsubscribed', ['user_id' => $user->id]);

        return response()->view('digest.unsubscribed');
    }
}
