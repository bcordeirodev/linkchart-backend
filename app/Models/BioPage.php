<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's public "link-in-bio" page (MVP: exactly one per user).
 *
 * Exposed publicly (unauthenticated) at GET /api/public/bio/{handle} — only
 * when `is_active` is true — and managed by the authenticated owner via the
 * /api/bio family of endpoints. `handle` is globally unique and always
 * persisted lowercase (case normalization happens in
 * {@see \App\Services\Bio\BioPageService} and
 * {@see \App\Http\Requests\Bio\UpsertBioPageRequest}, not via a DB-level
 * constraint or cast).
 *
 * @property int $id
 * @property int $user_id Owner; unique — one bio page per user (MVP).
 * @property int|null $subdomain_id Optional link to one of the owner's active `user_subdomains` rows;
 *                                  `nullOnDelete()` — released/deleted subdomains silently detach.
 *                                  Data relationship only (Option A): does not make the subdomain
 *                                  root serve the bio page; only feeds the computed `url` field
 *                                  built by {@see \App\Services\Bio\BioPageService}.
 * @property string $handle Public URL slug, lowercase, 3–30 chars, globally unique.
 * @property string $title Display title shown at the top of the page.
 * @property string|null $bio Short free-text description, max 280 chars.
 * @property string $theme Visual theme: 'dark' | 'light'. Default 'dark'.
 * @property string|null $avatar_url Reserved for a future avatar upload feature; unused today.
 * @property bool $is_active Soft on/off switch; false makes the public endpoint 404.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User $user Owning user.
 * @property-read \App\Models\UserSubdomain|null $subdomain Associated subdomain, when set.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BioPageItem> $items Buttons on this page, ordered by position.
 */
class BioPage extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * `subdomain_id` is deliberately excluded — it goes through the
     * ownership/active-status validation in
     * {@see \App\Services\Bio\BioPageService::upsert()} and is set via
     * direct attribute assignment there, never blind mass-assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'handle',
        'title',
        'bio',
        'theme',
        'avatar_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The user who owns this bio page (belongsTo User).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subdomain associated with this bio page, if any (belongsTo UserSubdomain).
     */
    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(UserSubdomain::class);
    }

    /**
     * Buttons on this page, ordered by their display position (hasMany BioPageItem).
     */
    public function items(): HasMany
    {
        return $this->hasMany(BioPageItem::class)->orderBy('position');
    }
}
