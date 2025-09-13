<?php

namespace App\DTOs;

use App\Http\Requests\CreatePublicLinkRequest;

/**
 * DTO para criação de links públicos
 *
 * FUNCIONALIDADE:
 * - Encapsula dados de criação de links sem usuário
 * - Validações básicas de negócio
 * - Conversão de request para dados estruturados
 */
class CreatePublicLinkDTO
{
    public function __construct(
        public readonly string $original_url,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
        public readonly bool $is_active = true,
        public readonly ?int $user_id = null // Sempre null para links públicos
    ) {}

    /**
     * Cria DTO a partir de uma request validada.
     */
    public static function fromRequest(CreatePublicLinkRequest $request): self
    {
        return new self(
            original_url: $request->validated('original_url'),
            title: $request->validated('title'),
            slug: $request->validated('custom_slug'),
            is_active: true, // Links públicos sempre ativos inicialmente
            user_id: null // Links públicos não têm usuário
        );
    }

    /**
     * Converte para array para persistência.
     */
    public function toArray(): array
    {
        return [
            'original_url' => $this->original_url,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id, // null
            'clicks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Valida se a URL é válida.
     */
    public function isValidUrl(): bool
    {
        return filter_var($this->original_url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Verifica se tem dados para criar o link.
     */
    public function hasValidData(): bool
    {
        return !empty($this->original_url) && $this->isValidUrl();
    }
}
