<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de e-mail reportado pelo webhook Brevo (delivered, unique_opened,
 * opened, click, soft/hard_bounce, spam, blocked, invalid_email).
 *
 * Gravado por {@see \App\Http\Controllers\Email\BrevoWebhookController} e
 * podado após 180 dias por {@see \App\Console\Commands\PruneEmailEvents}.
 * `tag` é o tipo semântico do envio (weekly_digest, milestone, ...) — ver
 * EmailService, que manda o $type como tag Brevo.
 *
 * @property int $id
 * @property int|null $user_id Dono do endereço no momento do evento; nulo se não houver conta.
 * @property string $email Endereço como veio do Brevo.
 * @property string $event Nome do evento Brevo.
 * @property string|null $tag Campanha (primeira tag do payload).
 * @property string|null $url URL clicada — só em eventos `click`.
 * @property string|null $message_id `message-id` do Brevo, para correlação.
 * @property \Illuminate\Support\Carbon $occurred_at Quando o evento aconteceu segundo o Brevo.
 */
class EmailEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'email',
        'event',
        'tag',
        'url',
        'message_id',
        'occurred_at',
    ];

    /**
     * Casts de atributos do model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
