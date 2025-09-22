<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// app/Models/LinkUtm.php
class LinkUtm extends Model
{
    use HasFactory;

    protected $fillable = [
        'click_id',
        'utm_source',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_medium',
    ];

    public function click()
    {
        return $this->belongsTo(Click::class);
    }
}

