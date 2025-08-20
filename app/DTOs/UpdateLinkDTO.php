<?php

namespace App\DTOs;

use Illuminate\Http\Request;

/**
 * DTO para atualização de links
 *
 * Segue o princípio Single Responsibility (SRP) - responsável apenas
 * por transportar dados de atualização de links.
 */
class UpdateLinkDTO
{
    public readonly ?string $original_url;
    public readonly ?string $title;
    public readonly ?string $slug;
    public readonly ?string $description;
    public readonly ?string $expires_at;
    public readonly ?bool $is_active;
    public readonly ?string $starts_in;
    public readonly ?int $click_limit;
    public readonly ?string $utm_source;
    public readonly ?string $utm_medium;
    public readonly ?string $utm_campaign;
    public readonly ?string $utm_term;
    public readonly ?string $utm_content;

    public function __construct(
        ?string $original_url = null,
        ?string $title = null,
        ?string $slug = null,
        ?string $description = null,
        ?string $expires_at = null,
        ?bool $is_active = null,
        ?string $starts_in = null,
        ?int $click_limit = null,
        ?string $utm_source = null,
        ?string $utm_medium = null,
        ?string $utm_campaign = null,
        ?string $utm_term = null,
        ?string $utm_content = null
    ) {
        $this->original_url = $original_url;
        $this->title = $title;
        $this->slug = $slug;
        $this->description = $description;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->starts_in = $starts_in;
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
            title: $request->input('title'),
            slug: $request->input('slug'),
            description: $request->input('description'),
            expires_at: $request->input('expires_at'),
            is_active: $request->has('is_active') ? $request->boolean('is_active') : null,
            starts_in: $request->input('starts_in'),
            click_limit: $request->has('click_limit') ? ($request->input('click_limit') ? (int) $request->input('click_limit') : null) : null,
            utm_source: $request->input('utm_source'),
            utm_medium: $request->input('utm_medium'),
            utm_campaign: $request->input('utm_campaign'),
            utm_term: $request->input('utm_term'),
            utm_content: $request->input('utm_content')
        );
    }

    /**
     * Converte o DTO para um array para atualização no banco.
     * Remove valores null para não sobrescrever campos desnecessariamente.
     */
    public function toArray(): array
    {
        return array_filter([
            'original_url' => $this->original_url,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'starts_in' => $this->starts_in,
            'click_limit' => $this->click_limit,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_term' => $this->utm_term,
            'utm_content' => $this->utm_content,
        ], fn($value) => $value !== null);
    }

    /**
     * Verifica se há dados para atualizar.
     */
    public function hasDataToUpdate(): bool
    {
        return !empty($this->toArray());
    }

    /**
     * Valida se a URL é válida (quando fornecida).
     */
    public function isValidUrl(): bool
    {
        return $this->original_url === null ||
               filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }
}
