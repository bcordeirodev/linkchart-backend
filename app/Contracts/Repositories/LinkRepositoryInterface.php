<?php

namespace App\Contracts\Repositories;

use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;

/**
 * Interface para o repositório de Links
 *
 * Define o contrato que deve ser implementado por qualquer repositório de links,
 * seguindo o princípio de Inversão de Dependência (DIP) do SOLID.
 */
interface LinkRepositoryInterface
{
    /**
     * Retorna todos os links do usuário autenticado.
     *
     * @return Collection<Link>
     */
    public function getAllByUser(): Collection;

    /**
     * Retorna um link específico por ID e usuário.
     */
    public function findByIdAndUser(string $id, int $userId): ?Link;

    /**
     * Retorna um link pelo slug.
     */
    public function findBySlug(string $slug): ?Link;

    /**
     * Cria um novo link.
     */
    public function create(array $data): Link;

    /**
     * Atualiza um link existente.
     */
    public function update(string $id, array $data, int $userId): ?Link;

    /**
     * Remove um link.
     */
    public function delete(string $id, int $userId): bool;

    /**
     * Verifica se um slug já existe.
     */
    public function slugExists(string $slug): bool;
}
