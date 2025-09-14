<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para links públicos
 *
 * FUNCIONALIDADE:
 * - Expõe apenas dados seguros de links públicos
 * - Omite informações sensíveis
 * - Formata dados para resposta da API
 */
class PublicLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'original_url' => $this->original_url,
            'short_url' => $this->getShortedUrl(),
            'clicks' => $this->clicks,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,

            // Metadados úteis
            'is_public' => $this->user_id === null,
            'has_analytics' => $this->clicks > 0,
            'domain' => parse_url($this->original_url, PHP_URL_HOST),
        ];
    }
}
