<?php

namespace App\Services\Links;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Models\Link;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Implementação do serviço de Links
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas pelas regras de negócio de links
 * - OCP: Extensível através de interfaces
 * - DIP: Depende de abstrações (interfaces)
 */
class LinkService implements LinkServiceInterface
{
    protected LinkRepositoryInterface $linkRepository;

    public function __construct(LinkRepositoryInterface $linkRepository)
    {
        $this->linkRepository = $linkRepository;
    }

    /**
     * Retorna todos os links do usuário autenticado.
     */
    public function getAllUserLinks(): Collection
    {
        return $this->linkRepository->getAllByUser();
    }

    /**
     * Retorna um link específico do usuário.
     */
    public function getUserLink(string $id): ?Link
    {
        return $this->linkRepository->findByIdAndUser($id, auth()->guard('api')->id());
    }

    /**
     * Cria um novo link encurtado.
     */
    public function createLink(CreateLinkDTO $linkDTO): Link
    {
        // Validação de negócio
        if (!$linkDTO->isValidUrl()) {
            throw new \InvalidArgumentException('URL inválida fornecida.');
        }

        $data = $linkDTO->toArray();

        // Gera slug único se não fornecido
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug();
        } elseif ($this->linkRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Slug personalizado já está em uso.');
        }

        return $this->linkRepository->create($data);
    }

    /**
     * Atualiza um link existente.
     */
    public function updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link
    {
        // Validação de negócio
        if (!$linkDTO->hasDataToUpdate()) {
            throw new \InvalidArgumentException('Nenhum dado fornecido para atualização.');
        }

        if (!$linkDTO->isValidUrl()) {
            throw new \InvalidArgumentException('URL inválida fornecida.');
        }

        return $this->linkRepository->update($id, $linkDTO->toArray(), auth()->guard('api')->id());
    }

    /**
     * Remove um link.
     */
    public function deleteLink(string $id): bool
    {
        return $this->linkRepository->delete($id, auth()->guard('api')->id());
    }

    /**
     * Processa o redirecionamento de um link encurtado.
     */
    public function processRedirect(string $slug): ?string
    {
        $link = $this->linkRepository->findBySlug($slug);

        if (!$link) {
            return null;
        }

        // Verifica se o link não expirou
        if ($link->expires_at && now()->isAfter($link->expires_at)) {
            return null;
        }

        // Verifica se já pode ser usado (starts_in)
        if ($link->starts_in && now()->isBefore($link->starts_in)) {
            return null;
        }

        // Verifica se atingiu o limite de cliques
        if ($link->hasReachedClickLimit()) {
            return null;
        }

        // Incrementa contador de cliques
        $this->linkRepository->incrementClicks($slug);

        return $link->original_url;
    }

    /**
     * Gera um slug único para o link.
     */
    public function generateUniqueSlug(int $length = 6): string
    {
        do {
            $slug = Str::random($length);
        } while ($this->linkRepository->slugExists($slug));

        return $slug;
    }
}
