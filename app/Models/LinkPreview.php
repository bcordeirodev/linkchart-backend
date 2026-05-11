<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Open Graph / favicon preview metadata for a shortened link.
 *
 * Acts as a 1:1 extension table on `links`. Primary key is `link_id` (no
 * auto-increment); no timestamps. Populated/refreshed by FetchLinkPreviewJob.
 *
 * Non-standard primary key behavior:
 *   $primaryKey  = 'link_id'  (not 'id')
 *   $incrementing = false      (link_id is assigned, not generated)
 *   $timestamps   = false      (table has no created_at/updated_at columns)
 *
 * Fillable: link_id, favicon_url, og_title, og_image_url, fetched_at.
 *
 * Casts: fetched_at → datetime.
 *
 * @property int $link_id FK/PK to links.id (cascade delete).
 * @property string|null $favicon_url URL of the destination page's favicon (up to 500 chars).
 * @property string|null $og_title Open Graph title tag extracted from the destination page (up to 500 chars).
 * @property string|null $og_image_url Open Graph image URL (up to 500 chars).
 * @property \Illuminate\Support\Carbon $fetched_at Timestamp of the most recent successful fetch.
 * @property-read \App\Models\Link $link  The parent link (belongsTo Link via link_id).
 */
class LinkPreview extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'link_id';

    public $incrementing = false;

    protected $fillable = [
        'link_id',
        'favicon_url',
        'og_title',
        'og_image_url',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    /**
     * The shortened link this preview belongs to (belongsTo Link).
     */
    public function link()
    {
        return $this->belongsTo(Link::class);
    }
}
