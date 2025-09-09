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
    public readonly ?string $title;
    public readonly ?string $description;
    public readonly ?string $expires_at;
    public readonly bool $is_active;
    public readonly ?string $starts_in;
    public readonly ?string $custom_slug;
    public readonly ?int $click_limit;
    public readonly ?string $utm_source;
    public readonly ?string $utm_medium;
    public readonly ?string $utm_campaign;
    public readonly ?string $utm_term;
    public readonly ?string $utm_content;

    public function __construct(
        string $original_url,
        int $user_id,
        ?string $title = null,
        ?string $description = null,
        ?string $expires_at = null,
        bool $is_active = true,
        ?string $starts_in = null,
        ?string $custom_slug = null,
        ?int $click_limit = null,
        ?string $utm_source = null,
        ?string $utm_medium = null,
        ?string $utm_campaign = null,
        ?string $utm_term = null,
        ?string $utm_content = null
    ) {
        $this->original_url = $original_url;
        $this->user_id = $user_id;
        $this->title = $title;
        $this->description = $description;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->starts_in = $starts_in;
        $this->custom_slug = $custom_slug;
        $this->click_limit = $click_limit;
        $this->utm_source = $utm_source;
        $this->utm_medium = $utm_medium;
        $this->utm_campaign = $utm_campaign;
        $this->utm_term = $utm_term;
        $this->utm_content = $utm_content;
    }

    /**
     * Cria uma instância do DTO a partir da Request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            original_url: $request->input('original_url'),
            user_id: Auth::id(),
            title: $request->input('title'),
            description: $request->input('description'),
            expires_at: $request->input('expires_at'),
            is_active: $request->boolean('is_active', true),
            starts_in: $request->input('starts_in'),
            custom_slug: $request->input('custom_slug'),
            click_limit: $request->input('click_limit') ? (int) $request->input('click_limit') : null,
            utm_source: $request->input('utm_source') ?: null,
            utm_medium: $request->input('utm_medium') ?: null,
            utm_campaign: $request->input('utm_campaign') ?: null,
            utm_term: $request->input('utm_term') ?: null,
            utm_content: $request->input('utm_content') ?: null
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
            'title' => $this->title,
            'description' => $this->description,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'starts_in' => $this->starts_in,
            'slug' => $this->custom_slug, // Será gerado se não fornecido
            'click_limit' => $this->click_limit,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_term' => $this->utm_term,
            'utm_content' => $this->utm_content,
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
