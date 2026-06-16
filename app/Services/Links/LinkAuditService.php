<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use App\Models\Link;
use App\Models\LinkAudit;
use Illuminate\Http\Request;

/**
 * Records immutable audit trail entries for link CRUD operations.
 *
 * Every create, update, and delete performed on a link by an authenticated user
 * is persisted as a LinkAudit row (link_audits table) with old/new JSON snapshots,
 * IP address, and User-Agent. Failures are swallowed and logged so that an audit
 * error never aborts the primary operation.
 *
 * Side effects:
 *   - Writes to link_audits table via LinkAudit::create.
 *   - Logs failures via AppLogger::auditFailed (channel: audit).
 */
class LinkAuditService
{
    /**
     * Records an audit entry for a newly created link.
     *
     * Old values are null; new values are the full link array snapshot.
     * Action constant: LinkAudit::ACTION_CREATED.
     *
     * @param  Link  $link  The newly created link model.
     * @param  int  $userId  Authenticated user ID performing the action.
     * @param  Request  $request  Current request (IP + User-Agent).
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
     * Records an audit entry for an updated link.
     *
     * Old values are the pre-update snapshot; new values are the post-update snapshot.
     * Action constant: LinkAudit::ACTION_UPDATED.
     *
     * @param  Link  $link  The updated link model (post-save state).
     * @param  array<string, mixed>  $oldValues  The link's attribute array before the update.
     * @param  int  $userId  Authenticated user ID.
     * @param  Request  $request  Current request.
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
     * Records an audit entry for a deleted link.
     *
     * Old values are the full link array snapshot; new values are null.
     * Action constant: LinkAudit::ACTION_DELETED.
     *
     * @param  Link  $link  The link model at the moment of deletion.
     * @param  int  $userId  Authenticated user ID.
     * @param  Request  $request  Current request.
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
            AppLogger::auditFailed($e, [
                'link_id' => $linkId,
                'user_id' => $userId,
                'action' => $action,
            ]);
        }
    }
}
