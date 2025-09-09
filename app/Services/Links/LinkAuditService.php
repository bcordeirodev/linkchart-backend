<?php

namespace App\Services\Links;

use App\Models\Link;
use App\Models\LinkAudit;
use Illuminate\Http\Request;

/**
 * Serviço para auditoria de links
 *
 * Responsável por registrar todas as operações realizadas nos links
 * para fins de auditoria, segurança e compliance.
 */
class LinkAuditService
{
    /**
     * Registra a criação de um link
     */
    public function logCreated(Link $link, int $userId, Request $request): void
    {
        $this->createAuditLog(
            linkId: $link->id,
            userId: $userId,
            action: LinkAudit::ACTION_CREATED,
            oldValues: null,
            newValues: $link->toArray(),
            request: $request
        );
    }

    /**
     * Registra a atualização de um link
     */
    public function logUpdated(Link $link, array $oldValues, int $userId, Request $request): void
    {
        $this->createAuditLog(
            linkId: $link->id,
            userId: $userId,
            action: LinkAudit::ACTION_UPDATED,
            oldValues: $oldValues,
            newValues: $link->toArray(),
            request: $request
        );
    }

    /**
     * Registra a exclusão de um link
     */
    public function logDeleted(Link $link, int $userId, Request $request): void
    {
        $this->createAuditLog(
            linkId: $link->id,
            userId: $userId,
            action: LinkAudit::ACTION_DELETED,
            oldValues: $link->toArray(),
            newValues: null,
            request: $request
        );
    }

    /**
     * Cria um registro de auditoria
     */
    private function createAuditLog(
        int $linkId,
        int $userId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        Request $request
    ): void {
        try {
            LinkAudit::create([
                'link_id' => $linkId,
                'user_id' => $userId,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Log do erro mas não falha a operação principal
            \Log::error('Erro ao criar log de auditoria: ' . $e->getMessage(), [
                'link_id' => $linkId,
                'user_id' => $userId,
                'action' => $action,
            ]);
        }
    }

    /**
     * Obtém o histórico de auditoria de um link
     */
    public function getLinkHistory(int $linkId, int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return LinkAudit::where('link_id', $linkId)
            ->where('user_id', $userId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Obtém o histórico de auditoria de um usuário
     */
    public function getUserHistory(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return LinkAudit::where('user_id', $userId)
            ->with(['link', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
