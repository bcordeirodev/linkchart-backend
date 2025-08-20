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
     *
     * @param string $id
     * @param int $userId
     * @return Link|null
     */
    public function findByIdAndUser(string $id, int $userId): ?Link;

    /**
     * Retorna um link pelo slug.
     *
     * @param string $slug
     * @return Link|null
     */
    public function findBySlug(string $slug): ?Link;

    /**
     * Cria um novo link.
     *
     * @param array $data
     * @return Link
     */
    public function create(array $data): Link;

    /**
     * Atualiza um link existente.
     *
     * @param string $id
     * @param array $data
     * @param int $userId
     * @return Link|null
     */
    public function update(string $id, array $data, int $userId): ?Link;

    /**
     * Remove um link.
     *
     * @param string $id
     * @param int $userId
     * @return bool
     */
    public function delete(string $id, int $userId): bool;

    /**
     * Incrementa o contador de cliques de um link.
     *
     * @param string $slug
     * @return bool
     */
    public function incrementClicks(string $slug): bool;

    /**
     * Verifica se um slug já existe.
     *
     * @param string $slug
     * @return bool
     */
    public function slugExists(string $slug): bool;
}
