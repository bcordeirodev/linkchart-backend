<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// app/Models/Click.php
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

