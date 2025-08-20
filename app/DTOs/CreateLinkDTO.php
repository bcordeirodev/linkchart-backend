<?php

namespace App\DTOs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DTO para criação de links
 *
 * Segue o princípio Single Responsibility (SRP) - responsável apenas
 * por transportar dados de criação de links.
 */
class CreateLinkDTO
{
    public readonly string $original_url;
    public readonly int $user_id;
    public readonly ?string $expires_at;
    public readonly bool $is_active;
    public readonly ?string $starts_in;
    public readonly ?string $custom_slug;
    public readonly ?int $click_limit;

    public function __construct(
        string $original_url,
        int $user_id,
        ?string $expires_at = null,
        bool $is_active = true,
        ?string $starts_in = null,
        ?string $custom_slug = null,
        ?int $click_limit = null
    ) {
        $this->original_url = $original_url;
        $this->user_id = $user_id;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->starts_in = $starts_in;
        $this->custom_slug = $custom_slug;
        $this->click_limit = $click_limit;
    }

    /**
     * Cria uma instância do DTO a partir da Request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            original_url: $request->input('original_url'),
            user_id: Auth::id(),
            expires_at: $request->input('expires_at'),
            is_active: $request->boolean('is_active', true),
            starts_in: $request->input('starts_in'),
            custom_slug: $request->input('custom_slug'),
            click_limit: $request->input('click_limit') ? (int) $request->input('click_limit') : null
        );
    }

    /**
     * Converte o DTO para um array para criação no banco.
     */
    public function toArray(): array
    {
        return array_filter([
            'original_url' => $this->original_url,
            'user_id' => $this->user_id,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'starts_in' => $this->starts_in,
            'slug' => $this->custom_slug, // Será gerado se não fornecido
            'click_limit' => $this->click_limit,
        ], fn($value) => $value !== null);
    }

    /**
     * Valida se a URL é válida.
     */
    public function isValidUrl(): bool
    {
        return filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }
}
