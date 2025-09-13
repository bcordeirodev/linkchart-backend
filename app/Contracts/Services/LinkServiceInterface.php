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
     *
     * @param string $id
     * @return Link|null
     */
    public function getUserLink(string $id): ?Link;

    /**
     * Cria um novo link encurtado.
     *
     * @param CreateLinkDTO $linkDTO
     * @return Link
     */
    public function createLink(CreateLinkDTO $linkDTO): Link;

    /**
     * Atualiza um link existente.
     *
     * @param string $id
     * @param UpdateLinkDTO $linkDTO
     * @return Link|null
     */
    public function updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link;

    /**
     * Remove um link.
     *
     * @param string $id
     * @return bool
     */
    public function deleteLink(string $id): bool;

    /**
     * Processa o redirecionamento de um link encurtado.
     *
     * @param string $slug
     * @return string|null URL original ou null se não encontrado
     */
    public function processRedirect(string $slug): ?string;

    /**
     * Cria um novo link público encurtado (sem usuário).
     *
     * @param CreatePublicLinkDTO $linkDTO
     * @return Link
     */
    public function createPublicLink(CreatePublicLinkDTO $linkDTO): Link;

    /**
     * Gera um slug único para o link.
     *
     * @param int $length
     * @return string
     */
    public function generateUniqueSlug(int $length = 6): string;
}
