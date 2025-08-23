<?php

namespace App\Http\Controllers;

use App\Contracts\Services\LinkServiceInterface;
use App\Services\LinkTrackingService;
use Illuminate\Http\Request;

/**
 * Controller para redirecionamento de links encurtados
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas pelo redirecionamento de links
 * - DIP: Depende de abstrações (interfaces)
 */
class RedirectController
{
    public function __construct(
        protected LinkServiceInterface $linkService,
        protected LinkTrackingService $linkTrackingService
    ) {}

        /**
     * Processa o redirecionamento de um link encurtado.
     * Suporta modo preview (sem registrar clique) e modo redirect (com clique).
     * 
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(string $slug, Request $request)
    {
        try {
            // Busca o link antes de processar
            $link = \App\Models\Link::where('slug', $slug)
                                  ->where('is_active', true)
                                  ->first();

            if (!$link) {
                return response()->json([
                    'error' => 'Link não encontrado',
                    'message' => 'O link solicitado não foi encontrado ou está inativo.'
                ], 404);
            }

            // Verifica se o link não expirou
            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return response()->json([
                    'error' => 'Link expirado',
                    'message' => 'Este link expirou e não está mais disponível.'
                ], 404);
            }

            // Verifica se já pode ser usado (starts_in)
            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return response()->json([
                    'error' => 'Link não disponível',
                    'message' => 'Este link ainda não está disponível.'
                ], 404);
            }

            // Verifica se é modo preview (não registra clique)
            $isPreview = $request->has('preview') || $request->header('X-Preview-Mode') === 'true';

            if (!$isPreview) {
                // Registra tracking do clique com tratamento de erro
                try {
                    $this->linkTrackingService->registrarClique($link, $request);

                    // Incrementa contador de cliques apenas se tracking foi bem-sucedido
                    $link->increment('clicks');

                    // Log de sucesso
                    \Log::info('Redirect successful', [
                        'slug' => $slug,
                        'link_id' => $link->id,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'referer' => $request->headers->get('referer')
                    ]);
                } catch (\Exception $trackingError) {
                    // Log do erro de tracking, mas continua com o redirect
                    \Log::error('Tracking failed but continuing redirect', [
                        'slug' => $slug,
                        'link_id' => $link->id,
                        'error' => $trackingError->getMessage(),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent()
                    ]);

                    // Incrementa cliques mesmo se tracking falhar
                    $link->increment('clicks');
                }
            }

            // Retorna dados do link (com ou sem registro de clique)
            return response()->json([
                'success' => true,
                'redirect_url' => $link->original_url,
                'is_preview' => $isPreview,
                'data' => [
                    'id' => $link->id,
                    'user_id' => $link->user_id,
                    'slug' => $link->slug,
                    'original_url' => $link->original_url,
                    'title' => $link->title,
                    'description' => $link->description,
                    'expires_at' => $link->expires_at,
                    'starts_in' => $link->starts_in,
                    'is_active' => $link->is_active,
                    'created_at' => $link->created_at->format('d/m/Y H:i:s'),
                    'updated_at' => $link->updated_at->format('d/m/Y H:i:s'),
                    'is_expired' => $link->expires_at && now()->isAfter($link->expires_at),
                    'is_active_valid' => $link->is_active,
                    'shorted_url' => $link->shorted_url ?? "http://localhost:3000/r/{$link->slug}",
                    'clicks' => $link->clicks,
                    'utm_source' => $link->utm_source,
                    'utm_medium' => $link->utm_medium,
                    'utm_campaign' => $link->utm_campaign,
                    'utm_term' => $link->utm_term,
                    'utm_content' => $link->utm_content,
                ]
            ]);
        } catch (\Exception $e) {
            // Log do erro para debugging
            \Log::error('Erro no processamento do link', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Erro interno do servidor',
                'message' => 'Ocorreu um erro ao processar o link.'
            ], 500);
        }
    }
}
