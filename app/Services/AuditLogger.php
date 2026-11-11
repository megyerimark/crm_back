<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function __construct(protected CurrentWorkspace $current) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $auditable = null, array $meta = [], ?int $workspaceId = null, bool $isAdminAction = false): AuditLog
    {
        return AuditLog::create([
            'workspace_id' => $workspaceId ?? $this->current->id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => request()?->ip(),
            'is_admin_action' => $isAdminAction,
        ]);
    }
}
