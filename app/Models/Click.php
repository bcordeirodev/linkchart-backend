<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single recorded click event on a shortened link.
 *
 * Written asynchronously by ProcessLinkClickJob via LinkTrackingService. The
 * record captures the full enrichment pipeline in phases:
 *   - Core:    IP, UA, referer, basic country/city/device.
 *   - Geo:     iso_code, state, postal_code, coordinates, timezone, continent, currency.
 *   - Device:  browser, browser_version, os, os_version, is_mobile/tablet/desktop/bot.
 *   - Temporal: hour_of_day … year, local_time, is_weekend, is_business_hours.
 *   - Behavior: is_return_visitor, session_clicks, click_source.
 *   - Phase 1: navigation_context, fetch_dest, ch_platform, ch_is_mobile,
 *              is_data_saver, http_protocol, primary_language, language_region.
 *   - Phase 2: is_holiday, holiday_name, season, viral_rank,
 *              seconds_since_last_click, connection_type, rendering_engine.
 *   - Phase 3: quality_score, quality_tier, fingerprint_score.
 *   - Perf:    response_time, accept_language.
 *
 * No explicit casts defined — booleans are stored as tinyint and read back
 * as int (0/1) unless cast explicitly in future migrations. ip is stored as
 * varchar (ipAddress column type in MySQL/Postgres maps to varchar(45)).
 *
 * Fillable: all columns above (see $fillable array below).
 *
 * @property int $id
 * @property string|null $dedup_key Server-generated idempotency token (UNIQUE); null for legacy/edge events.
 * @property int $link_id FK to links.id (cascade delete).
 * @property string|null $ip Visitor IP address (IPv4 or IPv6, up to 45 chars).
 * @property string|null $user_agent Raw User-Agent string (up to 1 024 chars).
 * @property string|null $referer HTTP Referer header value.
 * @property string|null $country Country name resolved by GeoIP.
 * @property string|null $city City name resolved by GeoIP.
 * @property string|null $device Top-level device category (mobile/desktop/tablet).
 * @property string|null $iso_code ISO 3166-1 alpha-2 country code (2 chars).
 * @property string|null $state State/region code (up to 10 chars).
 * @property string|null $state_name Full state/region name.
 * @property string|null $postal_code Postal/ZIP code (up to 20 chars).
 * @property string|null $latitude Geographic latitude (decimal 10,7).
 * @property string|null $longitude Geographic longitude (decimal 11,7).
 * @property string|null $timezone IANA timezone identifier (e.g. America/Sao_Paulo).
 * @property string|null $continent Continent name (up to 20 chars).
 * @property string|null $currency ISO 4217 currency code (3 chars).
 * @property string|null $browser Browser name (up to 50 chars).
 * @property string|null $browser_version Browser version string (up to 20 chars).
 * @property string|null $os Operating system name (up to 50 chars).
 * @property string|null $os_version Operating system version (up to 20 chars).
 * @property int $is_mobile 1 if mobile device; default 0.
 * @property int $is_tablet 1 if tablet; default 0.
 * @property int $is_desktop 1 if desktop; default 0.
 * @property int $is_bot 1 if crawler/bot detected; default 0.
 * @property int|null $hour_of_day Hour of click in visitor's local time (0–23).
 * @property int|null $day_of_week Day of week (0=Sunday … 6=Saturday).
 * @property int|null $day_of_month Day of month (1–31).
 * @property int|null $month Month (1–12).
 * @property int|null $year 4-digit year.
 * @property string|null $local_time Formatted local time string (up to 20 chars).
 * @property int $is_weekend 1 if click occurred on Saturday or Sunday; default 0.
 * @property int $is_business_hours 1 if within business hours in visitor's timezone; default 0.
 * @property int $is_return_visitor 1 if the same IP has clicked this link before; default 0.
 * @property int $session_clicks Number of clicks in the current session window; default 1.
 * @property string|null $click_source Categorised traffic source (up to 50 chars).
 * @property string|null $social_platform Specific social platform slug (instagram/tiktok/etc.) or null.
 * @property string|null $navigation_context Phase 1: Sec-Fetch-Site / Sec-Fetch-Mode derived context (up to 30 chars).
 * @property string|null $fetch_dest Phase 1: Sec-Fetch-Dest header value (up to 30 chars).
 * @property string|null $ch_platform Phase 1: Sec-CH-UA-Platform Client Hint (up to 30 chars).
 * @property int|null $ch_is_mobile Phase 1: Sec-CH-UA-Mobile Client Hint (0/1/null).
 * @property int $is_data_saver Phase 1: Save-Data header present; default 0.
 * @property string|null $http_protocol Phase 1: HTTP protocol version (e.g. HTTP/2, up to 10 chars).
 * @property string|null $primary_language Phase 1: Primary language from Accept-Language header (up to 10 chars).
 * @property string|null $language_region Phase 1: Region part of Accept-Language (up to 10 chars).
 * @property int|null $is_holiday Phase 2: 1 if click fell on a national holiday in the visitor's country.
 * @property string|null $holiday_name Phase 2: Name of the holiday when is_holiday = 1 (up to 100 chars).
 * @property string|null $season Phase 2: Calendar season (spring|summer|fall|winter, up to 10 chars).
 * @property string|null $viral_rank Phase 2: Click velocity classification (cold|warming|trending|viral, up to 15 chars).
 * @property int|null $seconds_since_last_click Phase 2: Seconds elapsed since the previous click on the same link.
 * @property string|null $connection_type Phase 2: ISP category (datacenter|mobile|education|residential|unknown, up to 20 chars).
 * @property string|null $rendering_engine Phase 2: Browser rendering engine (blink|gecko|webkit|trident|unknown, up to 20 chars).
 * @property int|null $quality_score Phase 3: Composite click quality score (0–100); stored as unsigned tinyint.
 * @property string|null $quality_tier Phase 3: Quality classification (organic|suspicious|likely_fraud, up to 15 chars).
 * @property int $fingerprint_score Phase 3: Count of detected header inconsistencies (0–3); default 0.
 * @property string|null $response_time Redirect response time in milliseconds (decimal 8,3).
 * @property string|null $accept_language Raw Accept-Language header (up to 100 chars).
 * @property bool $ip_anonymized True once the IP has been masked by clicks:anonymize-ips (LGPD).
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Link             $link   Parent link (belongsTo Link).
 * @property-read \App\Models\LinkUtm|null     $utm    Associated UTM parameters record (hasOne LinkUtm).
 */
class Click extends Model
{
    use HasFactory;

    protected $casts = [
        'ip_anonymized' => 'boolean',
    ];

    protected $fillable = [
        'dedup_key',
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
        'social_platform',
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
        // LGPD retention
        'ip_anonymized',
    ];

    /**
     * The shortened link this click belongs to (belongsTo Link).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Link, $this>
     */
    public function link(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * UTM parameter record associated with this click (hasOne LinkUtm).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\LinkUtm, $this>
     */
    public function utm(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LinkUtm::class);
    }
}
