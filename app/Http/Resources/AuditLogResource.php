<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'workspace_id' => $this->workspace_id,
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? ['id' => $this->workspace->id, 'name' => $this->workspace->name] : null),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'meta' => $this->meta,
            'ip_address' => $this->ip_address,
            'is_admin_action' => $this->is_admin_action,
            'created_at' => $this->created_at,
        ];
    }
}
