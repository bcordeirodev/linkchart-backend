<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user-defined label that can be attached to any number of the user's links.
 *
 * Tags are strictly a per-user resource: `name` is unique within
 * `(user_id, name)` (enforced at the DB level by the `tags` table migration),
 * and every read/write path in {@see \App\Repositories\TagRepository} and
 * {@see \App\Services\Links\TagService} scopes by the authenticated user's id.
 * `color` is a required 7-character hex string (e.g. "#3B82F6") chosen by the
 * frontend from a fixed palette — the backend only validates the hex format
 * (see {@see \App\Http\Requests\CreateTagRequest}), not membership in that
 * palette.
 *
 * Fillable: user_id, name, color.
 *
 * @property int $id
 * @property int $user_id Owning user.
 * @property string $name Tag label, max 50 chars, unique per user.
 * @property string $color 7-char hex colour code, e.g. "#3B82F6".
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Link> $links
 */
class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory;

    /**
     * Maximum number of tags a single user may create.
     *
     * Enforced in {@see \App\Services\Links\TagService::createTag()}, which
     * returns a 422-mapped InvalidArgumentException once the cap is reached.
     */
    public const MAX_TAGS_PER_USER = 20;

    protected $fillable = [
        'user_id',
        'name',
        'color',
    ];

    /**
     * The user who owns this tag (belongsTo User).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Links this tag is currently attached to (belongsToMany Link via link_tag).
     */
    public function links(): BelongsToMany
    {
        return $this->belongsToMany(Link::class);
    }
}
