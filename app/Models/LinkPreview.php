<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function link()
    {
        return $this->belongsTo(Link::class);
    }
}
