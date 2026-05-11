<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * UTM tracking parameters extracted from a click's query string or Referer header.
 *
 * One record per click, written by LinkTrackingService as a child of Click.
 * The `link_utms` table complements the denormalised UTM fields on `links`
 * (which hold defaults to append on redirect) — these columns hold what was
 * actually present in the incoming request.
 *
 * Fillable: click_id, utm_source, utm_content, utm_campaign, utm_term, utm_medium.
 *
 * No custom casts. Standard timestamps (created_at / updated_at).
 *
 * @property int $id
 * @property int $click_id FK to clicks.id (cascade delete).
 * @property string|null $utm_source Campaign source (e.g. google, newsletter).
 * @property string|null $utm_medium Campaign medium (e.g. cpc, email).
 * @property string|null $utm_campaign Campaign name.
 * @property string|null $utm_term Paid search keywords.
 * @property string|null $utm_content Ad variant identifier (A/B testing discriminator).
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Click $click  The click that generated these UTM parameters (belongsTo Click).
 */
// app/Models/LinkUtm.php
class LinkUtm extends Model
{
    use HasFactory;

    protected $fillable = [
        'click_id',
        'utm_source',
        'utm_content',
        'utm_campaign',
        'utm_term',
        'utm_medium',
    ];

    /**
     * The click event that generated these UTM parameters (belongsTo Click).
     */
    public function click()
    {
        return $this->belongsTo(Click::class);
    }
}
