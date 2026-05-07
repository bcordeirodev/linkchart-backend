<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a single recorded click on a shortened link.
 *
 * Stores comprehensive click data including geographic location, device and browser
 * details, temporal context, behavioral signals, performance metrics, UTM parameters,
 * Phase 1 navigation context enrichment (Sec-Fetch headers, Client Hints,
 * Save-Data indicator, HTTP protocol, language preferences), and Phase 2
 * contextual intelligence (holidays, season, viral rank, connection type,
 * rendering engine).
 *
 * @property int $id
 * @property int $link_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $referer
 * @property string|null $country
 * @property string|null $city
 * @property string|null $device
 * @property string|null $browser
 * @property string|null $os
 * @property bool|null $is_mobile
 * @property bool|null $is_holiday         Phase 2: whether the click fell on a national holiday
 * @property string|null $holiday_name     Phase 2: name of the holiday if applicable
 * @property string|null $season           Phase 2: calendar season (spring|summer|fall|winter)
 * @property string|null $viral_rank       Phase 2: click velocity classification (cold|warming|trending|viral)
 * @property int|null $seconds_since_last_click  Phase 2: seconds elapsed since the previous click on this link
 * @property string|null $connection_type  Phase 2: ISP category (datacenter|mobile|education|residential|unknown)
 * @property string|null $rendering_engine Phase 2: browser rendering engine (blink|gecko|webkit|trident|unknown)
 * @property int|null $quality_score      Phase 3: composite click quality score (0–100)
 * @property string|null $quality_tier    Phase 3: quality classification (organic|suspicious|likely_fraud)
 * @property int $fingerprint_score       Phase 3: count of detected header inconsistencies (0–3)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method \Illuminate\Database\Eloquent\Relations\BelongsTo link()
 * @method \Illuminate\Database\Eloquent\Relations\HasOne utm()
 */
class Click extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'ip',
        'user_agent',
        'referer',
        'country',
        'city',
        'device',
        // Campos geográficos detalhados
        'iso_code',
        'state',
        'state_name',
        'postal_code',
        'latitude',
        'longitude',
        'timezone',
        'continent',
        'currency',
        // Campos de dispositivo detalhados
        'browser',
        'browser_version',
        'os',
        'os_version',
        'is_mobile',
        'is_tablet',
        'is_desktop',
        'is_bot',
        // Campos temporais enriquecidos
        'hour_of_day',
        'day_of_week',
        'day_of_month',
        'month',
        'year',
        'local_time',
        'is_weekend',
        'is_business_hours',
        // Campos de comportamento
        'is_return_visitor',
        'session_clicks',
        'click_source',
        // Phase 1 — navigation context
        'navigation_context',
        'fetch_dest',
        'ch_platform',
        'ch_is_mobile',
        'is_data_saver',
        'http_protocol',
        'primary_language',
        'language_region',
        // Phase 2 — contextual intelligence
        'is_holiday',
        'holiday_name',
        'season',
        'viral_rank',
        'seconds_since_last_click',
        'connection_type',
        'rendering_engine',
        // Phase 3 — quality scoring
        'quality_score',
        'quality_tier',
        'fingerprint_score',
        // Campos de performance
        'response_time',
        'accept_language',
    ];

    public function link()
    {
        return $this->belongsTo(Link::class);
    }

    public function utm()
    {
        return $this->hasOne(LinkUtm::class);
    }
}
