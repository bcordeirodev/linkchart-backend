<?php

namespace App\Repositories;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;

/**
 * Implementação do repositório de Links
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas pelo acesso a dados de links
 * - DIP: Implementa a interface LinkRepositoryInterface
 */
class LinkRepository implements LinkRepositoryInterface
{
    /**
     * Retorna todos os links do usuário autenticado.
     */
    public function getAllByUser(): Collection
    {
        return Link::where('user_id', auth()->guard('api')->id())
                  ->orderBy('created_at', 'desc')
                  ->get();
    }

    /**
     * Retorna um link específico por ID e usuário.
     */
    public function findByIdAndUser(string $id, int $userId): ?Link
    {
        return Link::where('id', $id)
                  ->where('user_id', $userId)
                  ->first();
    }

    /**
     * Retorna um link pelo slug.
     */
    public function findBySlug(string $slug): ?Link
    {
        return Link::where('slug', $slug)
                  ->where('is_active', true)
                  ->first();
    }

    /**
     * Cria um novo link.
     */
    public function create(array $data): Link
    {
        return Link::create($data);
    }

    /**
     * Atualiza um link existente.
     */
    public function update(string $id, array $data, int $userId): ?Link
    {
        $link = $this->findByIdAndUser($id, $userId);

        if ($link) {
            $link->update($data);
            return $link->fresh();
        }

        return null;
    }

    /**
     * Remove um link.
     */
    public function delete(string $id, int $userId): bool
    {
        $link = $this->findByIdAndUser($id, $userId);

        if ($link) {
            return $link->delete();
        }

        return false;
    }

    /**
     * Incrementa o contador de cliques de um link.
     */
    public function incrementClicks(string $slug): bool
    {
        return Link::where('slug', $slug)
                  ->where('is_active', true)
                  ->increment('clicks') > 0;
    }

    /**
     * Verifica se um slug já existe.
     */
    public function slugExists(string $slug): bool
    {
        return Link::where('slug', $slug)->exists();
    }
}
