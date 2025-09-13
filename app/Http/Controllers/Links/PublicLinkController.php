<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreatePublicLinkDTO;
use App\Http\Requests\CreatePublicLinkRequest;
use App\Http\Resources\PublicLinkResource;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Controller para encurtamento público de URLs
 *
 * FUNCIONALIDADE:
 * - Permite encurtamento de URLs sem autenticação
 * - Links criados não têm userId (são públicos)
 * - Validação básica de segurança
 * - Rate limiting aplicado via middleware
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas por receber requisições HTTP públicas
 * - DIP: Depende da abstração LinkServiceInterface
 */
class PublicLinkController extends Controller
{
    protected LinkServiceInterface $linkService;

    public function __construct(LinkServiceInterface $linkService)
    {
        $this->linkService = $linkService;
    }

    /**
     * Cria um novo link encurtado público.
     *
     * @param CreatePublicLinkRequest $request
     * @return JsonResponse
     */
    public function store(CreatePublicLinkRequest $request): JsonResponse
    {
        try {
            $linkDTO = CreatePublicLinkDTO::fromRequest($request);
            $link = $this->linkService->createPublicLink($linkDTO);

            return response()->json([
                'message' => 'Link criado com sucesso.',
                'data' => new PublicLinkResource($link)
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exibe informações básicas de um link público pelo slug.
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function showBySlug(string $slug): JsonResponse
    {
        try {
            $link = \App\Models\Link::where('slug', $slug)
                                  ->where('is_active', true)
                                  ->first();

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou inativo.'], 404);
            }

            // Verifica se o link não expirou
            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return response()->json(['message' => 'Link expirado.'], 404);
            }

            // Verifica se já pode ser usado (starts_in)
            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return response()->json(['message' => 'Link ainda não está disponível.'], 404);
            }

            return response()->json([
                'data' => new PublicLinkResource($link)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtém analytics básicos de um link público (sem dados sensíveis).
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function basicAnalytics(string $slug): JsonResponse
    {
        try {
            $link = \App\Models\Link::where('slug', $slug)->first();

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado.'], 404);
            }

            // Retorna apenas métricas básicas públicas
            return response()->json([
                'total_clicks' => $link->clicks,
                'created_at' => $link->created_at,
                'is_active' => $link->is_active,
                'short_url' => config('app.url') . '/r/' . $link->slug,
                'has_analytics' => $link->clicks > 0
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics básicos.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
