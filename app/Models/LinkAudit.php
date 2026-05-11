<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit record for a change made to a Link.
 *
 * Written by App\Services\Links\LinkAuditService whenever a link is created,
 * updated, or deleted. Records the before and after state as JSON blobs plus
 * the actor's IP and UA for forensic use. The audit channel logs complement
 * these rows (see AppLogger).
 *
 * Fillable: link_id, user_id, action, old_values, new_values, ip_address, user_agent.
 *
 * Casts: old_values → array, new_values → array.
 *
 * Constants: ACTION_CREATED = 'created', ACTION_UPDATED = 'updated',
 *            ACTION_DELETED = 'deleted'.
 *
 * @property int $id
 * @property int $link_id FK to links.id (cascade delete).
 * @property int $user_id FK to users.id; the user who performed the action.
 * @property string $action Audit action token: 'created' | 'updated' | 'deleted'.
 * @property array|null $old_values JSON snapshot of the link's attributes before the change; null for creates.
 * @property array|null $new_values JSON snapshot of the link's attributes after the change; null for deletes.
 * @property string|null $ip_address IP address of the actor at the time of the action.
 * @property string|null $user_agent User-Agent string of the actor's client.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Link $link   The audited link (belongsTo Link).
 * @property-read \App\Models\User $user   The actor who made the change (belongsTo User).
 */
class LinkAudit extends Model
{
    protected $fillable = [
        'link_id',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * The link that was audited (belongsTo Link).
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * The user who performed the audited action (belongsTo User).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Constantes para ações de auditoria
     */
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';
}
