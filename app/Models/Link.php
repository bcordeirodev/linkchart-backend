<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Link extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'id',
        'slug',
        'original_url',
        'title',
        'description',
        'user_id',
        'expires_at',
        'starts_in',
        'is_active',
        'clicks',
        'click_limit',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'starts_in' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function clicks()
    {
        return $this->hasMany(Click::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    public function hasReachedClickLimit(): bool
    {
        return $this->click_limit !== null && $this->clicks >= $this->click_limit;
    }

    public function getRemainingClicks(): ?int
    {
        if ($this->click_limit === null) {
            return null; // Ilimitado
        }

        return max(0, $this->click_limit - $this->clicks);
    }

    public function getShortedUrl(): string
    {
        // URL encurtada deve apontar para o frontend (redirect page)
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        return "{$frontendUrl}/r/{$this->slug}";
    }
}
