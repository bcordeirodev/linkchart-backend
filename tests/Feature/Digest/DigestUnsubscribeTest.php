<?php

namespace Tests\Feature\Digest;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Rota pública de opt-out (GET assinado, sem auth): a assinatura é a
 * autenticação — válida desliga a flag, inválida/ausente é 403, e o link é
 * idempotente (revisitar mantém o opt-out).
 *
 * `weekly_digest_enabled` governa TODOS os e-mails de retenção (resumo
 * semanal, marcos, winback, dicas) — daí o copy da página falar em e-mails de
 * novidades, não só no digest.
 */
class DigestUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    /** Link assinado desliga weekly_digest_enabled e confirma na página. */
    public function test_signed_link_disables_digest(): void
    {
        $user = User::factory()->create();
        // fresh(): o default(true) é do banco; a instância da factory não o hidrata.
        $this->assertTrue($user->fresh()->weekly_digest_enabled);

        $response = $this->get(URL::signedRoute('digest.unsubscribe', ['user' => $user->id]));

        $response->assertOk();
        $response->assertSee('E-mails de novidades desativados');
        $response->assertSee('marcos e dicas dos seus links');
        $this->assertFalse($user->fresh()->weekly_digest_enabled);
    }

    /** Sem assinatura válida, nada muda — 403 do middleware `signed`. */
    public function test_unsigned_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->get("/email/digest/unsubscribe/{$user->id}");

        $response->assertForbidden();
        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** Assinatura adulterada (id trocado) também é rejeitada. */
    public function test_tampered_signature_is_rejected(): void
    {
        $victim = User::factory()->create();
        $other = User::factory()->create();

        $signedForOther = URL::signedRoute('digest.unsubscribe', ['user' => $other->id]);
        $tampered = str_replace("/unsubscribe/{$other->id}", "/unsubscribe/{$victim->id}", $signedForOther);

        $response = $this->get($tampered);

        $response->assertForbidden();
        $this->assertTrue($victim->fresh()->weekly_digest_enabled);
    }

    /** Revisitar o mesmo link é idempotente: continua 200 e continua desinscrito. */
    public function test_revisiting_link_is_idempotent(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('digest.unsubscribe', ['user' => $user->id]);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $this->assertFalse($user->fresh()->weekly_digest_enabled);
    }
}
