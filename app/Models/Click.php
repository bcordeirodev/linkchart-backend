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
        // Novos campos geográficos detalhados
        'iso_code',
        'state',
        'state_name',
        'postal_code',
        'latitude',
        'longitude',
        'timezone',
        'continent',
        'currency',
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

