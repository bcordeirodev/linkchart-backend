<?php

namespace App\Contracts\Services;

use App\DTOs\CreateLinkDTO;
use App\DTOs\CreatePublicLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;

/**
 * Interface para o serviço de Links
 *
 * Define o contrato para as regras de negócio relacionadas aos links,
 * seguindo o princípio de Inversão de Dependência (DIP) do SOLID.
 */
interface LinkServiceInterface
{
    /**
     * Retorna todos os links do usuário autenticado.
     *
     * @return Collection<Link>
     */
    public function getAllUserLinks(): Collection;

    /**
     * Retorna um link específico do usuário.
     */
    public function getUserLink(string $id): ?Link;

    /**
     * Cria um novo link encurtado.
     */
    public function createLink(CreateLinkDTO $linkDTO): Link;

    /**
     * Atualiza um link existente.
     */
    public function updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link;

    /**
     * Remove um link.
     */
    public function deleteLink(string $id): bool;

    /**
     * Cria um novo link público encurtado (sem usuário).
     */
    public function createPublicLink(CreatePublicLinkDTO $linkDTO): Link;

    /**
     * Gera um slug único para o link.
     */
    public function generateUniqueSlug(int $length = 6): string;
}
