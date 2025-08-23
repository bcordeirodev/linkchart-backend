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
     * Retorna JSON com URL original para o front-end fazer o redirect.
     */
    public function handle(string $slug, Request $request)
    {
        try {
            // Busca o link antes de processar o redirecionamento
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

            // Retorna URL original para o front-end fazer o redirect
            return response()->json([
                'success' => true,
                'redirect_url' => $link->original_url,
                'link' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'description' => $link->description,
                    'clicks' => $link->clicks,
                    'created_at' => $link->created_at->format('d/m/Y H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            // Log do erro para debugging
            \Log::error('Erro no redirecionamento', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erro interno do servidor',
                'message' => 'Ocorreu um erro ao processar o redirecionamento.'
            ], 500);
        }
    }
}
