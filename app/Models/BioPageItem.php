<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single button on a {@see BioPage}, pointing at one of the owner's
 * existing shortened links. Click tracking/analytics is inherited for free
 * from the underlying Link — this model only holds display metadata
 * (label, order, active flag).
 *
 * @property int $id
 * @property int $bio_page_id Owning bio page (cascadeOnDelete).
 * @property int $link_id The shortened link this button points to (cascadeOnDelete).
 * @property string $label Button text shown to visitors.
 * @property int $position 0-based display order within the page.
 * @property bool $is_active Soft on/off switch; false hides the button from the public page.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\BioPage $bioPage Owning bio page.
 * @property-read \App\Models\Link $link The underlying shortened link.
 */
class BioPageItem extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bio_page_id',
        'link_id',
        'label',
        'position',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * The bio page this item belongs to (belongsTo BioPage).
     */
    public function bioPage(): BelongsTo
    {
        return $this->belongsTo(BioPage::class);
    }

    /**
     * The shortened link this button points to (belongsTo Link).
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
