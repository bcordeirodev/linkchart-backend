<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'slug'            => $this->slug,
            'original_url'    => $this->original_url,
            'title'           => $this->title,
            'description'     => $this->description,
            'expires_at'      => $this->formattedExpiresAt(),
            'starts_in'       => $this->formattedStartsIn(),
            'is_active'       => $this->is_active,
            'created_at'      => $this->formattedCreatedAt(),
            'updated_at'      => $this->updated_at->format('d/m/Y H:i:s'),
            'is_expired'      => $this->isExpired(),
            'is_active_valid' => $this->isActiveAndNotExpired(),
            'shorted_url'     => $this->getShortedUrl(),
            'clicks'          => $this->clicks()->count(),
            'utm_source'      => $this->utm_source,
            'utm_medium'      => $this->utm_medium,
            'utm_campaign'    => $this->utm_campaign,
            'utm_term'        => $this->utm_term,
            'utm_content'     => $this->utm_content,
        ];
    }

    /**
     * Check if the link is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        // Se for string, converte para Carbon
        if (is_string($this->expires_at)) {
            return now()->greaterThan(\Carbon\Carbon::parse($this->expires_at));
        }

        // Se já for Carbon/DateTime
        return now()->greaterThan($this->expires_at);
    }

    /**
     * Get the formatted creation date.
     *
     * @return string
     */
    public function formattedCreatedAt(): string
    {
        return $this->created_at->format('d/m/Y H:i:s');
    }

    /**
     * Get the formatted expiration date.
     *
     * @return string|null
     */
    public function formattedExpiresAt(): ?string
    {
        if (!$this->expires_at) {
            return null;
        }

        // Se for string, converte para Carbon
        if (is_string($this->expires_at)) {
            return \Carbon\Carbon::parse($this->expires_at)->format('d/m/Y H:i:s');
        }

        // Se já for Carbon/DateTime
        return $this->expires_at->format('d/m/Y H:i:s');
    }

    /**
     * Get the formatted starts in date.
     *
     * @return string|null
     */
    public function formattedStartsIn(): ?string
    {
        if (!$this->starts_in) {
            return null;
        }

        // Se for string, converte para Carbon
        if (is_string($this->starts_in)) {
            return \Carbon\Carbon::parse($this->starts_in)->format('d/m/Y H:i:s');
        }

        // Se já for Carbon/DateTime
        return $this->starts_in->format('d/m/Y H:i:s');
    }

    /**
     * Check if the link is active and not expired.
     *
     * @return bool
     */
    public function isActiveAndNotExpired(): bool
    {
        return $this->is_active && !$this->isExpired();
    }
}
